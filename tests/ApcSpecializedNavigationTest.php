<?php declare(strict_types=1);
namespace Tests;
use Apc\Controllers\{CurriculumAdminController,PlanController,ReportController,ScheduleAdminController};
use Apc\Repositories\{AccessRepository,AuditRepository,CurriculumRepository,EventRepository,PlanRepository,ScheduleRepository};
use Apc\Services\{AuthorizationService,CurriculumImporter,CurriculumService,EventWindow,PlanService,ScheduleService,XlsxScheduleParser};
use PreConselho\Support\Csrf;
use Shared\Exceptions\HttpException;
use Shared\Http\Request;
use Shared\Support\View;
final class ApcSpecializedNavigationTest extends ApcTestCase
{
    protected function tearDown():void{unset($_SESSION['user']);$_SERVER['REQUEST_URI']='/';}
    public function testPlansRemainScopedAndPaginationIsStable():void
    {
        $db=$this->apcDatabase();$this->seedEvent($db);$repo=new PlanRepository($db);
        foreach([3,3,4]as$i=>$user)$repo->insert(['evento_id'=>1,'professor_usuario_id'=>$user,'professor_nome_snapshot'=>'Professor','turma_id_externo'=>10,'turma_nome_snapshot'=>'7 A','componente_curricular'=>'Matemática '.$i,'competencias_habilidades'=>'','conteudos'=>'','descricao_atividade'=>'','estrategia_devolucao'=>'']);
        self::assertSame(2,$repo->count(3,'PROFESSOR'));
        self::assertSame(3,$repo->count(1,'ADMIN'));
        self::assertSame(0,$repo->count(3,'PROFESSOR',['professor'=>4]));
        $first=$repo->list(3,'PROFESSOR',[],1,0);$second=$repo->list(3,'PROFESSOR',[],1,1);
        self::assertCount(1,$first);self::assertCount(1,$second);self::assertNotSame($first[0]['id'],$second[0]['id']);
        self::assertSame(0,$repo->count(3,'PROFESSOR',['status'=>'FINALIZADO']));
        self::assertSame(0,$repo->count(3,'VISITANTE'));
        self::assertSame(2,$repo->count(3,'PROFESSOR',['ano'=>2026,'evento'=>1,'turma'=>10]));
        self::assertSame(0,$repo->count(3,'PROFESSOR',['ano'=>2025]));
    }
    public function testPlanListClampsPagesAndKeepsOtherTeachersOutsideResults():void
    {
        $db=$this->apcDatabase();$this->seedEvent($db);$main=$this->mainDatabase();$this->seedMain($main);$repo=new PlanRepository($db);$events=new EventRepository($db);$access=new AccessRepository($main);$authorization=new AuthorizationService($repo,$access);$window=new EventWindow('2026-08-15');
        for($i=1;$i<=28;$i++)$repo->insert(['evento_id'=>1,'professor_usuario_id'=>$i<=26?3:4,'professor_nome_snapshot'=>$i<=26?'Professor Um':'Outro professor privado','turma_id_externo'=>10,'turma_nome_snapshot'=>'7 A','componente_curricular'=>sprintf('Plano %03d',$i),'competencias_habilidades'=>'','conteudos'=>'','descricao_atividade'=>'','estrategia_devolucao'=>'']);
        $controller=new PlanController(new PlanService($repo,$events,$access,new AuditRepository($db),$authorization,null,null,$window),$authorization,$events,$access,$this->view(),null,$window,null,$repo);$_SESSION['user']=['id'=>3,'perfil'=>'PROFESSOR','nome'=>'Professor Um'];
        $html=$controller->index(new Request('GET','/apc/planos',['pagina'=>999,'ano'=>2026],[],[]))->body;
        self::assertStringContainsString('Mostrando 26–26 de 26 planos',$html);self::assertStringContainsString('Plano 026',$html);self::assertStringNotContainsString('Outro professor privado',$html);self::assertStringNotContainsString('Plano 001',$html);
        $html=$controller->index(new Request('GET','/apc/planos',['pagina'=>-3,'status'=>'FINALIZADO'],[],[]))->body;self::assertStringContainsString('Nenhum plano corresponde aos filtros',$html);
        $_SESSION['user']=['id'=>1,'perfil'=>'ADMIN','nome'=>'Admin'];$response=(new ReportController($repo,$events,$access,$this->view()))->index(new Request('GET','/apc/relatorios',['professor'=>3,'formato'=>'csv','pagina'=>2],[],[]));
        $lines=preg_split('/\R/',trim($response->body));self::assertCount(27,$lines);self::assertStringContainsString('Plano 001',$response->body);self::assertStringContainsString('Plano 026',$response->body);self::assertStringNotContainsString('Outro professor privado',$response->body);
    }
    public function testHistoricalScheduleDetailIsSelectOnlyIncludingUnassignedTeacherAndFilters():void
    {
        $db=$this->apcDatabase();$main=$this->mainDatabase();$this->seedMain($main);$repo=new ScheduleRepository($db);
        $row=['turma_id_externo'=>10,'turma_nome_snapshot'=>'Turma histórica','dia_semana'=>1,'numero_aula'=>1,'disciplina'=>'Ciências','professor_usuario_id'=>null,'professor_nome_snapshot'=>null,'professor_nome_importado'=>'Nome antigo'];
        $id=$repo->createImport(['ano_letivo'=>2026,'turno'=>'MATUTINO','vigente_de'=>'2026-01-01','vigente_ate'=>null,'nome_arquivo'=>'antiga.xlsx','sha256'=>str_repeat('a',64),'usuario_id'=>1],[$row,array_replace($row,['numero_aula'=>2])]);self::assertSame('ATIVO',$repo->findImport($id)['status']);self::assertCount(2,$repo->rowsForImport($id));$repo->deactivate($id,1);
        $service=new ScheduleService($repo,new EventRepository($db),new AccessRepository($main),new AuditRepository($db),new XlsxScheduleParser(),sys_get_temp_dir(),1000);
        $controller=new ScheduleAdminController($service,$repo,$this->view());$_SESSION['user']=['id'=>1,'perfil'=>'ADMIN','nome'=>'Admin'];
        $before=$db->query('SELECT total_changes()')->fetchColumn();$db->exec('PRAGMA query_only=ON');
        $html=$controller->show(new Request('GET','/',[],[],[]),['id'=>$id])->body;
        self::assertStringContainsString('Nome antigo',$html);self::assertStringContainsString('DESATIVADO',$html);self::assertStringContainsString('Sem professor associado',$html);
        self::assertCount(0,$repo->rowsForImport($id,['dia'=>2]));self::assertCount(2,$repo->rowsForImport($id,['turma'=>10,'dia'=>1]));self::assertSame($before,$db->query('SELECT total_changes()')->fetchColumn());
        $this->expectException(HttpException::class);$controller->show(new Request('GET','/',[],[],[]),['id'=>999]);
    }
    public function testCurriculumBeyondOneHundredHasLiteralSearchAndClampedPages():void
    {
        $db=$this->apcDatabase();$repo=new CurriculumRepository($db);$audit=new AuditRepository($db);$service=new CurriculumService($repo,$audit);$admin=['id'=>1,'perfil'=>'ADMIN','nome'=>'Admin'];
        $component=$service->saveComponent(null,['nome'=>'Ciências','sigla'=>'CI','modalidade'=>'GERAL','etapa'=>'EF_AI','area_conhecimento'=>'Ciências','ordem'=>0,'ativo'=>1],$admin,'','');
        for($i=1;$i<=111;$i++)$service->saveAbility(null,['componente_id'=>$component,'codigo'=>sprintf('C%03d',$i),'descricao'=>$i===111?"Texto %_ ' literal":'Habilidade '.$i,'origem'=>'OUTRO_OFICIAL','escopo'=>'CURRICULO_COMPLETO','fonte_documento'=>'CADASTRO_MANUAL','anos_series'=>['EF1'],'tipo_associacao'=>'CURRICULAR','ativo'=>1],$admin,'','');
        self::assertSame(111,$repo->countAbilities(['ano_serie'=>'EF1']));self::assertSame(1,$repo->countAbilities(['q'=>"%_ '"]));
        $_SESSION['user']=$admin;$controller=new CurriculumAdminController($repo,$service,new CurriculumImporter($repo,$audit,''),$this->view());$html=$controller->index(new Request('GET','/',['pagina'=>'999','ano_serie'=>'EF1'],[],[]))->body;
        self::assertStringContainsString('Mostrando 101–111 de 111',$html);self::assertStringContainsString('pagina=5',$html);self::assertStringContainsString('C111',$html);self::assertStringNotContainsString('C001</strong>',$html);
    }
    public function testCurriculumCountUsesDistinctAbilitiesAndUpdateReturnsOnlyAllowedContext():void
    {
        $db=$this->apcDatabase();$repo=new CurriculumRepository($db);$audit=new AuditRepository($db);$service=new CurriculumService($repo,$audit);$admin=['id'=>1,'perfil'=>'ADMIN','nome'=>'Admin'];$_SESSION['user']=$admin;
        $component=$service->saveComponent(null,['nome'=>'Ciências','sigla'=>'CI','modalidade'=>'GERAL','etapa'=>'EF_AI','area_conhecimento'=>'Ciências','ordem'=>0,'ativo'=>1],$admin,'','');
        $input=['componente_id'=>$component,'codigo'=>'COD_LITERAL','descricao'=>"Ciência %_ ' literal",'origem'=>'OUTRO_OFICIAL','escopo'=>'CURRICULO_COMPLETO','fonte_documento'=>'CADASTRO_MANUAL','associacoes'=>['EF1|CURRICULAR','EF1|RECOMPOSICAO'],'ativo'=>1];
        $id=$service->saveAbility(null,$input,$admin,'','');$filters=['etapa'=>'EF_AI','ano_serie'=>'EF1','componente'=>(string)$component,'origem'=>'OUTRO_OFICIAL','escopo'=>'CURRICULO_COMPLETO','ativo'=>'1','q'=>"%_ '"];
        self::assertSame(1,$repo->countAbilities($filters));self::assertCount(1,$repo->abilities($filters));self::assertSame(0,$repo->countAbilities(array_replace($filters,['ativo'=>'0'])));self::assertSame(0,$repo->countAbilities(['q'=>'inexistente']));
        $context=$filters+['pagina'=>'2','return'=>'https://example.invalid/','editar_habilidade'=>999];$controller=new CurriculumAdminController($repo,$service,new CurriculumImporter($repo,$audit,''),$this->view());
        $response=$controller->updateAbility(new Request('POST','/',[],$input+['_csrf'=>Csrf::token(),'contexto'=>$context],[]),['id'=>$id]);
        self::assertStringStartsWith('/apc/admin/curriculo?',$response->headers['Location']);self::assertStringEndsWith('#habilidades',$response->headers['Location']);parse_str((string)parse_url($response->headers['Location'],PHP_URL_QUERY),$actual);self::assertSame($filters+['pagina'=>'2'],$actual);self::assertCount(2,$repo->ability($id)['associacoes']);
        $response=$controller->toggleAbility(new Request('POST','/',[],['_csrf'=>Csrf::token(),'contexto'=>$context],[]),['id'=>$id]);parse_str((string)parse_url($response->headers['Location'],PHP_URL_QUERY),$actual);self::assertSame($filters+['pagina'=>'2'],$actual);self::assertSame(0,(int)$repo->ability($id)['ativo']);self::assertCount(2,$repo->ability($id)['associacoes']);
    }
    private function view():View{return new View(dirname(__DIR__).'/apps/apc/resources/views');}
}

