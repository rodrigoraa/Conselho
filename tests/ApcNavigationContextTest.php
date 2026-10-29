<?php declare(strict_types=1);

namespace Tests;

use Apc\Controllers\{CalendarController,SubmissionController};
use Apc\Repositories\{AccessRepository,AuditRepository,EventRepository,SubmissionRepository,TermRepository};
use Apc\Services\{SubmissionService,SubmissionWindow};
use Apc\Support\NavigationContext;
use PreConselho\Support\FormRecovery;
use Shared\Exceptions\HttpException;
use Shared\Http\Request;
use Shared\Support\View;

final class ApcNavigationContextTest extends ApcTestCase
{
    private string $directory;
    protected function setUp():void{$this->directory=sys_get_temp_dir().'/apc-context-'.bin2hex(random_bytes(6));mkdir($this->directory);}
    protected function tearDown():void{unset($_SESSION['user'],$_SESSION['_csrf'],$_SESSION['flash'],$_SESSION['_form_recovery']);$_SERVER['REQUEST_URI']='/';foreach(glob($this->directory.'/*')?:[]as$file)unlink($file);rmdir($this->directory);}

    public function testContextOnlyBuildsInternalAllowlistedReturns():void
    {
        $context=NavigationContext::from(['aba'=>'files','ano'=>'2026','evento'=>'2','professor'=>'3','q'=>"50%_O'Neil",'situacao'=>'late','pagina'=>'2','return'=>'https://evil.test','formato'=>'https://evil.test']);
        $url=$context->url();
        self::assertStringStartsWith('/apc?',$url);self::assertStringEndsWith('#arquivos',$url);
        parse_str((string)parse_url($url,PHP_URL_QUERY),$query);
        self::assertSame("50%_O'Neil",$query['q']);self::assertSame('2',$query['pagina']);self::assertArrayNotHasKey('return',$query);self::assertArrayNotHasKey('formato',$query);
        self::assertSame('/apc',NavigationContext::from(['aba'=>['files'],'evento'=>'https://evil.test','pagina'=>'-5','ano'=>'9999','q'=>[]])->url());
    }

    public function testLibraryPaginationFiltersWholeHistoryAndKeepsStablePageBounds():void
    {
        [$main,$apc,$repository]=$this->context();
        for($id=1;$id<=45;$id++)$this->submission($apc,$id,3,$id===1?"50%_O'Neil.pdf":'comum-'.$id.'.pdf');
        $first=$repository->page(2,'COORDENADOR',[],1,20);$second=$repository->page(2,'COORDENADOR',[],2,20);$last=$repository->page(2,'COORDENADOR',[],999,20);
        self::assertSame(45,$first['total']);self::assertCount(20,$first['rows']);self::assertCount(20,$second['rows']);self::assertCount(5,$last['rows']);self::assertSame(3,$last['page']);
        self::assertSame([],array_intersect(array_column($first['rows'],'id'),array_column($second['rows'],'id')));
        $literal=$repository->page(2,'COORDENADOR',['q'=>"50%_O'Neil"],1,20);
        self::assertSame(1,$literal['total']);self::assertSame("50%_O'Neil.pdf",$literal['rows'][0]['nome_original']);
        self::assertSame(45,$repository->page(2,'COORDENADOR',[],0,5000)['total']);self::assertCount(45,$repository->page(2,'COORDENADOR',[],0,5000)['rows']);
        self::assertSame(45,$repository->page(2,'COORDENADOR',['formato'=>'pdf'],1,20)['total']);self::assertSame(0,$repository->page(2,'COORDENADOR',['formato'=>'docx'],1,20)['total']);
        for($id=46;$id<=145;$id++)$this->submission($apc,$id,3,'volume-'.$id.'.pdf');
        $bounded=$repository->page(2,'COORDENADOR',[],PHP_INT_MAX,5000);self::assertSame(2,$bounded['page']);self::assertSame(100,$bounded['per_page']);self::assertCount(45,$bounded['rows']);
        self::assertSame(0,$repository->page(2,'COORDENADOR',['ano'=>'2025','evento'=>'1','professor'=>'3','situacao'=>'ontime'],1,20)['total']);
    }

    public function testTeacherScopeCannotBeOverriddenByProfessorFilter():void
    {
        [$main,$apc,$repository]=$this->context();$this->submission($apc,1,3,'proprio.pdf');$this->submission($apc,2,4,'outro.pdf');
        self::assertSame(0,$repository->page(3,'PROFESSOR',['professor'=>4],1,20)['total']);
        $own=$repository->page(3,'PROFESSOR',[],1,20);self::assertSame(1,$own['total']);self::assertSame('proprio.pdf',$own['rows'][0]['nome_original']);
    }

