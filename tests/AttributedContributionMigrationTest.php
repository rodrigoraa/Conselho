<?php declare(strict_types=1);

namespace Tests;

use PDO;
use PHPUnit\Framework\TestCase;

final class AttributedContributionMigrationTest extends TestCase
{
    public function testPreservaTextoAnteriorSemAtribuirAutoriaIncorreta(): void
    {
        $db=new PDO('sqlite::memory:');$db->setAttribute(PDO::ATTR_ERRMODE,PDO::ERRMODE_EXCEPTION);$db->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE,PDO::FETCH_ASSOC);
        $base=dirname(__DIR__).'/apps/preconselho-web/database/migrations/';$migrations=glob($base.'*.sql')?:[];sort($migrations);
        foreach($migrations as$migration){if(basename($migration)==='013_attributed_contributions.sql')break;$db->exec((string)file_get_contents($migration));}
        $hash=password_hash('interno',PASSWORD_DEFAULT);
        $db->exec("INSERT INTO usuarios(id,nome,email,senha_hash,perfil)VALUES(1,'Admin','a@test','$hash','ADMIN');INSERT INTO periodos_pre_conselho(id,nome,ano_letivo,etapa,data_inicio,data_fim,status,criado_por,turno)VALUES(1,'Bimestre',2026,'1º','2026-01-01','2026-12-31','ABERTO',1,'MATUTINO');INSERT INTO documento_turmas(id,periodo_id,turma_externa_id,turma_nome_snapshot,turma_ano_letivo_snapshot,conteudo)VALUES(1,1,10,'7º A',2026,'Texto coletivo da versão anterior.')");
        $db->exec((string)file_get_contents($base.'013_attributed_contributions.sql'));
        $contribution=$db->query('SELECT * FROM documento_turma_contribuicoes')->fetch();
        self::assertNull($contribution['professor_usuario_id']);
        self::assertSame('Conteúdo anterior à identificação individual',$contribution['autor_nome_snapshot']);
        self::assertSame('Texto coletivo da versão anterior.',$contribution['conteudo']);
    }
}
