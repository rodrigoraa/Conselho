<?php declare(strict_types=1);

namespace Tests;

use Apc\Controllers\CalendarController;
use Apc\Repositories\{AccessRepository,AuditRepository,EventRepository,ScheduleRepository,SubmissionRepository,TermRepository};
use Apc\Services\{ScheduleService,SubmissionService,SubmissionWindow,XlsxScheduleParser};
use Apc\Support\Module;
use PDO;
use PreConselho\Integration\SecretariaApiClient;
use Shared\Exceptions\HttpException;
use Shared\Http\Request;
use Shared\Support\View;

final class ApcScopedEventVisibilityTest extends ApcTestCase
{
    protected function tearDown():void{unset($_SESSION['user']);$_SERVER['REQUEST_URI']='/';}

    public function testTeacherDashboardKeepsOnlyMatchingScopedEventsAndPreservesCompletedAndGlobalEvents():void
    {
        [$main,$apc,$schedules]=$this->context();$access=new AccessRepository($main);$service=$this->submissionService($main,$apc,$schedules);
        $dashboard=$service->teacherDashboard($access->seriesFor(3,'PROFESSOR'),[['evento_id'=>1,'professor_usuario_id'=>3,'turma_id_externo'=>10]],3);
        self::assertSame([1,3],array_map('intval',array_column($dashboard['available'],'id')));
        self::assertSame('COMPLETO',$dashboard['available'][0]['status']);self::assertSame('SEM_AULA',$dashboard['available'][1]['status']);self::assertSame([1],$dashboard['eligible_events'][10]);
        $other=$service->teacherDashboard($access->seriesFor(4,'PROFESSOR'),[],4);
        self::assertSame([2,3],array_map('intval',array_column($other['available'],'id')));
    }

    public function testTeacherDashboardHidesScopedEventsWhenScheduleServiceIsUnavailable():void
    {
        [$main,$apc]=$this->context();$access=new AccessRepository($main);$dashboard=$this->submissionService($main,$apc)->teacherDashboard($access->seriesFor(3,'PROFESSOR'),[],3);
        self::assertSame([3],array_map('intval',array_column($dashboard['available'],'id')));
    }

    public function testTeacherCalendarListsOnlyMatchingScopedAndGlobalEvents():void
    {
        [$main,$apc,$schedules]=$this->context();$this->teacher();$html=$this->calendar($apc,$schedules)->index($this->calendarRequest())->body;
        self::assertStringContainsString('APC matutina selecionada',$html);self::assertStringContainsString('APC geral preservada',$html);
        foreach(['APC vespertina selecionada','APC sem aula neste dia','APC aguardando revisão']as$title)self::assertStringNotContainsString($title,$html);
    }

    public function testTeacherCalendarFailsClosedWithoutScheduleService():void
    {
        [$main,$apc]=$this->context();$this->teacher();$html=$this->calendar($apc)->index($this->calendarRequest())->body;
        self::assertStringContainsString('APC geral preservada',$html);self::assertStringNotContainsString('APC matutina selecionada',$html);self::assertStringNotContainsString('APC vespertina selecionada',$html);
    }

    public function testTeacherCannotOpenScopedEventForAnotherShiftNoClassOrLegacyState():void
    {
        [$main,$apc,$schedules]=$this->context();$this->teacher();$controller=$this->calendar($apc,$schedules);
        foreach([2,4,5]as$id){try{$controller->show(new Request('GET','/apc/eventos/'.$id,[],[],[]),['id'=>(string)$id]);self::fail('Evento sem obrigação docente deve ser recusado.');}catch(HttpException$exception){self::assertSame(403,$exception->status);}}
    }

    public function testTeacherCanOpenMatchingScopedAndGlobalEvents():void
    {
        [$main,$apc,$schedules]=$this->context();$this->teacher();$controller=$this->calendar($apc,$schedules);
        self::assertStringContainsString('APC matutina selecionada',$controller->show(new Request('GET','/apc/eventos/1',[],[],[]),['id'=>'1'])->body);
        self::assertStringContainsString('APC geral preservada',$controller->show(new Request('GET','/apc/eventos/3',[],[],[]),['id'=>'3'])->body);
    }

    public function testTeacherCannotOpenScopedEventWithoutScheduleService():void
    {
        [$main,$apc]=$this->context();$this->teacher();
        try{$this->calendar($apc)->show(new Request('GET','/apc/eventos/1',[],[],[]),['id'=>'1']);self::fail('Evento por turno deve exigir consulta à grade.');}catch(HttpException$exception){self::assertSame(403,$exception->status);}
    }

