<?php declare(strict_types=1);

namespace Tests;

use PDO;
use PHPUnit\Framework\TestCase;
use PreConselho\Repositories\AppRepository;
use PreConselho\Services\CouncilDocumentService;
use Shared\Exceptions\HttpException;

final class CouncilDocumentServiceTest extends TestCase
{
    private PDO $db;
    private CouncilDocumentService $service;

    protected function setUp(): void
    {
        $this->db=new PDO('sqlite::memory:');
        $this->db->setAttribute(PDO::ATTR_ERRMODE,PDO::ERRMODE_EXCEPTION);
        $this->db->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE,PDO::FETCH_ASSOC);
        foreach(glob(dirname(__DIR__).'/apps/preconselho-web/database/migrations/*.sql')?:[] as$migration)$this->db->exec((string)file_get_contents($migration));
        $hash=password_hash('senha-segura',PASSWORD_DEFAULT);
        $this->db->exec("INSERT INTO usuarios(id,nome,email,senha_hash,perfil)VALUES(1,'Coordenação','c@test','$hash','COORDENADOR'),(2,'Professor','p@test','$hash','PROFESSOR');INSERT INTO professores(id,usuario_id)VALUES(1,2);INSERT INTO disciplinas(id,nome)VALUES(1,'Matemática'),(2,'Ciências');INSERT INTO vinculos_professor_turma_disciplina(id,professor_id,turma_externa_id,turma_nome_snapshot,turma_ano_letivo_snapshot,disciplina_id)VALUES(1,1,10,'1º ano',2026,1),(2,1,20,'2º ano',2026,2);INSERT INTO periodos_pre_conselho(id,nome,ano_letivo,etapa,data_inicio,data_fim,status,criado_por)VALUES(1,'3º Bimestre',2026,'3º','2020-01-01','2099-12-31','ABERTO',1);INSERT INTO relatorios_pre_conselho(id,periodo_id,vinculo_id)VALUES(1,1,1),(2,1,2)");
        $this->service=new CouncilDocumentService(new AppRepository($this->db));
    }

    public function testSalvaTodasAsTurmasComoUmDocumento():void
    {
        $versions=$this->service->save(1,2,['relatorios'=>[1=>['versao'=>1,'relato'=>'Relato da primeira turma'],2=>['versao'=>1,'relato'=>'Relato da segunda turma']]],2,'PROFESSOR',false,'127.0.0.1','test');
        self::assertSame([1=>2,2=>2],$versions);
        self::assertSame(['RASCUNHO','RASCUNHO'],$this->db->query('SELECT status FROM relatorios_pre_conselho ORDER BY id')->fetchAll(PDO::FETCH_COLUMN));
        self::assertSame(['Relato da primeira turma','Relato da segunda turma'],$this->db->query('SELECT observacoes_professor FROM relatorios_pre_conselho ORDER BY id')->fetchAll(PDO::FETCH_COLUMN));
        self::assertSame(1,(int)$this->db->query("SELECT COUNT(*) FROM auditoria WHERE acao='SALVAR_DOCUMENTO'")->fetchColumn());
    }

    public function testEnvioExigeRelatoDeTodasAsTurmas():void
    {
        $this->expectException(HttpException::class);
        $this->service->save(1,2,['relatorios'=>[1=>['versao'=>1,'relato'=>'Preenchido'],2=>['versao'=>1,'relato'=>'']]],2,'PROFESSOR',true,'127.0.0.1','test');
    }

    public function testEnvioEAprovacaoSaoAplicadosAoDocumentoInteiro():void
    {
        $this->service->save(1,2,['relatorios'=>[1=>['versao'=>1,'relato'=>'Relato 1'],2=>['versao'=>1,'relato'=>'Relato 2']]],2,'PROFESSOR',true,'127.0.0.1','test');
        self::assertSame(['ENVIADO','ENVIADO'],$this->db->query('SELECT status FROM relatorios_pre_conselho ORDER BY id')->fetchAll(PDO::FETCH_COLUMN));
        $this->service->review(1,2,true,'','Documento adequado',1,'127.0.0.1','test');
        self::assertSame(['APROVADO','APROVADO'],$this->db->query('SELECT status FROM relatorios_pre_conselho ORDER BY id')->fetchAll(PDO::FETCH_COLUMN));
        self::assertSame(2,(int)$this->db->query("SELECT COUNT(*) FROM historico_status_relatorio WHERE status_novo='APROVADO'")->fetchColumn());
        self::assertSame(1,(int)$this->db->query("SELECT COUNT(*) FROM auditoria WHERE acao='APROVADO_DOCUMENTO'")->fetchColumn());
    }

    public function testProfessorNaoAcessaDocumentoDeOutroProfessor():void
    {
        $this->expectException(HttpException::class);
        $this->service->allowed(1,2,99,'PROFESSOR');
    }
}
