<?php declare(strict_types=1);

namespace Tests;

use Apc\Repositories\{AccessRepository,AuditRepository,EventRepository,PlanRepository};
use Apc\Services\{AuthorizationService,EventWindow,PlanService};
use Apc\Controllers\PlanController;
use PreConselho\Integration\SecretariaApiClient;
use PreConselho\Support\Csrf;
use Shared\Exceptions\HttpException;
use Shared\Http\Request;
use Shared\Support\View;

final class ApcPlanServiceTest extends ApcTestCase
{
    private function services(?SecretariaApiClient $api=null,string $today='2026-08-11'): array
    {
        $main=$this->mainDatabase();$this->seedMain($main);$apc=$this->apcDatabase();$this->seedEvent($apc);$plans=new PlanRepository($apc);$access=new AccessRepository($main);$audit=new AuditRepository($apc);$authorization=new AuthorizationService($plans,$access);return[$apc,$plans,$authorization,new PlanService($plans,new EventRepository($apc),$access,$audit,$authorization,$api,null,new EventWindow($today))];
    }

    private function input(int $classId=10): array
    {
        return['evento_id'=>1,'turma_id_externo'=>$classId,'componente_curricular'=>'Matemática','competencias_habilidades'=>'Resolver problemas','conteudos'=>'Frações','descricao_atividade'=>'Atividade impressa aplicada em sala','estrategia_devolucao'=>'Correção coletiva e devolutiva individual'];
    }

    public function testProfessorCreatesUpdatesAndFinalizesPlanOnlyForLinkedClass(): void
    {
        [$db,$plans,,$service]=$this->services();$teacher=['id'=>3,'nome'=>'Professor Um','perfil'=>'PROFESSOR'];$id=$service->create($this->input(),$teacher,'127.0.0.1','phpunit');self::assertSame('RASCUNHO',$plans->find($id)['status']);
        $updated=$this->input();$updated['conteudos']='Números racionais';$service->update($id,$updated,$teacher,'127.0.0.1','phpunit');self::assertSame('Números racionais',$plans->find($id)['conteudos']);
        $service->finalize($id,$teacher,'127.0.0.1','phpunit');self::assertSame('FINALIZADO',$plans->find($id)['status']);self::assertSame(3,(int)$db->query("SELECT COUNT(*) FROM apc_auditoria WHERE entidade='apc_planos'")->fetchColumn());
    }

    public function testFinalizationSavesTheSubmittedVersionAndAuditsBothChanges(): void
    {
        [$db,$plans,,$service]=$this->services();$teacher=['id'=>3,'nome'=>'Professor Um','perfil'=>'PROFESSOR'];
        $id=$service->create($this->input(),$teacher,'127.0.0.1','phpunit');$submitted=$this->input();$submitted['conteudos']='Texto que ainda estava na tela';
        $service->finalize($id,$teacher,'127.0.0.1','phpunit',$submitted);
        self::assertSame('Texto que ainda estava na tela',$plans->find($id)['conteudos']);self::assertSame('FINALIZADO',$plans->find($id)['status']);
        self::assertSame(['CRIAR','ALTERAR','FINALIZAR'],$db->query("SELECT acao FROM apc_auditoria WHERE entidade='apc_planos' ORDER BY id")->fetchAll(\PDO::FETCH_COLUMN));
    }

    public function testIncompleteSubmittedVersionLeavesStoredDraftAndAuditUnchanged(): void
    {
        [$db,$plans,,$service]=$this->services();$teacher=['id'=>3,'nome'=>'Professor Um','perfil'=>'PROFESSOR'];
        $id=$service->create($this->input(),$teacher,'127.0.0.1','phpunit');$before=$plans->find($id);$submitted=$this->input();$submitted['conteudos']='';$submitted['descricao_atividade']='Edição que não pode ser salva parcialmente';
        try{$service->finalize($id,$teacher,'127.0.0.1','phpunit',$submitted);self::fail('A versão incompleta deve ser recusada.');}catch(HttpException $exception){self::assertSame('APC_PLAN_INCOMPLETE',$exception->errorCode);}
        self::assertSame($before,$plans->find($id));self::assertSame(1,(int)$db->query('SELECT COUNT(*) FROM apc_auditoria')->fetchColumn());self::assertFalse($db->inTransaction());
    }

