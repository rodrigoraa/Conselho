<?php declare(strict_types=1);

namespace Tests;

use Apc\Repositories\{AuditRepository,EventRepository,SettingsRepository};
use Apc\Services\{EventService,SettingsService};

final class ApcEventServiceTest extends ApcTestCase
{
    public function testAdminCreatesUpdatesAndCancelsCalendarEventWithAudit(): void
    {
        $db=$this->apcDatabase();$events=new EventRepository($db);$service=new EventService($events,new AuditRepository($db));$input=['ano_letivo'=>2027,'data'=>'2027-03-10','titulo'=>'APC excepcional','tipo'=>'EXCEPCIONAL','origem'=>'SED','descricao'=>'Reposição','justificativa'=>'Calendário ajustado','numero_processo'=>'123','documento_referencia'=>'Ofício 1','atividade_fornecida_sed'=>1,'status'=>'ATIVO'];$id=$service->save(null,$input,1,'127.0.0.1','phpunit');$input['titulo']='APC excepcional atualizada';$service->save($id,$input,1,'127.0.0.1','phpunit');$service->cancel($id,1,'127.0.0.1','phpunit');self::assertSame('CANCELADO',$events->find($id)['status']);$service->reactivate($id,1,'127.0.0.1','phpunit');self::assertSame('ATIVO',$events->find($id)['status']);self::assertSame(['CRIAR','ALTERAR','CANCELAR','REATIVAR'],$db->query("SELECT acao FROM apc_auditoria WHERE entidade='apc_eventos' ORDER BY id")->fetchAll(\PDO::FETCH_COLUMN));
    }

    public function testApcGradeSettingsAreIndependentAndConfigurable(): void
    {
        $db=$this->apcDatabase();$settings=new SettingsRepository($db);$service=new SettingsService($settings,new AuditRepository($db),$db);$service->update(['nota_min'=>'0','nota_max'=>'2','nota_decimais'=>'2'],1,'127.0.0.1','phpunit');self::assertSame(['nota_min'=>'0','nota_max'=>'2','nota_decimais'=>'2'],$settings->all());
    }

}