    public function testSelectionUsesOnlyAvailableEventsAndLibraryFiltersNeverResetCompletion():void
    {
        [$main,$apc,$repository,$controller]=$this->context();
        $apc->exec("INSERT INTO apc_eventos(id,ano_letivo,data,titulo,tipo,origem,descricao,status,criado_por)VALUES(2,2026,'2026-08-14','Evento permitido','OUTRO','ESCOLA','','ATIVO',1),(3,2026,'2026-08-14','Evento restrito','OUTRO','ESCOLA','','ATIVO',1);UPDATE apc_eventos SET turno='VESPERTINO' WHERE id=3");
        $this->submission($apc,1,3,'concluido.pdf',10);
        for($id=2;$id<=25;$id++)$this->submission($apc,$id,3,'historico-'.$id.'.pdf');
        $_SESSION['user']=['id'=>3,'nome'=>'Professor Um','perfil'=>'PROFESSOR'];$_SERVER['REQUEST_URI']='/apc';
        $html=$controller->index(new Request('GET','/apc',['evento'=>'2','q'=>'sem resultados','pagina'=>'2'],[],[]))->body;
        self::assertMatchesRegularExpression('/<option value="2" selected[^>]*>/',$html);
        self::assertStringContainsString('Envio concluído',$html);self::assertStringNotContainsString('Evento restrito',$html);
        $complete=$controller->index(new Request('GET','/apc',['evento'=>'1'],[],[]))->body;preg_match('/<select name="evento_id"[^>]*>(.*?)<\/select>/s',$complete,$uploadSelect);self::assertStringNotContainsString('<option value="1" selected',$uploadSelect[1]??'');self::assertStringContainsString('não está disponível para um novo envio',$complete);
        $html=$controller->index(new Request('GET','/apc',['evento'=>'3'],[],[]))->body;
        preg_match('/<select name="evento_id"[^>]*>(.*?)<\/select>/s',$html,$restrictedUploadSelect);self::assertStringNotContainsString('<option value="3"',$restrictedUploadSelect[1]??'');self::assertStringNotContainsString('Evento restrito',$html);
        self::assertStringContainsString('não está disponível para um novo envio',$html);
    }

    public function testPreviewAndDeletionPreserveFiltersWithoutAcceptingReturnUrl():void
    {
        [$main,$apc,$repository,$controller]=$this->context();$this->submission($apc,1,3,"50%_O'Neil.pdf");
        $_SESSION['user']=['id'=>2,'nome'=>'Coordenação','perfil'=>'COORDENADOR'];$_SESSION['_csrf']='context-csrf';$_SERVER['REQUEST_URI']='/apc/envios/1/visualizar';
        $query=['aba'=>'files','evento'=>'1','professor'=>'3','q'=>"50%_O'Neil",'situacao'=>'ontime','pagina'=>'2','return'=>'https://evil.test'];
        $html=$controller->preview(new Request('GET','/apc/envios/1/visualizar',$query,[],[]),['id'=>1])->body;
        self::assertStringContainsString('Abrir PDF para conferir',$html);self::assertStringContainsString('q=50%25_O%27Neil',$html);self::assertStringContainsString('name="pagina" value="2"',$html);self::assertStringNotContainsString('evil.test',$html);
        $response=$controller->delete(new Request('POST','/apc/envios/1/excluir',[],['_csrf'=>'context-csrf']+$query,[]),['id'=>1]);
        self::assertSame(302,$response->status);self::assertStringStartsWith('/apc?',$response->headers['Location']);self::assertStringEndsWith('#arquivos',$response->headers['Location']);self::assertStringContainsString('professor=3',$response->headers['Location']);self::assertNull($repository->find(1));
    }