    public function testSubmittedFinalizationStillRequiresOwnershipAndAnOpenWindow(): void
    {
        [$db,$plans,,$service]=$this->services();$teacher=['id'=>3,'nome'=>'Professor Um','perfil'=>'PROFESSOR'];$id=$service->create($this->input(),$teacher,'127.0.0.1','phpunit');$before=$plans->find($id);
        try{$service->finalize($id,['id'=>4,'nome'=>'Outro professor','perfil'=>'PROFESSOR'],'127.0.0.1','phpunit',$this->input());self::fail('Outro professor não pode finalizar.');}catch(HttpException $exception){self::assertSame(403,$exception->status);}
        $db->exec("UPDATE apc_eventos SET data='2026-07-01' WHERE id=1");
        try{$service->finalize($id,$teacher,'127.0.0.1','phpunit',$this->input());self::fail('O prazo precisa ser respeitado.');}catch(HttpException $exception){self::assertSame('APC_EVENT_CLOSED',$exception->errorCode);}
        self::assertSame($before['conteudos'],$plans->find($id)['conteudos']);self::assertSame('RASCUNHO',$plans->find($id)['status']);self::assertSame(1,(int)$db->query('SELECT COUNT(*) FROM apc_auditoria')->fetchColumn());
    }

    public function testFinalizeEndpointUsesSubmittedFieldsAndKeepsCsrfOnlyClientsCompatible(): void
    {
        [$db,$plans,$authorization,$service]=$this->services();$previous=$_SESSION??[];$_SESSION['user']=$teacher=['id'=>3,'nome'=>'Professor Um','perfil'=>'PROFESSOR'];
        try{
            $controller=new PlanController($service,$authorization,new EventRepository($db),new AccessRepository($this->mainDatabase()),new View(dirname(__DIR__).'/apps/apc/resources/views'));
            $id=$service->create($this->input(),$teacher,'127.0.0.1','phpunit');$submitted=$this->input();$submitted['conteudos']='Conteúdo enviado pelo formulário';
            $response=$controller->finalize(new Request('POST',"/apc/planos/$id/finalizar",[],['_csrf'=>Csrf::token(),'_save_before_finalize'=>'1']+$submitted,[]),['id'=>$id]);
            self::assertSame('/apc/planos/'.$id,$response->headers['Location']);self::assertSame('Conteúdo enviado pelo formulário',$plans->find($id)['conteudos']);self::assertSame('FINALIZADO',$plans->find($id)['status']);
            $legacy=$this->input();$legacy['componente_curricular']='Ciências';$legacyId=$service->create($legacy,$teacher,'127.0.0.1','phpunit');
            $controller->finalize(new Request('POST',"/apc/planos/$legacyId/finalizar",[],['_csrf'=>Csrf::token()],[]),['id'=>$legacyId]);
            self::assertSame('Frações',$plans->find($legacyId)['conteudos']);self::assertSame('FINALIZADO',$plans->find($legacyId)['status']);
        }finally{$_SESSION=$previous;}
    }

    public function testFinalizationFailureRollsBackTheSubmittedDraftAndItsAudit(): void
    {
        [$db,$plans,,$service]=$this->services();$teacher=['id'=>3,'nome'=>'Professor Um','perfil'=>'PROFESSOR'];$id=$service->create($this->input(),$teacher,'127.0.0.1','phpunit');$before=$plans->find($id);
        $db->exec("CREATE TRIGGER fail_finalization BEFORE UPDATE OF status ON apc_planos WHEN NEW.status='FINALIZADO' BEGIN SELECT RAISE(ABORT,'simulated finalization failure'); END");
        $submitted=$this->input();$submitted['conteudos']='Não pode persistir sem finalizar';
        try{$service->finalize($id,$teacher,'127.0.0.1','phpunit',$submitted);self::fail('Falha na finalização deve reverter a transação.');}catch(\PDOException $exception){self::assertStringContainsString('simulated finalization failure',$exception->getMessage());}
        self::assertSame($before,$plans->find($id));self::assertSame(1,(int)$db->query('SELECT COUNT(*) FROM apc_auditoria')->fetchColumn());self::assertFalse($db->inTransaction());
    }

