<?php declare(strict_types=1);

namespace Tests;

use PDO;
use PHPUnit\Framework\TestCase;
use PreConselho\Controllers\BindingController;
use PreConselho\Integration\SecretariaApiClient;
use PreConselho\Repositories\AppRepository;
use PreConselho\Support\Csrf;
use Shared\Http\Request;

final class BindingControllerTest extends TestCase
{
    protected function tearDown(): void
    {
        unset($_SESSION['user'],$_SESSION['_csrf'],$_SESSION['flash']);
    }

    public function testCreatesSelectedClassesInMorningAndAfternoonAtOnce(): void
    {
        $db=new PDO('sqlite::memory:');$db->setAttribute(PDO::ATTR_ERRMODE,PDO::ERRMODE_EXCEPTION);$db->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE,PDO::FETCH_ASSOC);
        $migrations=glob(dirname(__DIR__).'/apps/preconselho-web/database/migrations/*.sql')?:[];sort($migrations);foreach($migrations as$migration)$db->exec((string)file_get_contents($migration));
        $hash=password_hash('interno',PASSWORD_DEFAULT);$db->exec("INSERT INTO usuarios(id,nome,email,senha_hash,perfil)VALUES(1,'Admin','a@test','$hash','ADMIN'),(2,'Professor','p@test','$hash','PROFESSOR');INSERT INTO professores(id,usuario_id)VALUES(1,2)");
        $api=new class extends SecretariaApiClient{public function turma(int$id):array{return['id'=>$id,'nome_turma'=>$id===10?'1º Ano':'2º Ano','ano_letivo'=>2026];}};
        $_SESSION['user']=['id'=>1,'perfil'=>'ADMIN'];$csrf=Csrf::token();
        $request=new Request('POST','/admin/vinculos',[],['_csrf'=>$csrf,'professor_id'=>'2','turnos'=>['MATUTINO','VESPERTINO'],'turma_ids'=>['10','20']],['REMOTE_ADDR'=>'127.0.0.1']);
        $response=(new BindingController(new AppRepository($db),$api))->create($request);
        self::assertSame(302,$response->status);self::assertSame('/admin#vinculos',$response->headers['Location']);
        self::assertSame(4,(int)$db->query('SELECT COUNT(*) FROM vinculos_professor_turma')->fetchColumn());
        self::assertSame(['MATUTINO','VESPERTINO'],$db->query('SELECT DISTINCT turno FROM vinculos_professor_turma ORDER BY turno')->fetchAll(PDO::FETCH_COLUMN));
        self::assertSame(4,(int)$db->query("SELECT COUNT(*) FROM auditoria WHERE acao='CRIAR' AND entidade='vinculos_professor_turma'")->fetchColumn());
    }
}
