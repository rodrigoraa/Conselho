<?php declare(strict_types=1);

namespace Tests;

use PDO;
use PHPUnit\Framework\TestCase;

final class ShiftBindingMigrationTest extends TestCase
{
    public function testConverteDisciplinasEmUmUnicoVinculoDeTurmaMatutino(): void
    {
        $db=new PDO('sqlite::memory:');$db->setAttribute(PDO::ATTR_ERRMODE,PDO::ERRMODE_EXCEPTION);$db->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE,PDO::FETCH_ASSOC);
        $base=dirname(__DIR__).'/apps/preconselho-web/database/migrations/';$migrations=glob($base.'*.sql')?:[];sort($migrations);
        foreach($migrations as$migration){if(basename($migration)==='012_shift_class_bindings.sql')break;$db->exec((string)file_get_contents($migration));}
        $hash=password_hash('interno',PASSWORD_DEFAULT);
        $db->exec("INSERT INTO usuarios(id,nome,email,senha_hash,perfil)VALUES(1,'Admin','a@test','$hash','ADMIN'),(2,'Professor','p@test','$hash','PROFESSOR');INSERT INTO professores(id,usuario_id)VALUES(1,2);INSERT INTO disciplinas(id,nome)VALUES(1,'Matemática'),(2,'Ciências');INSERT INTO vinculos_professor_turma_disciplina(id,professor_id,turma_externa_id,turma_nome_snapshot,turma_ano_letivo_snapshot,disciplina_id)VALUES(1,1,10,'7º A',2026,1),(2,1,10,'7º A',2026,2);INSERT INTO periodos_pre_conselho(id,nome,ano_letivo,etapa,data_inicio,data_fim,status,criado_por)VALUES(1,'Bimestre',2026,'1º','2026-01-01','2026-12-31','RASCUNHO',1)");
        $db->exec((string)file_get_contents($base.'012_shift_class_bindings.sql'));
        self::assertSame(1,(int)$db->query('SELECT COUNT(*) FROM vinculos_professor_turma')->fetchColumn());
        self::assertSame('MATUTINO',$db->query('SELECT turno FROM vinculos_professor_turma')->fetchColumn());
        self::assertSame('MATUTINO',$db->query('SELECT turno FROM periodos_pre_conselho')->fetchColumn());
    }
}