    public function testCalendarUsesPersonalEligibilityAndCompletedHistoryForActions():void
    {
        [$main,$apc,$repository]=$this->context();$access=new AccessRepository($main);$events=new EventRepository($apc);$terms=new TermRepository($apc);
        $service=new SubmissionService($repository,$events,$terms,$access,new AuditRepository($apc),$this->directory,1048576,null,null,'2026-08-20');
        $controller=new CalendarController($events,$repository,new View(dirname(__DIR__).'/apps/apc/resources/views'),new SubmissionWindow($terms,'2026-08-20'),null,$service,$access);
        $_SESSION['user']=['id'=>3,'nome'=>'Professor Um','perfil'=>'PROFESSOR'];
        $available=$controller->show(new Request('GET','/apc/eventos/1',[],[],[]),['id'=>1])->body;
        self::assertStringContainsString('/apc?evento=1#apc-upload',$available);self::assertStringContainsString('Evento ativo',$available);
        $this->submission($apc,1,3,'concluido.pdf',10);
        $complete=$controller->show(new Request('GET','/apc/eventos/1',[],[],[]),['id'=>1])->body;
        self::assertStringNotContainsString('>Anexar arquivo<',$complete);self::assertStringContainsString('Envio concluído para todas as suas turmas.',$complete);
        $_SESSION['user']=['id'=>2,'nome'=>'Coordenação','perfil'=>'COORDENADOR'];
        $management=$controller->show(new Request('GET','/apc/eventos/1',[],[],[]),['id'=>1])->body;
        self::assertStringNotContainsString('>Anexar arquivo<',$management);self::assertStringContainsString('/apc?aba=files&amp;evento=1#arquivos',$management);
        self::assertSame('Sexta-feira',NavigationContext::reference(['data'=>'2026-08-14','dia_grade_referencia'=>1]));
        self::assertSame('Segunda-feira',NavigationContext::reference(['data'=>'2026-08-15','dia_grade_referencia'=>1]));
    }

    public function testTrackingFiltersBeforePaginationAndRecountsOnlyDisplayedProfessors():void
    {
        [$main,$apc,$repository,$controller]=$this->context();
        for($id=2;$id<=13;$id++)$apc->exec("INSERT INTO apc_eventos(id,ano_letivo,data,titulo,tipo,origem,descricao,status,criado_por)VALUES($id,2026,'2026-08-14','Evento $id','OUTRO','ESCOLA','','ATIVO',1)");
        $_SESSION['user']=['id'=>2,'nome'=>'Coordenação','perfil'=>'COORDENADOR'];
        $second=$controller->index(new Request('GET','/apc',['aba'=>'tracking','pagina'=>'2','professor'=>'3'],[],[]))->body;
        self::assertStringContainsString('Mostrando 11–13 de 13',$second);self::assertStringContainsString('0 de 1 professores concluíram',$second);self::assertStringContainsString('professor=3',$second);
        $filtered=$controller->index(new Request('GET','/apc',['aba'=>'tracking','evento'=>'1','professor'=>'3','q'=>"%_'"],[],[]))->body;
        self::assertStringContainsString('Nenhum resultado para os filtros escolhidos.',$filtered);
    }

    public function testDualProfileSwitchToMineRemovesIncompatibleHiddenProfessorFilter():void
    {
        [$main,$apc,$repository,$controller]=$this->context();
        $main->exec("INSERT INTO professores(id,usuario_id)VALUES(3,2);INSERT INTO vinculos_professor_turma(id,professor_id,turma_externa_id,turma_nome_snapshot,turma_ano_letivo_snapshot,turno)VALUES(3,3,30,'9º A',2026,'MATUTINO')");
        $this->submission($apc,1,2,'comprovante-proprio.pdf',30);$this->submission($apc,2,4,'outro-professor.pdf',20);
        $_SESSION['user']=['id'=>2,'nome'=>'Coordenação','perfil'=>'COORDENADOR'];
        $files=$controller->index(new Request('GET','/apc',['aba'=>'files','professor'=>'4'],[],[]))->body;
        preg_match('/data-apc-view-tab="mine"[^>]*href="([^"]+)"/',$files,$match);$mineUrl=html_entity_decode($match[1],ENT_QUOTES,'UTF-8');
        self::assertStringNotContainsString('professor=4',$mineUrl);parse_str((string)parse_url($mineUrl,PHP_URL_QUERY),$query);
        $mine=$controller->index(new Request('GET','/apc',$query,[],[]))->body;
        preg_match('#<div id="apc-minhas".*?(?=<div id="apc-acompanhamento")#s',$mine,$minePanel);self::assertStringContainsString('comprovante-proprio.pdf',$minePanel[0]);self::assertStringNotContainsString('outro-professor.pdf',$minePanel[0]);
        $direct=$controller->index(new Request('GET','/apc',['aba'=>'mine','professor'=>'4'],[],[]))->body;
        preg_match('#<div id="apc-minhas".*?(?=<div id="apc-acompanhamento")#s',$direct,$directPanel);self::assertStringContainsString('comprovante-proprio.pdf',$directPanel[0]);self::assertStringNotContainsString('outro-professor.pdf',$directPanel[0]);
    }

