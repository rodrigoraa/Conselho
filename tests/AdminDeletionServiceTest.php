<?php declare(strict_types=1);

use PHPUnit\Framework\TestCase;
use PreConselho\Middlewares\RoleMiddleware;
use PreConselho\Repositories\AppRepository;
use PreConselho\Services\AdminDeletionService;
use Shared\Exceptions\HttpException;
use Shared\Http\Request;

final class AdminDeletionServiceTest extends TestCase
{
    private PDO $db;
    private AdminDeletionService $service;

    protected function setUp(): void
    {
        $this->db=new PDO('sqlite::memory:');
        $this->db->setAttribute(PDO::ATTR_ERRMODE,PDO::ERRMODE_EXCEPTION);
        $this->db->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE,PDO::FETCH_ASSOC);
        $this->db->exec('PRAGMA foreign_keys=ON');
        $this->db->exec((string)file_get_contents(dirname(__DIR__).'/apps/preconselho-web/database/migrations/001_initial.sql'));
        $hash=password_hash('senha-segura',PASSWORD_DEFAULT);
        $statement=$this->db->prepare("INSERT INTO usuarios(id,nome,email,senha_hash,perfil)VALUES(1,'Administrador','admin@test',?,'ADMIN'),(2,'Professor','prof@test',?,'PROFESSOR')");
        $statement->execute([$hash,$hash]);
        $this->db->exec("INSERT INTO professores(id,usuario_id)VALUES(1,2);
            INSERT INTO disciplinas(id,nome)VALUES(1,'Matemática');
            INSERT INTO vinculos_professor_turma_disciplina(id,professor_id,turma_externa_id,turma_nome_snapshot,turma_ano_letivo_snapshot,disciplina_id)VALUES(1,1,10,'7º A',2026,1);
            INSERT INTO periodos_pre_conselho(id,nome,ano_letivo,etapa,data_inicio,data_fim,status,criado_por)VALUES(1,'1º bimestre',2026,'1º bimestre','2026-01-01','2026-03-31','ABERTO',1);
            INSERT INTO relatorios_pre_conselho(id,periodo_id,vinculo_id)VALUES(1,1,1);
            INSERT INTO relatorio_alunos(relatorio_id,aluno_externo_id,aluno_nome_snapshot,aluno_data_nascimento_snapshot,turma_externa_id,motivo_rav,dificuldades,intervencoes)VALUES(1,20,'Aluno','2013-01-01',10,'','','');
            INSERT INTO historico_status_relatorio(relatorio_id,status_novo,usuario_id)VALUES(1,'PENDENTE',1)");
        $this->service=new AdminDeletionService(new AppRepository($this->db));
    }

    public function testAdminCanDeleteBindingAndAllRelatedReportData(): void
    {
        $this->service->deleteBinding(1,1,'127.0.0.1','phpunit');

        self::assertSame(0,(int)$this->db->query('SELECT COUNT(*) FROM vinculos_professor_turma_disciplina')->fetchColumn());
        self::assertSame(0,(int)$this->db->query('SELECT COUNT(*) FROM relatorios_pre_conselho')->fetchColumn());
        self::assertSame(0,(int)$this->db->query('SELECT COUNT(*) FROM relatorio_alunos')->fetchColumn());
        self::assertSame(0,(int)$this->db->query('SELECT COUNT(*) FROM historico_status_relatorio')->fetchColumn());
        self::assertSame('EXCLUIR',$this->db->query("SELECT acao FROM auditoria WHERE entidade='vinculos_professor_turma_disciplina'")->fetchColumn());
    }

    public function testAdminCanDeletePeriodAndItsReportsWithoutDeletingBinding(): void
    {
        $this->service->deletePeriod(1,1,'127.0.0.1','phpunit');

        self::assertSame(0,(int)$this->db->query('SELECT COUNT(*) FROM periodos_pre_conselho')->fetchColumn());
        self::assertSame(0,(int)$this->db->query('SELECT COUNT(*) FROM relatorios_pre_conselho')->fetchColumn());
        self::assertSame(1,(int)$this->db->query('SELECT COUNT(*) FROM vinculos_professor_turma_disciplina')->fetchColumn());
    }

    public function testAdminCanDeleteSingleReportWithoutDeletingItsPeriodOrBinding(): void
    {
        $this->service->deleteReport(1,1,'127.0.0.1','phpunit');

        self::assertSame(0,(int)$this->db->query('SELECT COUNT(*) FROM relatorios_pre_conselho')->fetchColumn());
        self::assertSame(1,(int)$this->db->query('SELECT COUNT(*) FROM periodos_pre_conselho')->fetchColumn());
        self::assertSame(1,(int)$this->db->query('SELECT COUNT(*) FROM vinculos_professor_turma_disciplina')->fetchColumn());
    }

    public function testAdminCanDeleteDisciplineEvenWhenItHasBindingsAndReports(): void
    {
        $this->service->deleteDiscipline(1,1,'127.0.0.1','phpunit');

        self::assertSame(0,(int)$this->db->query('SELECT COUNT(*) FROM disciplinas')->fetchColumn());
        self::assertSame(0,(int)$this->db->query('SELECT COUNT(*) FROM vinculos_professor_turma_disciplina')->fetchColumn());
        self::assertSame(0,(int)$this->db->query('SELECT COUNT(*) FROM relatorios_pre_conselho')->fetchColumn());
    }

    public function testAdministratorIsAcceptedByAdminOnlyPermission(): void
    {
        $_SESSION['user']=['id'=>1,'perfil'=>'ADMIN'];
        $result=(new RoleMiddleware(['ADMIN']))(new Request('POST','/admin/periodos/1/excluir',[],[],[]),static fn()=>true);
        unset($_SESSION['user']);

        self::assertTrue($result);
    }

    public function testCoordinatorIsRejectedByAdminOnlyPermission(): void
    {
        $_SESSION['user']=['id'=>3,'perfil'=>'COORDENADOR'];
        $middleware=new RoleMiddleware(['ADMIN']);

        try{
            $middleware(new Request('POST','/admin/periodos/1/excluir',[],[],[]),static fn()=>true);
            self::fail('A coordenação não deveria acessar uma ação de exclusão administrativa.');
        }catch(HttpException $exception){
            self::assertSame(403,$exception->status);
            self::assertSame('FORBIDDEN',$exception->errorCode);
        }finally{unset($_SESSION['user']);}
    }
}
