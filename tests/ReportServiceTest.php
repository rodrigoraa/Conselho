<?php declare(strict_types=1);
namespace Tests;

use PDO;
use PHPUnit\Framework\TestCase;
use PreConselho\Integration\SecretariaApiClient;
use PreConselho\Repositories\AppRepository;
use PreConselho\Services\ReportService;
use Shared\Exceptions\HttpException;

final class ReportServiceTest extends TestCase
{
    private PDO $db; private ReportService $service;
    protected function setUp(): void
    {
        $this->db=new PDO('sqlite::memory:');$this->db->setAttribute(PDO::ATTR_ERRMODE,PDO::ERRMODE_EXCEPTION);$this->db->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE,PDO::FETCH_ASSOC);foreach(glob(dirname(__DIR__).'/apps/preconselho-web/database/migrations/*.sql')?:[] as$migration)$this->db->exec((string)file_get_contents($migration));
        $hash=password_hash('senha-segura',PASSWORD_DEFAULT);$this->db->exec("INSERT INTO usuarios(id,nome,email,senha_hash,perfil)VALUES(1,'Admin','a@test','$hash','ADMIN'),(2,'Professor','p@test','$hash','PROFESSOR');INSERT INTO professores(id,usuario_id)VALUES(1,2);INSERT INTO disciplinas(id,nome)VALUES(1,'Matemática');INSERT INTO vinculos_professor_turma_disciplina(id,professor_id,turma_externa_id,turma_nome_snapshot,turma_ano_letivo_snapshot,disciplina_id)VALUES(1,1,10,'7 A',2026,1);INSERT INTO periodos_pre_conselho(id,nome,ano_letivo,etapa,data_inicio,data_fim,status,criado_por)VALUES(1,'Bimestre',2026,'1º','2020-01-01','2099-12-31','ABERTO',1);INSERT INTO relatorios_pre_conselho(id,periodo_id,vinculo_id)VALUES(1,1,1)");
        $api=new class extends SecretariaApiClient{public function aluno(int$id):array{return['id'=>$id,'nome_completo'=>'Aluno '.$id,'data_nascimento'=>'2013-01-01','id_turma'=>$id===99?20:10];}};
        $this->service=new ReportService(new AppRepository($this->db),$api);
    }

    public function testCamposDeAlunoSemMarcacaoSaoIgnorados():void
    {$this->service->save(1,['versao'=>1,'possui_alunos_rav'=>'0','alunos'=>[5=>['motivo_rav'=>'não marcado']]],2,'PROFESSOR',false,'127.0.0.1','test');self::assertSame(0,(int)$this->db->query('SELECT COUNT(*) FROM relatorio_alunos')->fetchColumn());}

    public function testAlunoDeOutraTurmaEhRecusado():void
    {$this->expectException(HttpException::class);$this->service->save(1,['versao'=>1,'possui_alunos_rav'=>'1','alunos'=>[99=>['selecionado'=>'1','motivo_rav'=>'x','dificuldades'=>'x','intervencoes'=>'x']]],2,'PROFESSOR',true,'127.0.0.1','test');}

    public function testAprovacaoGeraHistoricoEAuditoria():void
    {$this->db->exec("UPDATE relatorios_pre_conselho SET status='ENVIADO' WHERE id=1");$this->service->review(1,true,'','Parecer',1,'127.0.0.1','test');self::assertSame('APROVADO',$this->db->query('SELECT status FROM relatorios_pre_conselho WHERE id=1')->fetchColumn());self::assertSame(1,(int)$this->db->query('SELECT COUNT(*) FROM historico_status_relatorio')->fetchColumn());self::assertSame(1,(int)$this->db->query('SELECT COUNT(*) FROM auditoria')->fetchColumn());}

    public function testReaberturaExigeJustificativa():void
    {$this->db->exec("UPDATE relatorios_pre_conselho SET status='APROVADO' WHERE id=1");$this->expectException(HttpException::class);$this->service->reopen(1,'',1,'127.0.0.1','test');}
}