    public function testFilteredTrackingNeverClaimsAllTeachersCompletedAndKeepsGeneralPendingCount():void
    {
        [$main,$apc,$repository,$controller]=$this->context();$this->submission($apc,1,3,'concluido.pdf',10);
        $_SESSION['user']=['id'=>2,'nome'=>'Coordenação','perfil'=>'COORDENADOR'];
        $html=$controller->index(new Request('GET','/apc',['aba'=>'tracking','evento'=>'1','professor'=>'3'],[],[]))->body;
        self::assertStringNotContainsString('Todos os professores concluíram este evento.',$html);
        self::assertStringNotContainsString('>Todos concluíram<',$html);
        self::assertStringContainsString('Todos os professores do resultado dos filtros concluíram.',$html);
        self::assertStringContainsString('No evento completo: 1 de 2 professores concluíram; 1 professor(es) com pendência.',$html);
        self::assertStringContainsString('0 de 1 professores concluíram',$controller->index(new Request('GET','/apc',['aba'=>'tracking','evento'=>'1','professor'=>'4'],[],[]))->body);
    }

    public function testDualProfileUploadRecoveryReturnsToVisibleMineWithoutChangingDestination():void
    {
        [$main,$apc,$repository,$controller]=$this->context();
        $main->exec("INSERT INTO professores(id,usuario_id)VALUES(3,2);INSERT INTO vinculos_professor_turma(id,professor_id,turma_externa_id,turma_nome_snapshot,turma_ano_letivo_snapshot,turno)VALUES(3,3,30,'9º A',2026,'MATUTINO')");
        $_SESSION['user']=['id'=>2,'nome'=>'Coordenação','perfil'=>'COORDENADOR'];$_SESSION['_csrf']='context-csrf';
        $post=new Request('POST','/apc/envios',[],['_csrf'=>'context-csrf','evento_id'=>'1','etapa'=>'EF_AF','ano_serie'=>'EF9','turma_id'=>'30'],['CONTENT_TYPE'=>'multipart/form-data; boundary=test']);
        try{$controller->upload($post);self::fail('Upload sem arquivo deveria falhar.');}catch(HttpException $error){self::assertSame(422,$error->status);$redirect=FormRecovery::recover($post,$error);}
        self::assertSame('/apc#apc-upload',$redirect->headers['Location']);$_SERVER['REQUEST_URI']='/apc';
        $html=$controller->index(new Request('GET','/apc',[],[],[]))->body;
        self::assertMatchesRegularExpression('/id="apc-minhas"[^>]*data-apc-view-panel="mine"(?![^>]*hidden)/',$html);
        self::assertStringContainsString('data-form-recovery=',$html);self::assertStringContainsString('data-apc-submission',$html);
        self::assertStringContainsString('&quot;evento_id&quot;:&quot;1&quot;',$html);self::assertStringContainsString('&quot;turma_id&quot;:&quot;30&quot;',$html);self::assertStringContainsString('Selecione o arquivo novamente para continuar.',$html);
        self::assertArrayNotHasKey('_form_recovery',$_SESSION);
    }

    public function testDeletingLastMatchingFileKeepsSelectedFiltersVisibleAndResubmittable():void
    {
        [$main,$apc,$repository,$controller]=$this->context();$this->submission($apc,1,3,'ultimo-do-filtro.pdf',10);$this->submission($apc,2,4,'outro-conjunto.pdf',20);
        $apc->exec("INSERT INTO apc_eventos(id,ano_letivo,data,titulo,tipo,origem,descricao,status,criado_por)VALUES(2,2027,'2027-08-14','Outro conjunto','OUTRO','ESCOLA','','ATIVO',1);UPDATE apc_envios SET evento_id=2 WHERE id=2");
        $_SESSION['user']=['id'=>2,'nome'=>'Coordenação','perfil'=>'COORDENADOR'];$_SESSION['_csrf']='context-csrf';$_SERVER['REQUEST_URI']='/apc';
        $filters=['aba'=>'files','ano'=>'2026','evento'=>'1','professor'=>'3'];
        $redirect=$controller->delete(new Request('POST','/apc/envios/1/excluir',[],['_csrf'=>'context-csrf']+$filters,[]),['id'=>1]);
        parse_str((string)parse_url($redirect->headers['Location'],PHP_URL_QUERY),$query);
        $html=$controller->index(new Request('GET','/apc',$query,[],[]))->body;
        $this->assertSelectedFilterValues($html,'files',['ano'=>'2026','evento'=>'1','professor'=>'3']);
        self::assertSame(0,$repository->page(2,'COORDENADOR',$query)['total']);self::assertSame(1,$repository->page(2,'COORDENADOR',[])['total']);
    }

