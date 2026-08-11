<?php declare(strict_types=1);

namespace Tests;

use Apc\Repositories\{AccessRepository,AuditRepository,DeliveryRepository,PlanRepository,SettingsRepository};
use Apc\Services\{AuthorizationService,DeliveryService,EventWindow};
use PreConselho\Integration\SecretariaApiClient;
use Shared\Exceptions\HttpException;

final class ApcDeliveryServiceTest extends ApcTestCase
{
    private function service(?SecretariaApiClient $api=null,string $today='2026-08-11'): array
    {
        $main=$this->mainDatabase();$this->seedMain($main);$apc=$this->apcDatabase();$this->seedEvent($apc);$apc->exec("INSERT INTO apc_planos(id,evento_id,professor_usuario_id,professor_nome_snapshot,turma_id_externo,turma_nome_snapshot,componente_curricular,competencias_habilidades,conteudos,descricao_atividade,estrategia_devolucao)VALUES(1,1,3,'Professor Um',10,'7º A','Matemática','Competências','Conteúdos','Atividade','Devolução')");$plans=new PlanRepository($apc);$deliveries=new DeliveryRepository($apc);$authorization=new AuthorizationService($plans,new AccessRepository($main));
        $api??=new class extends SecretariaApiClient{public function alunosDaTurma(int$id):array{return[['id'=>100,'nome_completo'=>'Ana','id_turma'=>10],['id'=>101,'nome_completo'=>'Bruno','id_turma'=>10]];}public function aluno(int$id):array{return['id'=>$id,'nome_completo'=>$id===100?'Ana':($id===101?'Bruno':'Outro'),'id_turma'=>$id===999?20:10];}};
        return[$apc,$deliveries,new DeliveryService($plans,$deliveries,new SettingsRepository($apc),new AuditRepository($apc),$authorization,$api,new EventWindow($today))];
    }

    public function testListsSecretariaStudentsAndRegistersDeliveredAndNotDeliveredWithoutDuplicates(): void
    {
        [$db,,$service]=$this->service();$teacher=['id'=>3,'nome'=>'Professor Um','perfil'=>'PROFESSOR'];$list=$service->students(1,$teacher);self::assertCount(2,$list['students']);self::assertSame(2,(int)$list['plan']['total_alunos_snapshot']);
        $service->save(1,100,['entregue'=>'1','data_entrega'=>'2026-08-15','nota'=>'1,0','observacao'=>'Realizada'],$teacher,'127.0.0.1','phpunit');$service->save(1,100,['entregue'=>'1','data_entrega'=>'2026-08-16','nota'=>'0,8','observacao'=>'Corrigida'],$teacher,'127.0.0.1','phpunit');$service->save(1,101,['observacao'=>'Não entregou'],$teacher,'127.0.0.1','phpunit');
        self::assertSame(2,(int)$db->query('SELECT COUNT(*) FROM apc_entregas')->fetchColumn());self::assertSame(0.8,(float)$db->query('SELECT nota FROM apc_entregas WHERE aluno_id_externo=100')->fetchColumn());self::assertNull($db->query('SELECT data_entrega FROM apc_entregas WHERE aluno_id_externo=101')->fetchColumn());
    }

    public function testRejectsStudentFromAnotherClassAndGradeOutsideConfiguredScale(): void
    {
        [,,$service]=$this->service();$teacher=['id'=>3,'nome'=>'Professor Um','perfil'=>'PROFESSOR'];
        try{$service->save(1,999,['entregue'=>'1','data_entrega'=>'2026-08-15','nota'=>'1'],$teacher,'127.0.0.1','phpunit');self::fail('Aluno de outra turma deveria ser recusado.');}catch(HttpException $exception){self::assertSame('APC_STUDENT_CLASS_MISMATCH',$exception->errorCode);}
        $this->expectException(HttpException::class);$service->save(1,100,['entregue'=>'1','data_entrega'=>'2026-08-15','nota'=>'10.55'],$teacher,'127.0.0.1','phpunit');
    }

    public function testSecretariaOutageKeepsExistingSnapshotsAvailable(): void
    {
        $api=new class extends SecretariaApiClient{public function alunosDaTurma(int$id):array{throw new \RuntimeException('offline');}public function aluno(int$id):array{throw new \RuntimeException('offline');}};[$db,,$service]=$this->service($api);$db->exec("INSERT INTO apc_entregas(plano_id,aluno_id_externo,aluno_nome_snapshot,entregue)VALUES(1,100,'Ana snapshot',1)");$list=$service->students(1,['id'=>3,'nome'=>'Professor Um','perfil'=>'PROFESSOR']);self::assertNotNull($list['error']);self::assertSame('Ana snapshot',$list['students'][0]['nome']);
    }

    public function testClosedWindowKeepsDeliveriesReadOnly():void
    {
        [,,$service]=$this->service(null,'2026-08-23');try{$service->save(1,100,['entregue'=>'1','data_entrega'=>'2026-08-15','nota'=>'1'],$this->teacher(),'127.0.0.1','phpunit');self::fail('Entrega fora do prazo deveria ser recusada.');}catch(HttpException$exception){self::assertSame('APC_EVENT_CLOSED',$exception->errorCode);}
    }

    private function teacher():array{return['id'=>3,'nome'=>'Professor Um','perfil'=>'PROFESSOR'];}
}
