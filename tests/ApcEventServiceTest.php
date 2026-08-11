<?php declare(strict_types=1);

namespace Tests;

use Apc\Repositories\{AuditRepository,EventRepository,SettingsRepository};
use Apc\Services\{EventService,EventWindow,SettingsService};
use Shared\Exceptions\HttpException;

final class ApcEventServiceTest extends ApcTestCase
{
    public function testAdminCreatesUpdatesAndCancelsCalendarEventWithAudit(): void
    {
        $db=$this->apcDatabase();$events=new EventRepository($db);$service=new EventService($events,new AuditRepository($db));$input=['ano_letivo'=>2027,'data'=>'2027-03-10','titulo'=>'APC excepcional','tipo'=>'EXCEPCIONAL','origem'=>'SED','descricao'=>'Reposição','justificativa'=>'Calendário ajustado','numero_processo'=>'123','documento_referencia'=>'Ofício 1','atividade_fornecida_sed'=>1,'status'=>'ATIVO'];$id=$service->save(null,$input,1,'127.0.0.1','phpunit');$input['titulo']='APC excepcional atualizada';$service->save($id,$input,1,'127.0.0.1','phpunit');$service->cancel($id,1,'127.0.0.1','phpunit');self::assertSame('CANCELADO',$events->find($id)['status']);self::assertSame(3,(int)$db->query("SELECT COUNT(*) FROM apc_auditoria WHERE entidade='apc_eventos'")->fetchColumn());
    }

    public function testApcGradeSettingsAreIndependentAndConfigurable(): void
    {
        $db=$this->apcDatabase();$settings=new SettingsRepository($db);$service=new SettingsService($settings,new AuditRepository($db),$db);$service->update(['nota_min'=>'0','nota_max'=>'2','nota_decimais'=>'2'],1,'127.0.0.1','phpunit');self::assertSame(['nota_min'=>'0','nota_max'=>'2','nota_decimais'=>'2'],$settings->all());
    }

    public function testCoordinationReleasesAndSuspendsApcForTeachersWithAudit():void
    {
        $db=$this->apcDatabase();$this->seedEvent($db);$db->exec('UPDATE apc_eventos SET disponibilizado_em=NULL,disponibilizado_por=NULL');$events=new EventRepository($db);$service=new EventService($events,new AuditRepository($db),new EventWindow('2026-08-11'));$coord=['id'=>2,'nome'=>'Coordenação','perfil'=>'COORDENADOR'];$service->setAvailability(1,true,$coord,'127.0.0.1','phpunit');$released=$events->find(1);self::assertNotNull($released['disponibilizado_em']);self::assertSame(2,(int)$released['disponibilizado_por']);self::assertTrue((new EventWindow('2026-08-11'))->describe($released)['is_open']);$service->setAvailability(1,false,$coord,'127.0.0.1','phpunit');self::assertNull($events->find(1)['disponibilizado_em']);self::assertSame(['DISPONIBILIZAR','SUSPENDER_DISPONIBILIZACAO'],$db->query("SELECT acao FROM apc_auditoria WHERE entidade='apc_eventos' ORDER BY id")->fetchAll(\PDO::FETCH_COLUMN));
    }

    public function testTeacherCannotReleaseAndCoordinationCannotBypassDateWindow():void
    {
        $db=$this->apcDatabase();$this->seedEvent($db);$db->exec('UPDATE apc_eventos SET disponibilizado_em=NULL,disponibilizado_por=NULL');$events=new EventRepository($db);$service=new EventService($events,new AuditRepository($db),new EventWindow('2026-08-01'));
        try{$service->setAvailability(1,true,['id'=>3,'perfil'=>'PROFESSOR'],'127.0.0.1','phpunit');self::fail('Professor não deveria liberar APC.');}catch(HttpException$exception){self::assertSame(403,$exception->status);}
        try{$service->setAvailability(1,true,['id'=>2,'perfil'=>'COORDENADOR'],'127.0.0.1','phpunit');self::fail('Coordenação não deveria antecipar a janela oficial.');}catch(HttpException$exception){self::assertSame('APC_EVENT_NOT_OPEN',$exception->errorCode);}
    }
}
