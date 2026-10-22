<?php declare(strict_types=1);

namespace Tests;

use Apc\Controllers\PlanController;
use Apc\Repositories\{AccessRepository,AuditRepository,EventRepository,PlanRepository,ScheduleRepository};
use Apc\Services\{AuthorizationService,EventWindow,PlanService,ScheduleService,XlsxScheduleParser};
use PDO;
use Shared\Exceptions\HttpException;
use Shared\Http\Request;
use Shared\Support\View;

final class ApcScopedPlanCreationTest extends ApcTestCase
{
    protected function tearDown():void{unset($_SESSION['user']);$_SERVER['REQUEST_URI']='/';}

    public function testNewPlanListsOnlyMatchingScopedAndGlobalEventsForTeacher():void
    {
        [$main,$apc,$schedules]=$this->context();$_SESSION['user']=$this->teacher();
        $html=$this->controller($main,$apc,$schedules)->createForm($this->request())->body;
        self::assertStringContainsString('APC matutina permitida',$html);self::assertStringContainsString('APC global preservada',$html);
        foreach(['APC vespertina restrita','APC sem aula','APC legada restrita']as$title)self::assertStringNotContainsString($title,$html);
    }

    public function testNewPlanHidesScopedEventsWithoutScheduleService():void
    {
        [$main,$apc]=$this->context();$_SESSION['user']=$this->teacher();$html=$this->controller($main,$apc)->createForm($this->request())->body;
        self::assertStringContainsString('APC global preservada',$html);self::assertStringNotContainsString('APC matutina permitida',$html);
    }

    public function testNewPlanRejectsDirectScopedSelectionWithoutTeacherObligation():void
    {
        [$main,$apc,$schedules]=$this->context();$_SESSION['user']=$this->teacher();$controller=$this->controller($main,$apc,$schedules);
        foreach([2,4,5]as$eventId)$this->assertScheduleForbidden(fn()=>$controller->createForm($this->request($eventId)));
    }

    public function testNewPlanRejectsDirectScopedSelectionWithoutScheduleService():void
    {
        [$main,$apc]=$this->context();$_SESSION['user']=$this->teacher();$this->assertScheduleForbidden(fn()=>$this->controller($main,$apc)->createForm($this->request(1)));
    }

    public function testManagementKeepsEveryEventInNewPlanForm():void
    {
        [$main,$apc,$schedules]=$this->context();$controller=$this->controller($main,$apc,$schedules);
        foreach(['ADMIN','COORDENADOR']as$role){$_SESSION['user']=['id'=>1,'nome'=>'Gestão','perfil'=>$role];$html=$controller->createForm($this->request(2))->body;foreach(['APC matutina permitida','APC vespertina restrita','APC global preservada','APC sem aula','APC legada restrita']as$title)self::assertStringContainsString($title,$html);}
    }

    public function testScopedPlanCreationRequiresSelectedClassInTeacherSnapshot():void
    {
        [$main,$apc,$schedules]=$this->context();$service=$this->service($main,$apc,$schedules);
        foreach([[1,30],[2,10],[4,10],[5,10]]as[$eventId,$classId])$this->assertScheduleForbidden(fn()=>$service->create($this->input($eventId,$classId),$this->teacher(),'127.0.0.1','phpunit'));
        self::assertSame(0,(int)$apc->query('SELECT COUNT(*) FROM apc_planos')->fetchColumn());self::assertSame(0,(int)$apc->query('SELECT COUNT(*) FROM apc_auditoria')->fetchColumn());
    }

    public function testScopedPlanCreationFailsClosedWithoutScheduleService():void
    {
        [$main,$apc]=$this->context();$this->assertScheduleForbidden(fn()=>$this->service($main,$apc)->create($this->input(1,10),$this->teacher(),'127.0.0.1','phpunit'));
        self::assertSame(0,(int)$apc->query('SELECT COUNT(*) FROM apc_planos')->fetchColumn());
    }

    public function testScopedPlanCreationFailsClosedWhenTimetableIsMissing():void
    {
        [$main,$apc,$schedules]=$this->context();$apc->exec('DELETE FROM apc_evento_obrigacoes;DELETE FROM apc_evento_obrigacao_estados;DELETE FROM apc_horarios;DELETE FROM apc_horario_importacoes');
        $this->assertScheduleForbidden(fn()=>$this->service($main,$apc,$schedules)->create($this->input(1,10),$this->teacher(),'127.0.0.1','phpunit'));
        self::assertSame(0,(int)$apc->query('SELECT COUNT(*) FROM apc_planos')->fetchColumn());
    }

    public function testScopedPlanCreationAcceptsMatchingTeacherAndClass():void
    {
        [$main,$apc,$schedules]=$this->context();$id=$this->service($main,$apc,$schedules)->create($this->input(1,10),$this->teacher(),'127.0.0.1','phpunit');$plan=(new PlanRepository($apc))->find($id);
        self::assertSame(1,(int)$plan['evento_id']);self::assertSame(3,(int)$plan['professor_usuario_id']);self::assertSame(10,(int)$plan['turma_id_externo']);
    }

