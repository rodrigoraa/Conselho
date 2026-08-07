<?php declare(strict_types=1);

namespace Tests;

use PDO;
use PHPUnit\Framework\TestCase;

final class CollectiveDocumentMigrationTest extends TestCase
{
    public function testMigraRelatosAntigosParaUmaSecaoCompartilhadaSemPerderTexto(): void
    {
        $db=new PDO('sqlite::memory:');$db->setAttribute(PDO::ATTR_ERRMODE,PDO::ERRMODE_EXCEPTION);$db->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE,PDO::FETCH_ASSOC);
        $base=dirname(__DIR__).'/apps/preconselho-web/database/migrations/';
        $files=glob($base.'*.sql')?:[];sort($files);
        foreach($files as$file){if(basename($file)==='010_collective_class_document.sql')break;$db->exec((string)file_get_contents($file));}
        $hash=password_hash('interno',PASSWORD_DEFAULT);
        $db->exec("INSERT INTO usuarios(id,nome,email,senha_hash,perfil)VALUES(1,'Admin','a@test','$hash','ADMIN'),(2,'Professor A','a2@test','$hash','PROFESSOR'),(3,'Professor B','b@test','$hash','PROFESSOR');INSERT INTO professores(id,usuario_id)VALUES(1,2),(2,3);INSERT INTO disciplinas(id,nome)VALUES(1,'Matemática'),(2,'Ciências');INSERT INTO vinculos_professor_turma_disciplina(id,professor_id,turma_externa_id,turma_nome_snapshot,turma_ano_letivo_snapshot,disciplina_id)VALUES(1,1,10,'7º A',2026,1),(2,2,10,'7º A',2026,2);INSERT INTO periodos_pre_conselho(id,nome,ano_letivo,etapa,data_inicio,data_fim,status,criado_por)VALUES(1,'Bimestre',2026,'1º','2026-01-01','2026-12-31','ABERTO',1);INSERT INTO relatorios_pre_conselho(id,periodo_id,vinculo_id,observacoes_professor,status)VALUES(1,1,1,'Relato de Matemática','RASCUNHO'),(2,1,2,'Complemento de Ciências','ENVIADO')");
        $db->exec((string)file_get_contents($base.'010_collective_class_document.sql'));
        self::assertSame(1,(int)$db->query('SELECT COUNT(*) FROM documento_turmas')->fetchColumn());
        $content=(string)$db->query('SELECT conteudo FROM documento_turmas')->fetchColumn();
        self::assertStringContainsString('Relato de Matemática',$content);
        self::assertStringContainsString('Complemento de Ciências',$content);
        self::assertSame(2,(int)$db->query('SELECT COUNT(*) FROM documento_turma_professores')->fetchColumn());
    }
}
