<?php declare(strict_types=1);

namespace Tests;

use PDO;
use PHPUnit\Framework\TestCase;

final class ApcScheduleMigrationTest extends TestCase
{
    public function testMigrationPreservesExistingScheduleAndObligation():void
    {
        $db=new PDO('sqlite::memory:');$db->setAttribute(PDO::ATTR_ERRMODE,PDO::ERRMODE_EXCEPTION);$db->exec('PRAGMA foreign_keys=ON');$directory=dirname(__DIR__).'/apps/apc/database/migrations/';$migrations=glob($directory.'*.sql')?:[];sort($migrations);
        foreach($migrations as$file){if(basename($file)==='009_dia_referencia_e_professor_pendente.sql')break;$db->beginTransaction();$db->exec((string)file_get_contents($file));$db->commit();}
        $db->exec("INSERT INTO apc_eventos(id,ano_letivo,data,titulo,tipo,origem,descricao,status,criado_por)VALUES(1,2026,'2026-10-15','APC','OUTRO','ESCOLA','','ATIVO',1)");
        $db->exec("INSERT INTO apc_horario_importacoes(id,ano_letivo,turno,vigente_de,nome_arquivo_snapshot,sha256,criado_por)VALUES(1,2026,'MATUTINO','2026-01-01','grade.xlsx','".str_repeat('a',64)."',1)");
        $db->exec("INSERT INTO apc_horarios(id,importacao_id,turma_id_externo,turma_nome_snapshot,dia_semana,numero_aula,disciplina,professor_usuario_id,professor_nome_snapshot,professor_nome_importado)VALUES(1,1,10,'7º A',4,1,'Matemática',3,'Docente','Docente')");
        $db->exec("INSERT INTO apc_evento_obrigacoes(id,evento_id,professor_usuario_id,professor_nome_snapshot,turma_id_externo,turma_nome_snapshot,turno,disciplina_snapshot,origem_horario_id)VALUES(1,1,3,'Docente',10,'7º A','MATUTINO','Matemática',1)");
        $db->beginTransaction();$db->exec((string)file_get_contents($directory.'009_dia_referencia_e_professor_pendente.sql'));$db->commit();
        self::assertSame('1',$db->query('SELECT CAST(origem_horario_id AS TEXT) FROM apc_evento_obrigacoes WHERE id=1')->fetchColumn());self::assertSame('[]',json_encode($db->query('PRAGMA foreign_key_check')->fetchAll(PDO::FETCH_ASSOC)));
        $db->exec("INSERT INTO apc_horarios(importacao_id,turma_id_externo,turma_nome_snapshot,dia_semana,numero_aula,disciplina,professor_usuario_id,professor_nome_snapshot,professor_nome_importado)VALUES(1,10,'7º A',4,2,'Química',NULL,NULL,'---')");self::assertSame(1,(int)$db->query('SELECT COUNT(*) FROM apc_horarios WHERE professor_usuario_id IS NULL')->fetchColumn());
    }
}
