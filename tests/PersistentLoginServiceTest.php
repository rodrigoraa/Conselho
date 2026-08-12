<?php declare(strict_types=1);

namespace Tests;

use PreConselho\Services\PersistentLoginService;

final class PersistentLoginServiceTest extends ApcTestCase
{
    public function testProfessorLoginCanBeRestoredForFifteenDaysWithoutStoringRawToken():void
    {
        $db=$this->mainDatabase();$this->seedMain($db);$now=1_800_000_000;$cookies=[];
        $writer=static function(string$name,string$value,array$options)use(&$cookies):bool{$cookies[]=['name'=>$name,'value'=>$value,'options'=>$options];return true;};
        $clock=static function()use(&$now):int{return$now;};$service=new PersistentLoginService($db,15,true,'Lax',$writer,$clock);
        $expires=$service->remember(['id'=>3,'nome'=>'Professor Um','perfil'=>'PROFESSOR']);self::assertSame($now+15*86400,$expires);self::assertSame(PersistentLoginService::COOKIE_NAME,$cookies[0]['name']);self::assertSame($expires,$cookies[0]['options']['expires']);self::assertTrue($cookies[0]['options']['secure']);self::assertTrue($cookies[0]['options']['httponly']);
        $cookie=$cookies[0]['value'];[, $validator]=explode('.',$cookie,2);$stored=$db->query('SELECT token_hash FROM sessoes_persistentes_professor')->fetchColumn();self::assertSame(hash('sha256',$validator),$stored);self::assertStringNotContainsString($validator,(string)$stored);
        $restored=$service->restore($cookie);self::assertSame(3,$restored['id']);self::assertSame('Professor Um',$restored['nome']);self::assertSame('PROFESSOR',$restored['perfil']);self::assertSame($expires,$restored['persistent_expires_at']);self::assertNotNull($db->query('SELECT ultimo_uso_em FROM sessoes_persistentes_professor')->fetchColumn());
        $now=$expires;self::assertNull($service->restore($cookie));self::assertSame(0,(int)$db->query('SELECT COUNT(*) FROM sessoes_persistentes_professor')->fetchColumn());self::assertSame('',$cookies[array_key_last($cookies)]['value']);
    }

    public function testOnlyProfessorReceivesPersistentLoginAndLogoutRevokesCurrentDevice():void
    {
        $db=$this->mainDatabase();$this->seedMain($db);$now=1_800_000_000;$cookies=[];$writer=static function(string$name,string$value,array$options)use(&$cookies):bool{$cookies[]=$value;return true;};$service=new PersistentLoginService($db,15,false,'Lax',$writer,static fn():int=>$now);
        self::assertNull($service->remember(['id'=>1,'nome'=>'Admin','perfil'=>'ADMIN']));self::assertSame([],$cookies);$service->remember(['id'=>3,'nome'=>'Professor Um','perfil'=>'PROFESSOR']);$cookie=$cookies[0];self::assertSame(1,(int)$db->query('SELECT COUNT(*) FROM sessoes_persistentes_professor')->fetchColumn());$service->forget($cookie);self::assertSame(0,(int)$db->query('SELECT COUNT(*) FROM sessoes_persistentes_professor')->fetchColumn());self::assertSame('',$cookies[1]);
    }
}