    public function testProfessorCannotCreateForUnlinkedClassOrAccessAnotherProfessorPlan(): void
    {
        [,,$authorization,$service]=$this->services();$teacher=['id'=>3,'nome'=>'Professor Um','perfil'=>'PROFESSOR'];
        try{$service->create($this->input(20),$teacher,'127.0.0.1','phpunit');self::fail('Turma sem vínculo deveria ser recusada.');}catch(HttpException $exception){self::assertSame(403,$exception->status);}
        $id=$service->create($this->input(),$teacher,'127.0.0.1','phpunit');$this->expectException(HttpException::class);$authorization->plan($id,4,'PROFESSOR');
    }

    public function testCoordinatorHasGlobalReadAndCanReopenWithAuditedReason(): void
    {
        [$db,$plans,$authorization,$service]=$this->services();$teacher=['id'=>3,'nome'=>'Professor Um','perfil'=>'PROFESSOR'];$id=$service->create($this->input(),$teacher,'127.0.0.1','phpunit');$service->finalize($id,$teacher,'127.0.0.1','phpunit');
        self::assertSame($id,(int)$authorization->plan($id,2,'COORDENADOR')['id']);$service->reopen($id,'Professor precisa corrigir o conteúdo.',['id'=>2,'nome'=>'Coordenação','perfil'=>'COORDENADOR'],'127.0.0.1','phpunit');self::assertSame('RASCUNHO',$plans->find($id)['status']);self::assertSame(1,(int)$db->query("SELECT COUNT(*) FROM apc_auditoria WHERE acao='REABRIR'")->fetchColumn());
    }

    public function testFinalizedPlanRejectsSilentChanges(): void
    {
        [,,$authorization,$service]=$this->services();$teacher=['id'=>3,'nome'=>'Professor Um','perfil'=>'PROFESSOR'];$id=$service->create($this->input(),$teacher,'127.0.0.1','phpunit');$service->finalize($id,$teacher,'127.0.0.1','phpunit');$this->expectException(HttpException::class);$service->update($id,$this->input(),$teacher,'127.0.0.1','phpunit');
    }

    public function testCreationSnapshotsStudentTotalWithoutCopyingStudentRegistry(): void
    {
        $api=new class extends SecretariaApiClient{public function alunosDaTurma(int$id):array{return[['id'=>1],['id'=>2],['id'=>3]];}};[$db,$plans,,$service]=$this->services($api);$id=$service->create($this->input(),['id'=>3,'nome'=>'Professor Um','perfil'=>'PROFESSOR'],'127.0.0.1','phpunit');self::assertSame(3,(int)$plans->find($id)['total_alunos_snapshot']);self::assertSame(0,(int)$db->query('SELECT COUNT(*) FROM apc_entregas')->fetchColumn());
    }

    public function testCreationIsRejectedBeforeOpeningAndAfterClosing(): void
    {
        $teacher=['id'=>3,'nome'=>'Professor Um','perfil'=>'PROFESSOR'];[, , ,$before]=$this->services(null,'2026-08-07');try{$before->create($this->input(),$teacher,'127.0.0.1','phpunit');self::fail('APC ainda não aberta deveria bloquear criação.');}catch(HttpException$exception){self::assertSame('APC_EVENT_NOT_OPEN',$exception->errorCode);}
        [, , ,$after]=$this->services(null,'2026-08-23');try{$after->create($this->input(),$teacher,'127.0.0.1','phpunit');self::fail('APC encerrada deveria bloquear criação.');}catch(HttpException$exception){self::assertSame('APC_EVENT_CLOSED',$exception->errorCode);}
    }

    public function testExistingDraftCannotBeChangedWhenEventWindowCloses():void
    {
        [$db,,$authorization,$service]=$this->services();$teacher=['id'=>3,'nome'=>'Professor Um','perfil'=>'PROFESSOR'];$id=$service->create($this->input(),$teacher,'127.0.0.1','phpunit');$db->exec("UPDATE apc_eventos SET data='2026-07-01' WHERE id=1");try{$service->update($id,$this->input(),$teacher,'127.0.0.1','phpunit');self::fail('Rascunho deveria ficar somente leitura após o prazo.');}catch(HttpException$exception){self::assertSame('APC_EVENT_CLOSED',$exception->errorCode);}self::assertSame('RASCUNHO',$authorization->plan($id,3,'PROFESSOR')['status']);
    }
}
