<?php declare(strict_types=1);
namespace Tests;

use PDO;
use PHPUnit\Framework\TestCase;
use PreConselho\Repositories\AppRepository;
use PreConselho\Services\AuthService;
use Shared\Exceptions\HttpException;

final class AuthServiceTest extends TestCase
{
    private function database(): PDO
    {
        $db=new PDO('sqlite::memory:');
        $db->setAttribute(PDO::ATTR_ERRMODE,PDO::ERRMODE_EXCEPTION);
        $db->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE,PDO::FETCH_ASSOC);
        $files=glob(dirname(__DIR__).'/apps/preconselho-web/database/migrations/*.sql')?:[];sort($files);
        foreach($files as$file)$db->exec((string)file_get_contents($file));
        return $db;
    }

    public function testLoginValidoComCpfFormatado(): void
    {
        $db=$this->database();
        $db->prepare("INSERT INTO usuarios(nome,email,cpf,senha_hash,perfil)VALUES('A','a@a.com','52998224725',?,'ADMIN')")->execute([password_hash('interno',PASSWORD_DEFAULT)]);
        $user=(new AuthService(new AppRepository($db)))->login('529.982.247-25','127.0.0.1','phpunit');
        self::assertSame('ADMIN',$user['perfil']);
    }

    public function testCpfInvalidoNaoAutentica(): void
    {
        $this->expectException(HttpException::class);
        (new AuthService(new AppRepository($this->database())))->login('111.111.111-11','127.0.0.1','phpunit');
    }
}