    public function testManagementKeepsEveryScopedCalendarEventAndDirectEventAccess():void
    {
        [$main,$apc,$schedules]=$this->context();$controller=$this->calendar($apc,$schedules);
        foreach(['ADMIN','COORDENADOR']as$role){$_SESSION['user']=['id'=>1,'nome'=>'Gestão','perfil'=>$role];$html=$controller->index($this->calendarRequest())->body;foreach(['APC matutina selecionada','APC vespertina selecionada','APC sem aula neste dia','APC aguardando revisão']as$title)self::assertStringContainsString($title,$html);self::assertStringContainsString('APC vespertina selecionada',$controller->show(new Request('GET','/apc/eventos/2',[],[],[]),['id'=>'2'])->body);}
    }

    public function testModuleWiresScheduleVisibilityIntoCalendar():void
    {
        [$main,$apc]=$this->context();$this->teacher();$module=new Module($apc,$main,new SecretariaApiClient(),dirname(__DIR__));$html=$module->calendar->index($this->calendarRequest())->body;
        self::assertStringContainsString('APC matutina selecionada',$html);self::assertStringNotContainsString('APC vespertina selecionada',$html);
    }

    private function context():array
    {
        $main=$this->mainDatabase();$this->seedMain($main);$main->exec("UPDATE vinculos_professor_turma SET turno='VESPERTINO' WHERE professor_id=2");$apc=$this->apcDatabase();
        $insert=$apc->prepare("INSERT INTO apc_eventos(id,ano_letivo,data,titulo,tipo,origem,descricao,status,criado_por,turno)VALUES(:id,2026,'2026-10-15',:titulo,'OUTRO','ESCOLA','','ATIVO',1,:turno)");
        foreach([[1,'APC matutina selecionada','MATUTINO'],[2,'APC vespertina selecionada','VESPERTINO'],[3,'APC geral preservada',null],[4,'APC sem aula neste dia','MATUTINO'],[5,'APC aguardando revisão','MATUTINO']]as[$id,$title,$shift])$insert->execute([':id'=>$id,':titulo'=>$title,':turno'=>$shift]);
        $repository=new ScheduleRepository($apc);
        foreach([['MATUTINO',3,10,'7º A','Professor Um',1],['VESPERTINO',4,20,'8º A','Professor Dois',2]]as[$shift,$teacher,$class,$name,$teacherName,$event]){
            $repository->createImport(['ano_letivo'=>2026,'turno'=>$shift,'vigente_de'=>'2026-01-01','vigente_ate'=>'2026-12-31','nome_arquivo'=>'grade.xlsx','sha256'=>str_repeat('a',64),'usuario_id'=>1],[['turma_id_externo'=>$class,'turma_nome_snapshot'=>$name,'dia_semana'=>4,'numero_aula'=>1,'disciplina'=>'Matemática','professor_usuario_id'=>$teacher,'professor_nome_snapshot'=>$teacherName,'professor_nome_importado'=>$teacherName]]);
            $row=$apc->query('SELECT * FROM apc_horarios ORDER BY id DESC LIMIT 1')->fetch();$repository->saveSnapshot($event,[$row+['turno'=>$shift]]);
        }
        $repository->saveSnapshot(3,[]);$repository->saveSnapshot(4,[]);$repository->saveSnapshot(5,[],'LEGADO');
        $schedules=new ScheduleService($repository,new EventRepository($apc),new AccessRepository($main),new AuditRepository($apc),new XlsxScheduleParser(),sys_get_temp_dir(),1048576);return[$main,$apc,$schedules];
    }

    private function submissionService(PDO$main,PDO$apc,?ScheduleService$schedules=null):SubmissionService{return new SubmissionService(new SubmissionRepository($apc),new EventRepository($apc),new TermRepository($apc),new AccessRepository($main),new AuditRepository($apc),sys_get_temp_dir(),1048576,null,null,'2026-10-15',null,null,$schedules);}
    private function calendar(PDO$apc,?ScheduleService$schedules=null):CalendarController{return new CalendarController(new EventRepository($apc),new SubmissionRepository($apc),new View(dirname(__DIR__).'/apps/apc/resources/views'),new SubmissionWindow(new TermRepository($apc),'2026-10-15'),$schedules);}
    private function calendarRequest():Request{return new Request('GET','/apc/calendario',['ano'=>'2026','mes'=>'10'],[],[]);}
    private function teacher():void{$_SESSION['user']=['id'=>3,'nome'=>'Professor Um','perfil'=>'PROFESSOR'];$_SERVER['REQUEST_URI']='/apc/calendario';}
}
