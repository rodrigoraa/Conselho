<?php declare(strict_types=1);

namespace Tests;

use PDO;
use PHPUnit\Framework\TestCase;

abstract class ApcTestCase extends TestCase
{
    protected function mainDatabase(): PDO
    {
        $db=$this->database();$files=glob(dirname(__DIR__).'/apps/preconselho-web/database/migrations/*.sql')?:[];sort($files);foreach($files as$file)$db->exec((string)file_get_contents($file));return$db;
    }

    protected function apcDatabase(): PDO
    {
        $db=$this->database();$files=glob(dirname(__DIR__).'/apps/apc/database/migrations/*.sql')?:[];sort($files);foreach($files as$file)$db->exec((string)file_get_contents($file));return$db;
    }

    protected function seedMain(PDO $db): void
    {
        $hash=password_hash('interno',PASSWORD_DEFAULT);
        $statement=$db->prepare("INSERT INTO usuarios(id,nome,email,cpf,senha_hash,perfil)VALUES(1,'Admin','admin@test','52998224725',?,'ADMIN'),(2,'Coordenação','coord@test','11144477735',?,'COORDENADOR'),(3,'Professor Um','p1@test','12345678909',?,'PROFESSOR'),(4,'Professor Dois','p2@test','93541134780',?,'PROFESSOR')");$statement->execute([$hash,$hash,$hash,$hash]);
        $db->exec("INSERT INTO professores(id,usuario_id)VALUES(1,3),(2,4);INSERT INTO vinculos_professor_turma(id,professor_id,turma_externa_id,turma_nome_snapshot,turma_ano_letivo_snapshot,turno)VALUES(1,1,10,'7º A',2026,'MATUTINO'),(2,2,20,'8º A',2026,'MATUTINO')");
    }

    protected function seedEvent(PDO $db): void
    {
        $db->exec("INSERT INTO apc_eventos(id,ano_letivo,data,titulo,tipo,origem,descricao,status,criado_por)VALUES(1,2026,'2026-08-15','APC de agosto','JORNADA_FORMATIVA','ESCOLA','Evento de teste','ATIVO',1)");
    }

    private function database(): PDO
    {
        $db=new PDO('sqlite::memory:');$db->setAttribute(PDO::ATTR_ERRMODE,PDO::ERRMODE_EXCEPTION);$db->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE,PDO::FETCH_ASSOC);$db->exec('PRAGMA foreign_keys=ON');return$db;
    }
}
