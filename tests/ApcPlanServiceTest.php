<?php declare(strict_types=1);

namespace Tests;

use Apc\Repositories\{AccessRepository,AuditRepository,EventRepository,PlanRepository};
use Apc\Services\{AuthorizationService,PlanService};
use PreConselho\Integration\SecretariaApiClient;
use Shared\Exceptions\HttpException;

final class ApcPlanServiceTest extends ApcTestCase
{
    private function services(?SecretariaApiClient $api=null): array
    {
        $main=$this->mainDatabase();$this->seedMain($main);$apc=$this->apcDatabase();$this->seedEvent($apc);$plans=new PlanRepository($apc);$access=new AccessRepository($main);$audit=new AuditRepository($apc);$authorization=new AuthorizationService($plans,$access);return[$apc,$plans,$authorization,new PlanService($plans,new EventRepository($apc),$access,$audit,$authorization,$api)];
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
}
