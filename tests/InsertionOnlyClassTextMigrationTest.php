<?php declare(strict_types=1);

namespace Tests;

use PDO;
use PHPUnit\Framework\TestCase;

final class InsertionOnlyClassTextMigrationTest extends TestCase
{
    public function testConteudoExistenteEntraNoHistoricoComoLegado(): void
    {
        $db=new PDO('sqlite::memory:');$db->setAttribute(PDO::ATTR_ERRMODE,PDO::ERRMODE_EXCEPTION);$db->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE,PDO::FETCH_ASSOC);
        $db->exec('CREATE TABLE usuarios(id INTEGER PRIMARY KEY);CREATE TABLE documento_turmas(id INTEGER PRIMARY KEY,conteudo TEXT NOT NULL,versao INTEGER NOT NULL);CREATE TABLE documento_turma_contribuicoes(id INTEGER PRIMARY KEY,documento_turma_id INTEGER NOT NULL,professor_usuario_id INTEGER,autor_nome_snapshot TEXT NOT NULL,conteudo TEXT NOT NULL,atualizado_em TEXT NOT NULL);INSERT INTO documento_turmas VALUES(1,\'Texto que já existia.\',7);');
        $db->exec((string)file_get_contents(dirname(__DIR__).'/apps/preconselho-web/database/migrations/015_insertion_only_class_text.sql'));
        $row=$db->query('SELECT * FROM documento_turma_edicoes')->fetch();
        self::assertNull($row['autor_usuario_id']);self::assertSame('Conteúdo anterior ao histórico de autoria',$row['autor_nome_snapshot']);
        self::assertSame('Texto que já existia.',$row['texto_inserido']);self::assertSame(7,(int)$row['versao_resultante']);
    }
}
