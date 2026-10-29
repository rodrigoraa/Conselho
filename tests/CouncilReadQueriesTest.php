<?php declare(strict_types=1);

namespace Tests;

use PDO;
use PreConselho\Controllers\{ManagementController,WebController};
use PreConselho\Integration\SecretariaApiClient;
use PreConselho\Repositories\AppRepository;
use PreConselho\Services\CouncilDocumentService;
use Shared\Exceptions\HttpException;
use Shared\Http\Request;
use Shared\Support\View;

final class CouncilReadQueriesTest extends ApcTestCase
{
    private PDO $db;
    private WebController $web;
    private ManagementController $management;

    protected function setUp():void
    {
        $this->db=$this->mainDatabase();$this->seedMain($this->db);
        $_SESSION['user']=['id'=>1,'nome'=>'Admin','perfil'=>'ADMIN'];
        $_SERVER['REQUEST_URI']='/consolidados';
        $repository=new AppRepository($this->db);$view=new View(dirname(__DIR__).'/apps/preconselho-web/resources/views');$api=new SecretariaApiClient();
        $this->web=new WebController($repository,$view,$api);$this->management=new ManagementController($repository,$api,$view);
        $this->db->exec("INSERT INTO periodos_pre_conselho(id,nome,ano_letivo,etapa,data_inicio,data_fim,status,criado_por,turno) VALUES
            (1,'Aberto atual',2026,'1º','2020-01-01','2020-12-31','ABERTO',1,'MATUTINO'),
            (2,'Preparação futura',2027,'1º','2027-01-01','2027-12-31','RASCUNHO',1,'MATUTINO'),
            (3,'Arquivo vespertino',2025,'1º','2025-01-01','2025-12-31','ENCERRADO',1,'VESPERTINO');
            INSERT INTO documento_turmas(id,periodo_id,turma_externa_id,turma_nome_snapshot,turma_ano_letivo_snapshot,conteudo,atualizado_por) VALUES
            (11,1,10,'7º A',2026,'Relato atual 100%_ com aspas '' e ação no pátio',1),
            (12,1,20,'8º A',2026,'Relato segundo',1),
            (13,2,10,'7º A',2027,'Relato em preparação',1),
            (14,3,30,'9º A',2025,'Relato histórico',2);
            INSERT INTO documento_turma_professores(documento_turma_id,professor_usuario_id,finalizado) VALUES(11,3,0),(12,4,0),(13,3,0),(14,3,0)");
    }

    protected function tearDown():void { unset($_SESSION['user'],$_SESSION['flash'],$_SESSION['_csrf']);$_SERVER['REQUEST_URI']='/'; }
    private function get(string $path,array $query=[]):Request { return new Request('GET',$path,$query,[],[]); }
    private function csv(array $query):array
    {
        $response=$this->web->consolidated($this->get('/consolidados',$query+['formato'=>'csv']));
        self::assertSame(200,$response->status);$stream=fopen('php://temp','r+');fwrite($stream,$response->body);rewind($stream);$rows=[];while(($row=fgetcsv($stream))!==false)$rows[]=$row;fclose($stream);return$rows;
    }

    public function testConsolidatedFiltersApplyToAllCsvRowsAndKeepSevenColumns():void
    {
        $rows=$this->csv(['ano'=>'2026','periodo'=>'1','turno'=>'MATUTINO']);
        self::assertCount(3,$rows);self::assertCount(7,$rows[0]);self::assertCount(7,$rows[1]);
        self::assertSame(['Aberto atual','Aberto atual'],array_column(array_slice($rows,1),0));
        $html=$this->web->consolidated($this->get('/consolidados',['ano'=>'2025','turno'=>'VESPERTINO']))->body;
        self::assertStringContainsString('Relato histórico',$html);self::assertStringNotContainsString('Relato atual 100%',$html);
        self::assertStringContainsString('/documentos/3#turma-14',$html);
    }

    public function testConsolidatedSearchTreatsPercentUnderscoreAndQuoteLiterally():void
    {
        self::assertCount(2,$this->csv(['q'=>"100%_ com aspas '"]));
        self::assertCount(1,$this->csv(['q'=>'100%%']));
        self::assertCount(2,$this->csv(['q'=>'PÁTIO']));
    }

    public function testPositiveNumericFiltersKeepCanonicalValuesAndTheSameSelection():void
    {
        $filters=AppRepository::councilFilters(['ano'=>'02026','periodo'=>'001','turno'=>'MATUTINO']);
        self::assertSame('2026',$filters['ano']);self::assertSame('1',$filters['periodo']);
        self::assertSame($this->csv(['ano'=>'2026','periodo'=>'1','turno'=>'MATUTINO']),$this->csv(['ano'=>'02026','periodo'=>'001','turno'=>'MATUTINO']));
        $this->seedAudit();$repository=new AppRepository($this->db);
        $filters=AppRepository::auditFilters(['usuario'=>'002']);
        self::assertSame('2',$filters['usuario']);
        $result=$repository->auditPage($filters,1);
        self::assertSame(1,$result['total']);self::assertSame(2,(int)$result['rows'][0]['usuario_id']);
        self::assertSame('sistema',AppRepository::auditFilters(['usuario'=>'sistema'])['usuario']);
    }

    public function testSummariesPointOnlyToOwnOpenPendingClassWithoutTimeBlock():void
    {
        $service=new CouncilDocumentService(new AppRepository($this->db));
        $summaries=array_column($service->summaries(3,'PROFESSOR'),null,'periodo_id');
        self::assertSame(11,$summaries[1]['first_pending_class']??null);
        self::assertNull($summaries[2]['first_pending_class']??null);self::assertNull($summaries[3]['first_pending_class']??null);
        $this->db->exec('UPDATE documento_turma_professores SET finalizado=1 WHERE professor_usuario_id=3 AND documento_turma_id IN(11,12)');
        $summaries=array_column($service->summaries(3,'PROFESSOR'),null,'periodo_id');
        self::assertNull($summaries[1]['first_pending_class']);
        self::assertSame('RASCUNHO',$summaries[2]['periodo_status']);self::assertSame('ENCERRADO',$summaries[3]['periodo_status']);
    }

    public function testHistoricalFiltersNeverHideCurrentOpenOrPreparingTasks():void
    {
        $html=$this->web->dashboard($this->get('/conselho',['ano'=>'2024','turno'=>'MATUTINO']))->body;
        self::assertStringContainsString('Aberto atual',$html);self::assertStringContainsString('Preparação futura',$html);
        self::assertStringNotContainsString('Arquivo vespertino',$html);
    }

    public function testPendingTaskUsesSchoolOrderAndNeverSelectsAnotherTeacher():void
    {
        $this->db->exec("INSERT INTO documento_turmas(id,periodo_id,turma_externa_id,turma_nome_snapshot,turma_ano_letivo_snapshot)VALUES(15,1,40,'3º A',2026),(16,1,50,'1º A',2026),(17,1,60,'2º A',2026);
            INSERT INTO documento_turma_professores(documento_turma_id,professor_usuario_id,finalizado)VALUES(15,3,0),(16,4,0),(17,3,1)");
        $service=new CouncilDocumentService(new AppRepository($this->db));
        $summaries=array_column($service->summaries(3,'PROFESSOR'),null,'periodo_id');
        self::assertSame(15,$summaries[1]['first_pending_class']);
        $document=$service->document(1,3,'PROFESSOR');
        self::assertSame([16,17,15,11,12],array_map('intval',array_column($document['classes'],'id')));
    }

    public function testFirstPendingTaskMatchesNarrativeLexicalOrderWithinTheSameYear():void
    {
        $this->db->exec("UPDATE documento_turmas SET turma_nome_snapshot='1º Ano A2' WHERE id=11;
            UPDATE documento_turmas SET turma_nome_snapshot='1º Ano A10' WHERE id=12;
            INSERT INTO documento_turma_professores(documento_turma_id,professor_usuario_id,finalizado)VALUES(12,3,0)");
        $service=new CouncilDocumentService(new AppRepository($this->db));
        $document=$service->document(1,3,'PROFESSOR');
        // The existing document is lexical: A10 precedes A2. Its narrative must not change.
        self::assertSame([12,11],array_map('intval',array_column($document['classes'],'id')));
        $summaries=array_column($service->summaries(3,'PROFESSOR'),null,'periodo_id');
        self::assertSame(12,$summaries[1]['first_pending_class']);
    }

    public function testInvalidCouncilFiltersFailInsteadOfBroadeningSelection():void
    {
        foreach([['turno'=>'NOTURNO'],['ano'=>'2026 OR 1=1'],['periodo'=>['1']],['q'=>str_repeat('a',251)]]as$query){
            try{$this->web->consolidated($this->get('/consolidados',$query));self::fail('Filtro inválido deve ser rejeitado.');}
            catch(HttpException $exception){self::assertSame(422,$exception->status);}
        }
    }

    private function seedAudit():void
    {
        $statement=$this->db->prepare('INSERT INTO auditoria(usuario_id,acao,entidade,entidade_id,dados_anteriores,dados_novos,endereco_ip,criado_em)VALUES(:usuario,:acao,:entidade,:id,:antes,:depois,:ip,:data)');
        for($i=1;$i<=32;$i++)$statement->execute(['usuario'=>$i===1?2:1,'acao'=>$i===1?'EDITAR':'CRIAR','entidade'=>$i===1?"item 100%_ com ' literal":'usuarios','id'=>$i,'antes'=>'{"nome":"<script>antes</script>","senha_hash":"segredo-antes"}','depois'=>'{"nome":"depois seguro","nested":{"access_token":"segredo-token","label":"Preservado"}}','ip'=>'127.0.0.1','data'=>$i===1?'2026-10-01 23:59:59':'2026-10-02 09:00:00']);
        $this->db->exec('UPDATE usuarios SET ativo=0 WHERE id=2');
    }

    public function testAuditSearchFindsOldRecordOutsideFirstPageAndEscapesDetails():void
    {
        $this->seedAudit();$html=$this->management->audit($this->get('/admin/auditoria',['q'=>"100%_ com ' literal"]))->body;
        self::assertStringContainsString('item 100%_',$html);self::assertStringContainsString('depois seguro',$html);
        self::assertStringNotContainsString('segredo-antes',$html);self::assertStringNotContainsString('segredo-token',$html);
        self::assertStringNotContainsString('<script>antes</script>',$html);self::assertStringContainsString('&lt;script&gt;antes&lt;/script&gt;',$html);
        self::assertStringContainsString('Preservado',$html);
    }

    public function testAuditCombinedAuthorActionAndInclusiveDatesShareCountAndRows():void
    {
        $this->seedAudit();$html=$this->management->audit($this->get('/admin/auditoria',['usuario'=>'2','acao'=>'EDITAR','de'=>'2026-10-01','ate'=>'2026-10-01','pagina'=>'9999']))->body;
        self::assertStringContainsString('item 100%_',$html);self::assertStringContainsString('Página 1 de 1',$html);
        self::assertStringNotContainsString('02/10/2026 09:00',$html);
    }

    public function testAuditClampsExcessivePageAndDoesNotTreatWildcardsAsPatterns():void
    {
        $this->seedAudit();$html=$this->management->audit($this->get('/admin/auditoria',['pagina'=>'999999999']))->body;
        self::assertStringContainsString('Página 2 de 2',$html);self::assertStringContainsString('item 100%_',$html);
        $empty=$this->management->audit($this->get('/admin/auditoria',['q'=>'%%','pagina'=>'-5']))->body;
        self::assertStringContainsString('Nenhum registro corresponde aos filtros',$empty);self::assertStringContainsString('Página 1 de 1',$empty);
    }

    public function testInvalidAuditDateIsRejectedInsteadOfSilentlyBroadeningQuery():void
    {
        try{$this->management->audit($this->get('/admin/auditoria',['de'=>'2026-02-31']));self::fail('Data inválida deve ser rejeitada.');}
        catch(HttpException $exception){self::assertSame(422,$exception->status);}
    }

    public function testAdminPendingCountsUseExistingResponsibilitiesOnly():void
    {
        $this->db->exec('INSERT INTO documento_turma_professores(documento_turma_id,professor_usuario_id,finalizado)VALUES(12,3,0)');
        $this->db->exec('UPDATE documento_turma_professores SET finalizado=1 WHERE documento_turma_id=11');
        $before=$this->db->query('SELECT * FROM documento_turma_professores ORDER BY id')->fetchAll();
        $directory=sys_get_temp_dir().'/council-query-view-'.bin2hex(random_bytes(6));mkdir($directory);
        file_put_contents($directory.'/admin.php','<?php echo json_encode($periods);');
        $api=new class extends SecretariaApiClient { public function turmas():array{return[];} };
        try{
            $web=new WebController(new AppRepository($this->db),new View($directory),$api);
            $periods=array_column(json_decode($web->admin()->body,true,512,JSON_THROW_ON_ERROR),null,'id');
            self::assertSame(2,(int)($periods[1]['pending_contributions']??-1));
            self::assertSame(2,(int)($periods[1]['pending_teachers']??-1));
            self::assertSame(1,(int)($periods[2]['pending_contributions']??-1));
            self::assertSame($before,$this->db->query('SELECT * FROM documento_turma_professores ORDER BY id')->fetchAll());
        }finally{unlink($directory.'/admin.php');rmdir($directory);}
    }

    public function testDashboardDoesNotCreateDocumentsForEmptyDraftPeriods():void
    {
        $this->db->exec("INSERT INTO periodos_pre_conselho(id,nome,ano_letivo,etapa,data_inicio,data_fim,status,criado_por,turno)VALUES(4,'Preparação sem turmas',2028,'1º','2028-01-01','2028-12-31','RASCUNHO',1,'MATUTINO')");
        $html=$this->web->dashboard()->body;
        self::assertStringNotContainsString('Preparação sem turmas',$html);
        self::assertSame(0,(int)$this->db->query('SELECT COUNT(*) FROM documento_turmas WHERE periodo_id=4')->fetchColumn());
    }

    public function testAuditPreservesFiltersAcrossPagesAndDisplaysMissingAuthorsAndMalformedSnapshots():void
    {
        $this->seedAudit();
        $html=$this->management->audit($this->get('/admin/auditoria',['acao'=>'CRIAR','de'=>'2026-10-02','ate'=>'2026-10-02','pagina'=>'1']))->body;
        self::assertStringContainsString('acao=CRIAR&amp;de=2026-10-02&amp;ate=2026-10-02&amp;pagina=2',$html);
        self::assertStringContainsString('31 registro(s) nos filtros',$html);
        $this->db->exec('PRAGMA foreign_keys=OFF');
        $this->db->exec("INSERT INTO auditoria(usuario_id,acao,entidade,entidade_id,dados_anteriores,dados_novos,criado_em)VALUES(NULL,'CRIAR','teste',1,NULL,'{malformado segredo-malformado','2026-10-03 12:00:00'),(999,'CODIGO_INCOMUM','teste',2,NULL,NULL,'2026-10-03 12:00:01')");
        $this->db->exec('PRAGMA foreign_keys=ON');
        $html=$this->management->audit($this->get('/admin/auditoria',['de'=>'2026-10-03']))->body;
        self::assertStringContainsString('Sistema',$html);self::assertStringContainsString('Usuário #999',$html);
        self::assertStringContainsString('CODIGO_INCOMUM',$html);self::assertStringContainsString('Snapshot inválido',$html);
        self::assertStringContainsString('Sem snapshot registrado',$html);self::assertStringNotContainsString('segredo-malformado',$html);
        $system=$this->management->audit($this->get('/admin/auditoria',['usuario'=>'sistema']))->body;
        self::assertStringContainsString('1 registro(s) nos filtros',$system);
        $result=(new AppRepository($this->db))->auditPage(AppRepository::auditFilters(['usuario'=>'sistema']),1);
        self::assertCount(1,$result['rows']);self::assertNull($result['rows'][0]['usuario_id']);
    }
}
