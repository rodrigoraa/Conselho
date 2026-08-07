<?php declare(strict_types=1);

namespace Tests;

use PDO;
use PHPUnit\Framework\TestCase;

final class OwnedTextSegmentsMigrationTest extends TestCase
{
    public function testPreservaAutorQuandoOHistoricoIdentificaTodoOTextoExistente(): void
    {
        $db=new PDO('sqlite::memory:');$db->setAttribute(PDO::ATTR_ERRMODE,PDO::ERRMODE_EXCEPTION);$db->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE,PDO::FETCH_ASSOC);
        $db->exec("CREATE TABLE usuarios(id INTEGER PRIMARY KEY);CREATE TABLE documento_turmas(id INTEGER PRIMARY KEY,conteudo TEXT NOT NULL);CREATE TABLE documento_turma_edicoes(id INTEGER PRIMARY KEY,documento_turma_id INTEGER NOT NULL,autor_usuario_id INTEGER,autor_nome_snapshot TEXT NOT NULL,texto_inserido TEXT NOT NULL);INSERT INTO usuarios VALUES(2);INSERT INTO documento_turmas VALUES(1,'Texto do professor.');INSERT INTO documento_turma_edicoes VALUES(1,1,2,'Professor Um','Texto do professor.');");
        $db->exec((string)file_get_contents(dirname(__DIR__).'/apps/preconselho-web/database/migrations/016_owned_text_segments.sql'));
        $segment=$db->query('SELECT * FROM documento_turma_segmentos')->fetch();
        self::assertSame(2,(int)$segment['autor_usuario_id']);self::assertSame('Professor Um',$segment['autor_nome_snapshot']);self::assertSame('Texto do professor.',$segment['conteudo']);
    }
}
