<?php declare(strict_types=1);

namespace Tests;

use Apc\Repositories\{AccessRepository,AuditRepository,EventRepository,SubmissionRepository,TermRepository};
use Apc\Services\SubmissionService;
use Shared\Exceptions\HttpException;

final class ApcSubmissionServiceTest extends ApcTestCase
{
    private string$directory;

    protected function setUp():void{$this->directory=sys_get_temp_dir().DIRECTORY_SEPARATOR.'apc-envios-'.bin2hex(random_bytes(6));mkdir($this->directory,0770,true);}
    protected function tearDown():void{$this->remove($this->directory);}

    public function testProfessorUploadsUsingCouncilBindingAndCannotSendTheSameApcAgain():void
    {
        [$db,$repository,$service]=$this->service('2026-08-11');$teacher=['id'=>3,'nome'=>'Professor Um','perfil'=>'PROFESSOR'];$id=$service->submit($this->input(),$this->file('modelo.png'),$teacher,'127.0.0.1','phpunit');$first=$repository->find($id);self::assertSame(0,(int)$first['atrasado']);self::assertSame('7º A',$first['turmas']);self::assertFileExists($firstPath=$service->file($id,$teacher)['caminho_absoluto']);$lateService=$this->serviceFor($db,$repository,'2026-08-20');try{$lateService->submit($this->input(),$this->file('modelo-corrigido.png'),$teacher,'127.0.0.1','phpunit');self::fail('O mesmo evento e turma não deveriam aceitar reenvio.');}catch(HttpException$exception){self::assertSame(409,$exception->status);self::assertSame('APC_SUBMISSION_ALREADY_EXISTS',$exception->errorCode);}self::assertSame('modelo.png',$repository->find($id)['nome_original']);self::assertFileExists($firstPath);self::assertSame(1,(int)$db->query('SELECT COUNT(*) FROM apc_envios')->fetchColumn());self::assertSame(['ANEXAR_ARQUIVO_APC'],$db->query("SELECT acao FROM apc_auditoria WHERE entidade='apc_envios' ORDER BY id")->fetchAll(\PDO::FETCH_COLUMN));
    }

    public function testWholeBimesterIsAllowedButAfterItsEndIsBlocked():void
    {
        [$db,$repository]=$this->service('2026-08-11');$service=$this->serviceFor($db,$repository,'2026-09-30');$id=$service->submit($this->input(),$this->file('ultimo-dia.png'),['id'=>3,'nome'=>'Professor Um','perfil'=>'PROFESSOR'],'127.0.0.1','phpunit');self::assertSame(46,(int)$repository->find($id)['dias_atraso']);$closed=$this->serviceFor($db,$repository,'2026-10-01');try{$closed->submit($this->input(),$this->file('fora.png'),['id'=>3,'nome'=>'Professor Um','perfil'=>'PROFESSOR'],'127.0.0.1','phpunit');self::fail('Envio após o bimestre deveria ser bloqueado.');}catch(HttpException$exception){self::assertSame('APC_TERM_CLOSED',$exception->errorCode);}
    }

    public function testSeriesAndDownloadsFollowCouncilAuthorization():void
    {
        [$db,$repository,$service]=$this->service('2026-08-11');try{$service->submit($this->input('EF_AF','EF8'),$this->file('sem-vinculo.png'),['id'=>3,'nome'=>'Professor Um','perfil'=>'PROFESSOR'],'127.0.0.1','phpunit');self::fail('Turma fora da série vinculada deveria ser recusada.');}catch(HttpException$exception){self::assertSame('APC_CLASS_FORBIDDEN',$exception->errorCode);}$id=$service->submit($this->input(),$this->file('permitido.png'),['id'=>3,'nome'=>'Professor Um','perfil'=>'PROFESSOR'],'127.0.0.1','phpunit');try{$service->file($id,['id'=>4,'perfil'=>'PROFESSOR']);self::fail('Outro professor não deveria baixar o arquivo.');}catch(HttpException$exception){self::assertSame(403,$exception->status);}self::assertSame($id,(int)$service->file($id,['id'=>2,'perfil'=>'COORDENADOR'])['id']);
    }

    private function service(string$today):array
    {
        $main=$this->mainDatabase();$this->seedMain($main);$db=$this->apcDatabase();$this->seedEvent($db);$repository=new SubmissionRepository($db);return[$db,$repository,new SubmissionService($repository,new EventRepository($db),new TermRepository($db),new AccessRepository($main),new AuditRepository($db),$this->directory,1048576,static fn(string$path):bool=>is_file($path),static fn(string$from,string$to):bool=>rename($from,$to),$today),$main];
    }
    private function serviceFor(\PDO$db,SubmissionRepository$repository,string$today):SubmissionService
    {
        $main=$this->mainDatabase();$this->seedMain($main);return new SubmissionService($repository,new EventRepository($db),new TermRepository($db),new AccessRepository($main),new AuditRepository($db),$this->directory,1048576,static fn(string$path):bool=>is_file($path),static fn(string$from,string$to):bool=>rename($from,$to),$today);
    }
    private function input(string$stage='EF_AF',string$year='EF7',int$classId=10):array{return['evento_id'=>1,'etapa'=>$stage,'ano_serie'=>$year,'turma_id'=>$classId];}
    private function file(string$name):array{$path=$this->directory.DIRECTORY_SEPARATOR.bin2hex(random_bytes(5)).'.png';file_put_contents($path,(string)base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mNk+A8AAQUBAScY42YAAAAASUVORK5CYII='));return['name'=>$name,'tmp_name'=>$path,'error'=>UPLOAD_ERR_OK,'size'=>filesize($path)];}
    private function remove(string$path):void{if(!is_dir($path))return;foreach(scandir($path)?:[]as$item){if($item==='.'||$item==='..')continue;$target=$path.DIRECTORY_SEPARATOR.$item;if(is_dir($target))$this->remove($target);else unlink($target);}rmdir($path);}
}