    public function testGlobalPlanCreationKeepsLinkedClassBehaviorWithoutScheduleService():void
    {
        [$main,$apc]=$this->context();$id=$this->service($main,$apc)->create($this->input(3,30),$this->teacher(),'127.0.0.1','phpunit');
        self::assertSame(30,(int)(new PlanRepository($apc))->find($id)['turma_id_externo']);
    }

    public function testHistoricalPlanOutsideScopedSnapshotRemainsEditable():void
    {
        [$main,$apc,$schedules]=$this->context();$service=$this->service($main,$apc,$schedules);$id=$service->create($this->input(3,30),$this->teacher(),'127.0.0.1','phpunit');$apc->exec('UPDATE apc_planos SET evento_id=1 WHERE id='.$id);
        $input=$this->input(1,30);$input['conteudos']='Conteúdo histórico atualizado';$service->update($id,$input,$this->teacher(),'127.0.0.1','phpunit');$plan=(new PlanRepository($apc))->find($id);
        self::assertSame('Conteúdo histórico atualizado',$plan['conteudos']);self::assertSame(30,(int)$plan['turma_id_externo']);
    }

    private function context():array
    {
        $main=$this->mainDatabase();$this->seedMain($main);$main->exec("UPDATE vinculos_professor_turma SET turno='VESPERTINO' WHERE professor_id=2;INSERT INTO vinculos_professor_turma(id,professor_id,turma_externa_id,turma_nome_snapshot,turma_ano_letivo_snapshot,turno)VALUES(3,1,30,'8º B',2026,'MATUTINO')");$apc=$this->apcDatabase();
        $insert=$apc->prepare("INSERT INTO apc_eventos(id,ano_letivo,data,titulo,tipo,origem,descricao,status,criado_por,turno)VALUES(:id,2026,'2026-10-15',:titulo,'OUTRO','ESCOLA','','ATIVO',1,:turno)");
        foreach([[1,'APC matutina permitida','MATUTINO'],[2,'APC vespertina restrita','VESPERTINO'],[3,'APC global preservada',null],[4,'APC sem aula','MATUTINO'],[5,'APC legada restrita','MATUTINO']]as[$id,$title,$shift])$insert->execute([':id'=>$id,':titulo'=>$title,':turno'=>$shift]);
        $repository=new ScheduleRepository($apc);
        foreach([['MATUTINO',3,10,'7º A','Professor Um',1],['VESPERTINO',4,20,'8º A','Professor Dois',2]]as[$shift,$teacher,$class,$name,$teacherName,$event]){
            $repository->createImport(['ano_letivo'=>2026,'turno'=>$shift,'vigente_de'=>'2026-01-01','vigente_ate'=>'2026-12-31','nome_arquivo'=>'grade.xlsx','sha256'=>str_repeat('a',64),'usuario_id'=>1],[['turma_id_externo'=>$class,'turma_nome_snapshot'=>$name,'dia_semana'=>4,'numero_aula'=>1,'disciplina'=>'Matemática','professor_usuario_id'=>$teacher,'professor_nome_snapshot'=>$teacherName,'professor_nome_importado'=>$teacherName]]);
            $row=$apc->query('SELECT * FROM apc_horarios ORDER BY id DESC LIMIT 1')->fetch();$repository->saveSnapshot($event,[$row+['turno'=>$shift]]);
        }
        $repository->saveSnapshot(3,[]);$repository->saveSnapshot(4,[]);$repository->saveSnapshot(5,[],'LEGADO');
        return[$main,$apc,new ScheduleService($repository,new EventRepository($apc),new AccessRepository($main),new AuditRepository($apc),new XlsxScheduleParser(),sys_get_temp_dir(),1048576)];
    }

    private function service(PDO$main,PDO$apc,?ScheduleService$schedules=null):PlanService
    {
        $plans=new PlanRepository($apc);$access=new AccessRepository($main);return new PlanService($plans,new EventRepository($apc),$access,new AuditRepository($apc),new AuthorizationService($plans,$access),null,null,new EventWindow('2026-10-15'),$schedules);
    }

    private function controller(PDO$main,PDO$apc,?ScheduleService$schedules=null):PlanController
    {
        $plans=new PlanRepository($apc);$access=new AccessRepository($main);return new PlanController($this->service($main,$apc,$schedules),new AuthorizationService($plans,$access),new EventRepository($apc),$access,new View(dirname(__DIR__).'/apps/apc/resources/views'),null,new EventWindow('2026-10-15'),$schedules);
    }

    private function request(int$eventId=0):Request{return new Request('GET','/apc/planos/novo',$eventId?['evento'=>(string)$eventId]:[],[],[]);}
    private function teacher():array{return['id'=>3,'nome'=>'Professor Um','perfil'=>'PROFESSOR'];}
    private function input(int$eventId,int$classId):array{return['evento_id'=>$eventId,'turma_id_externo'=>$classId,'componente_curricular'=>'Matemática','competencias_habilidades'=>'Resolver problemas','conteudos'=>'Frações','descricao_atividade'=>'Atividade em sala','estrategia_devolucao'=>'Correção coletiva'];}
    private function assertScheduleForbidden(callable$action):void{try{$action();self::fail('Evento fora da obrigação docente deveria ser recusado.');}catch(HttpException$exception){self::assertSame(403,$exception->status);self::assertSame('APC_SCHEDULE_FORBIDDEN',$exception->errorCode);}}
}