    public function testMissingFilterChoicesRemainVisibleWithoutLookingUpRestrictedMetadata():void
    {
        [$main,$apc,$repository,$controller]=$this->context();
        $apc->exec("INSERT INTO apc_eventos(id,ano_letivo,data,titulo,tipo,origem,descricao,status,criado_por,turno)VALUES(9,2030,'2030-08-16','Título restrito não deve aparecer','OUTRO','ESCOLA','','ATIVO',1,'VESPERTINO')");
        $_SERVER['REQUEST_URI']='/apc';$_SESSION['user']=['id'=>3,'nome'=>'Professor Um','perfil'=>'PROFESSOR'];
        $mine=$controller->index(new Request('GET','/apc',['aba'=>'mine','ano'=>'2030','evento'=>'9'],[],[]))->body;
        $this->assertSelectedFilterValues($mine,'mine',['ano'=>'2030','evento'=>'9']);
        self::assertStringNotContainsString('Título restrito não deve aparecer',$mine);
        $_SESSION['user']=['id'=>2,'nome'=>'Coordenação','perfil'=>'COORDENADOR'];
        $tracking=$controller->index(new Request('GET','/apc',['aba'=>'tracking','ano'=>'2099','evento'=>'999','professor'=>'999'],[],[]))->body;
        $this->assertSelectedFilterValues($tracking,'tracking',['ano'=>'2099','evento'=>'999','professor'=>'999']);
    }

    private function assertSelectedFilterValues(string $html,string $view,array $expected):void
    {
        $document=new \DOMDocument();$previous=libxml_use_internal_errors(true);$document->loadHTML('<?xml encoding="UTF-8">'.$html);libxml_clear_errors();libxml_use_internal_errors($previous);$xpath=new \DOMXPath($document);
        $forms=$xpath->query('//form[@data-apc-server-filters][input[@name="aba" and @value="'.$view.'"]]');self::assertSame(1,$forms->length);
        foreach($expected as$name=>$value){$selected=$xpath->query('.//select[@name="'.$name.'"]/option[@selected]',$forms->item(0));self::assertSame(1,$selected->length,'Filtro selecionado deve continuar visível: '.$view.'/'.$name);self::assertSame($value,$selected->item(0)->getAttribute('value'));}
    }

    private function context():array
    {
        $main=$this->mainDatabase();$this->seedMain($main);$apc=$this->apcDatabase();$this->seedEvent($apc);$repository=new SubmissionRepository($apc);$access=new AccessRepository($main);
        $service=new SubmissionService($repository,new EventRepository($apc),new TermRepository($apc),$access,new AuditRepository($apc),$this->directory,1048576,null,null,'2026-08-20');
        return[$main,$apc,$repository,new SubmissionController($service,$repository,$access,new View(dirname(__DIR__).'/apps/apc/resources/views'))];
    }
    private function submission(\PDO $db,int $id,int $teacher,string $name,?int $classId=null):void
    {
        $classId??=100+$id;$stored=sprintf('%032x',$id).'.pdf';file_put_contents($this->directory.'/'.$stored,'%PDF-1.4 synthetic');
        $statement=$db->prepare("INSERT INTO apc_envios(id,evento_id,bimestre_id,professor_usuario_id,professor_nome_snapshot,etapa,ano_serie,turma_id_externo,nome_original,nome_armazenado,mime_type,tamanho_bytes,sha256,caminho_relativo,atrasado,dias_atraso,enviado_em)VALUES(:id,1,3,:teacher,:teacher_name,'EF_AF','EF7',:class,:name,:stored,'application/pdf',1200,:sha,:stored,0,0,'2026-08-11 10:00:00')");
        $statement->execute([':id'=>$id,':teacher'=>$teacher,':teacher_name'=>$teacher===3?'Professor Um':'Professor Dois',':class'=>$classId,':name'=>$name,':stored'=>$stored,':sha'=>str_repeat('a',64)]);
        $statement=$db->prepare('INSERT INTO apc_envio_turmas(envio_id,turma_id_externo,turma_nome_snapshot)VALUES(:id,:class,:name)');$statement->execute([':id'=>$id,':class'=>$classId,':name'=>'7º A']);
    }
}
