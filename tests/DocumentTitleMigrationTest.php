<?php declare(strict_types=1);

namespace Tests;

use PDO;
use PHPUnit\Framework\TestCase;

final class DocumentTitleMigrationTest extends TestCase
{
    public function testMigrationPreservaAberturaExistenteEAdicionaTituloNulo(): void
    {
        $db=new PDO('sqlite::memory:');
        $db->setAttribute(PDO::ATTR_ERRMODE,PDO::ERRMODE_EXCEPTION);
        $db->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE,PDO::FETCH_ASSOC);
        $base=dirname(__DIR__).'/apps/preconselho-web/database/migrations/';
        $files=glob($base.'*.sql')?:[];sort($files);
        foreach($files as$file){if(basename($file)==='019_document_title.sql')break;$db->exec((string)file_get_contents($file));}
        $db->exec("INSERT INTO usuarios(id,nome,email,senha_hash,perfil)VALUES(1,'Coordenação','c@test','hash','COORDENADOR');INSERT INTO periodos_pre_conselho(id,nome,ano_letivo,etapa,data_inicio,data_fim,status,criado_por,turno)VALUES(1,'Bimestre',2026,'1º','2020-01-01','2099-12-31','ABERTO',1,'MATUTINO');INSERT INTO documento_aberturas(periodo_id,texto,versao,atualizado_por,atualizado_em)VALUES(1,'Abertura histórica',5,1,'2026-09-01 12:34:56')");
        $db->exec((string)file_get_contents($base.'019_document_title.sql'));
        $row=$db->query('SELECT texto,versao,atualizado_por,atualizado_em,titulo,titulo_versao FROM documento_aberturas WHERE periodo_id=1')->fetch();
        self::assertSame('Abertura histórica',$row['texto']);
        self::assertSame(5,(int)$row['versao']);
        self::assertSame(1,(int)$row['atualizado_por']);
        self::assertSame('2026-09-01 12:34:56',$row['atualizado_em']);
        self::assertNull($row['titulo']);
        self::assertSame(1,(int)$row['titulo_versao']);
    }
}
