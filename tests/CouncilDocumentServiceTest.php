<?php declare(strict_types=1);

namespace Tests;

use PDO;
use PHPUnit\Framework\TestCase;
use PreConselho\Repositories\AppRepository;
use PreConselho\Services\CouncilDocumentService;
use Shared\Exceptions\HttpException;
use Shared\Support\View;

final class CouncilDocumentServiceTest extends TestCase
{
    private PDO $db;
    private CouncilDocumentService $service;

    protected function setUp(): void
    {
        $this->db=new PDO('sqlite::memory:');
        $this->db->setAttribute(PDO::ATTR_ERRMODE,PDO::ERRMODE_EXCEPTION);
        $this->db->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE,PDO::FETCH_ASSOC);
        $migrations=glob(dirname(__DIR__).'/apps/preconselho-web/database/migrations/*.sql')?:[];sort($migrations);
        foreach($migrations as$migration)$this->db->exec((string)file_get_contents($migration));
        $hash=password_hash('interno',PASSWORD_DEFAULT);
        $this->db->exec("INSERT INTO usuarios(id,nome,email,senha_hash,perfil)VALUES(1,'Coordenação','c@test','$hash','COORDENADOR'),(2,'Professor Um','p1@test','$hash','PROFESSOR'),(3,'Professor Dois','p2@test','$hash','PROFESSOR'),(4,'Administração','a@test','$hash','ADMIN');INSERT INTO professores(id,usuario_id)VALUES(1,2),(2,3);INSERT INTO vinculos_professor_turma(id,professor_id,turma_externa_id,turma_nome_snapshot,turma_ano_letivo_snapshot,turno)VALUES(1,1,10,'1º ano',2026,'MATUTINO'),(2,2,10,'1º ano',2026,'MATUTINO'),(3,1,20,'2º ano',2026,'MATUTINO'),(4,2,30,'3º ano',2026,'VESPERTINO');INSERT INTO periodos_pre_conselho(id,nome,ano_letivo,etapa,data_inicio,data_fim,status,criado_por,turno)VALUES(1,'3º Bimestre',2026,'3º','2020-01-01','2099-12-31','ABERTO',1,'MATUTINO')");
        $this->service=new CouncilDocumentService(new AppRepository($this->db));
        $this->service->synchronizePeriod(1);
    }

    public function testTodosVeemMesmoDocumentoMasProfessorEditaSomenteSuasTurmas(): void
    {
        $teacherOne=$this->service->document(1,2,'PROFESSOR');
        $teacherTwo=$this->service->document(1,3,'PROFESSOR');
        self::assertCount(2,$teacherOne['classes']);
        self::assertCount(2,$teacherTwo['classes']);
        self::assertSame(2,count(array_filter($teacherOne['classes'],static fn(array$c):bool=>(bool)$c['minha_turma'])));
        self::assertSame(1,count(array_filter($teacherTwo['classes'],static fn(array$c):bool=>(bool)$c['minha_turma'])));
    }

    public function testDocumentoCarregaSomenteVinculosDoTurnoEscolhido(): void
    {
        $this->db->exec("INSERT INTO periodos_pre_conselho(id,nome,ano_letivo,etapa,data_inicio,data_fim,status,criado_por,turno)VALUES(2,'3º Bimestre',2026,'3º','2020-01-01','2099-12-31','ABERTO',1,'VESPERTINO')");
        $this->service->synchronizePeriod(2);
        $document=$this->service->document(2,3,'PROFESSOR');
        self::assertSame('VESPERTINO',$document['period']['turno']);
        self::assertCount(1,$document['classes']);
        self::assertSame('3º ano',$document['classes'][0]['turma_nome_snapshot']);
        self::assertStringContainsString('turno vespertino',$document['opening']['texto']);
    }

    public function testSomenteCoordenacaoOuAdministracaoEditaAberturaDaAta(): void
    {
        $opening=$this->service->document(1,1,'COORDENADOR')['opening'];
        self::assertStringContainsString('No dia ___',(string)$opening['texto']);
        $saved=$this->service->saveOpening(1,'Abertura definida pela coordenação.',(int)$opening['versao'],1,'COORDENADOR');
        self::assertSame(2,$saved['version']);
        self::assertSame('Abertura definida pela coordenação.',(string)$this->db->query('SELECT texto FROM documento_aberturas WHERE periodo_id=1')->fetchColumn());
        try{$this->service->saveOpening(1,'Tentativa do professor.',2,2,'PROFESSOR');self::fail('O professor não deveria editar a abertura.');}
        catch(HttpException$exception){self::assertSame(403,$exception->status);}
    }

    public function testSegundoProfessorInsereNoMeioDoMesmoTextoLivreDaTurma(): void
    {
        $classId=$this->classId(10);
        $first='Gabriel apresentou boa evolução. Bruno precisa de acompanhamento. Rodrigo participa das atividades.';
        $saved=$this->service->saveClass(1,$classId,$first,1,2,'PROFESSOR','127.0.0.1','test');
        self::assertSame(2,$saved['version']);
        $seen=$this->service->document(1,3,'PROFESSOR')['classes'][0];
        self::assertSame($first,$seen['conteudo']);

        $second='Gabriel apresentou boa evolução. Bruno precisa de acompanhamento. Também observei dificuldade de concentração. Rodrigo participa das atividades.';
        $saved=$this->service->saveClass(1,$classId,$second,2,3,'PROFESSOR','127.0.0.1','test');
        self::assertSame(3,$saved['version']);
        self::assertSame($second,(string)$this->db->query("SELECT conteudo FROM documento_turmas WHERE id=$classId")->fetchColumn());
        self::assertLessThan(strpos($second,'Rodrigo'),strpos($second,'Também observei'));

        $third=str_replace('boa evolução','excelente evolução',$second);
        $saved=$this->service->saveClass(1,$classId,$third,3,2,'PROFESSOR','127.0.0.1','test',[['start'=>mb_strpos($second,'boa'),'delete'=>3,'insert'=>'excelente']]);
        self::assertSame(4,$saved['version']);
        self::assertSame($third,(string)$this->db->query("SELECT conteudo FROM documento_turmas WHERE id=$classId")->fetchColumn());

        $history=$this->db->query("SELECT autor_usuario_id,texto_inserido FROM documento_turma_edicoes WHERE documento_turma_id=$classId ORDER BY id")->fetchAll();
        self::assertSame(2,(int)$history[0]['autor_usuario_id']);
        self::assertSame($first,$history[0]['texto_inserido']);
        self::assertSame(3,(int)$history[1]['autor_usuario_id']);
        self::assertSame('Também observei dificuldade de concentração. ',$history[1]['texto_inserido']);
        self::assertSame(2,(int)$history[2]['autor_usuario_id']);
        self::assertSame('excelente',$history[2]['texto_inserido']);
    }

    public function testProfessorNaoPodeApagarTrechoEscritoPorOutroProfessor(): void
    {
        $classId=$this->classId(10);
        $original='Gabriel evoluiu. Bruno precisa de apoio.';
        $this->service->saveClass(1,$classId,$original,1,2,'PROFESSOR','127.0.0.1','test');
        $start=(int)mb_strpos($original,'Bruno');$length=mb_strlen('Bruno precisa de apoio.');
        try{$this->service->saveClass(1,$classId,'Gabriel evoluiu. ',2,3,'PROFESSOR','127.0.0.1','test',[['start'=>$start,'delete'=>$length,'insert'=>'']]);self::fail('Um professor não deveria apagar o trecho de outro.');}
        catch(HttpException$exception){self::assertSame(422,$exception->status);self::assertSame('FOREIGN_TEXT_PROTECTED',$exception->errorCode);}
        self::assertSame($original,(string)$this->db->query("SELECT conteudo FROM documento_turmas WHERE id=$classId")->fetchColumn());
    }

    public function testConflitoImpedeSobrescreverAtualizacaoDeOutraSessao(): void
    {
        $classId=$this->classId(10);
        $this->service->saveClass(1,$classId,'Primeiro texto.',1,2,'PROFESSOR','127.0.0.1','test');
        try{$this->service->saveClass(1,$classId,'Texto de uma tela antiga.',1,3,'PROFESSOR','127.0.0.1','test');self::fail('A versão antiga não deveria ser aceita.');}
        catch(HttpException$exception){self::assertSame(409,$exception->status);}
    }

    public function testBloqueioTemporarioImpedeDoisUsuariosDeEditaremAMesmaTurma(): void
    {
        $classId=$this->classId(10);
        $first=$this->service->acquireClassLock(1,$classId,2,'PROFESSOR');
        self::assertTrue($first['acquired']);self::assertNotEmpty($first['token']);
        $second=$this->service->acquireClassLock(1,$classId,3,'PROFESSOR');
        self::assertFalse($second['acquired']);self::assertSame('Professor Um',$second['locked_by']);
        try{$this->service->saveClass(1,$classId,'Texto.',1,2,'PROFESSOR','127.0.0.1','test',[],'token-incorreto');self::fail('Um token inválido não deveria salvar.');}
        catch(HttpException$exception){self::assertSame(423,$exception->status);}
        $this->service->saveClass(1,$classId,'Texto.',1,2,'PROFESSOR','127.0.0.1','test',[],$first['token']);
        $this->service->releaseClassLock(1,$classId,2,$first['token']);
        $released=$this->service->acquireClassLock(1,$classId,3,'PROFESSOR');
        self::assertTrue($released['acquired']);
        $this->db->exec("UPDATE documento_turma_bloqueios SET expira_em='2000-01-01 00:00:00' WHERE documento_turma_id=$classId");
        $afterExpiration=$this->service->acquireClassLock(1,$classId,2,'PROFESSOR');
        self::assertTrue($afterExpiration['acquired']);
    }

    public function testCoordenacaoEAdministracaoPodemEscreverEmQualquerTurma(): void
    {
        $classId=$this->classId(20);
        $coordLock=$this->service->acquireClassLock(1,$classId,1,'COORDENADOR');
        $this->service->saveClass(1,$classId,'Registro da coordenação.',1,1,'COORDENADOR','127.0.0.1','test',[],$coordLock['token']);
        $this->service->releaseClassLock(1,$classId,1,$coordLock['token']);
        $adminLock=$this->service->acquireClassLock(1,$classId,4,'ADMIN');
        $replacement='Registro revisado pela administração.';
        $this->service->saveClass(1,$classId,$replacement,2,4,'ADMIN','127.0.0.1','test',[['start'=>0,'delete'=>mb_strlen('Registro da coordenação.'),'insert'=>$replacement]],$adminLock['token']);
        self::assertSame($replacement,(string)$this->db->query("SELECT conteudo FROM documento_turmas WHERE id=$classId")->fetchColumn());
        self::assertSame([4],array_map('intval',$this->db->query("SELECT DISTINCT autor_usuario_id FROM documento_turma_segmentos WHERE documento_turma_id=$classId ORDER BY autor_usuario_id")->fetchAll(PDO::FETCH_COLUMN)));
    }

    public function testFinalizacaoEIndividualPorProfessorETurma(): void
    {
        $classId=$this->classId(10);
        $this->service->saveClass(1,$classId,'Registro coletivo iniciado.',1,2,'PROFESSOR','127.0.0.1','test');
        $this->service->finalizeClass(1,$classId,2,'PROFESSOR',true,'127.0.0.1','test');
        $rows=$this->db->query("SELECT professor_usuario_id,finalizado FROM documento_turma_professores WHERE documento_turma_id=$classId ORDER BY professor_usuario_id")->fetchAll();
        self::assertSame([['professor_usuario_id'=>2,'finalizado'=>1],['professor_usuario_id'=>3,'finalizado'=>0]],$rows);
        self::assertSame(1,(int)$this->db->query("SELECT COUNT(*) FROM auditoria WHERE acao='FINALIZAR_TURMA'")->fetchColumn());
        try{$this->service->finalizeClass(1,$classId,2,'PROFESSOR',false,'127.0.0.1','test');self::fail('O próprio professor não deveria reabrir a participação.');}
        catch(HttpException$exception){self::assertSame(403,$exception->status);}
        try{$this->service->saveClass(1,$classId,'Registro coletivo concluído.',2,2,'PROFESSOR','127.0.0.1','test',[['start'=>18,'delete'=>8,'insert'=>'concluído']]);self::fail('A edição deveria permanecer bloqueada após finalizar.');}
        catch(HttpException$exception){self::assertSame(422,$exception->status);}
        $this->service->reopenParticipation(1,$classId,2,1,'COORDENADOR','127.0.0.1','test');
        $this->service->saveClass(1,$classId,'Registro coletivo concluído.',2,2,'PROFESSOR','127.0.0.1','test',[['start'=>18,'delete'=>8,'insert'=>'concluído']]);
        self::assertSame('Registro coletivo concluído.',(string)$this->db->query("SELECT conteudo FROM documento_turmas WHERE id=$classId")->fetchColumn());
        self::assertSame(1,(int)$this->db->query("SELECT COUNT(*) FROM auditoria WHERE acao='LIBERAR_REEDICAO_TURMA'")->fetchColumn());
    }

    public function testProfessorNaoEditaTurmaEmQueNaoLeciona(): void
    {
        $this->expectException(HttpException::class);
        $this->service->saveClass(1,$this->classId(20),'Tentativa',1,3,'PROFESSOR','127.0.0.1','test');
    }

    public function testTelaTemUmUnicoTextoLivrePorTurmaVinculadaEBlocosRecolhidos(): void
    {
        $classId=$this->classId(10);
        $this->service->saveClass(1,$classId,'Bruno precisa de acompanhamento.',1,2,'PROFESSOR','127.0.0.1','test');
        $_SESSION['user']=['id'=>3,'nome'=>'Professor Dois','perfil'=>'PROFESSOR'];$_SERVER['REQUEST_URI']='/documentos/1';
        $view=new View(dirname(__DIR__).'/apps/preconselho-web/resources/views');
        $html=$view->render('document',['document'=>$this->service->document(1,3,'PROFESSOR'),'period'=>1,'title'=>'Documento coletivo']);
        self::assertStringContainsString('1º Ano - Ensino Fundamental',$html);
        self::assertStringContainsString('2º Ano - Ensino Fundamental',$html);
        self::assertSame(1,substr_count($html,'data-shared-content'));
        self::assertStringContainsString('Texto coletivo da turma',$html);
        self::assertStringContainsString('>Finalizar turma</button>',$html);
        self::assertStringContainsString('Autoria dos trechos atuais',$html);
        self::assertStringContainsString('<details id="turma-',$html);
        self::assertStringNotContainsString('<details id="turma-1" open',$html);
        self::assertStringNotContainsString('Adicionar outro aluno',$html);
        self::assertStringNotContainsString('data-student-group',$html);
    }

    public function testAdministracaoVeTextoFinalEHistoricoDeAutoriaSemEditarTurma(): void
    {
        $classId=$this->classId(10);
        $this->service->saveClass(1,$classId,'Os estudantes avançaram nas aprendizagens.',1,2,'PROFESSOR','127.0.0.1','test');
        $_SESSION['user']=['id'=>1,'nome'=>'Administração','perfil'=>'ADMIN'];$_SERVER['REQUEST_URI']='/documentos/1';
        $view=new View(dirname(__DIR__).'/apps/preconselho-web/resources/views');
        $html=$view->render('document',['document'=>$this->service->document(1,1,'ADMIN'),'period'=>1,'title'=>'Documento coletivo']);
        self::assertStringContainsString('data-opening-content',$html);
        self::assertStringContainsString('1º Ano - Ensino Fundamental: Os estudantes avançaram nas aprendizagens.',$html);
        self::assertStringContainsString('Professor Um',$html);
        self::assertStringContainsString('Autoria dos trechos atuais',$html);
        self::assertStringContainsString('Os estudantes avançaram nas aprendizagens.',$html);
        self::assertSame(2,substr_count($html,'data-shared-content'));
        self::assertStringContainsString('Você pode escrever nesta turma sem precisar de vínculo.',$html);
        self::assertSame(1,substr_count($html,'data-final-narrative'));
    }

    public function testPaineisResumemFinalizacoesDoProfessorEDaCoordenacao(): void
    {
        $classId=$this->classId(10);
        $this->service->saveClass(1,$classId,'Registro coletivo.',1,2,'PROFESSOR','127.0.0.1','test');
        $this->service->finalizeClass(1,$classId,2,'PROFESSOR',true,'127.0.0.1','test');
        $teacher=$this->service->summaries(2,'PROFESSOR')[0];$coordination=$this->service->summaries(1,'COORDENADOR')[0];
        self::assertSame(2,(int)$teacher['minhas_turmas']);self::assertSame(1,(int)$teacher['finalizadas']);
        self::assertSame(3,(int)$coordination['contribuicoes']);self::assertSame(1,(int)$coordination['finalizadas']);
    }

    private function classId(int $externalId): int
    {
        $statement=$this->db->prepare('SELECT id FROM documento_turmas WHERE turma_externa_id=:id');$statement->execute([':id'=>$externalId]);return(int)$statement->fetchColumn();
    }
}
