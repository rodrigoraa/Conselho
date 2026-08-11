<?php declare(strict_types=1);

namespace Tests;

use PDO;
use PDOException;

final class ApcMigrationTest extends ApcTestCase
{
    public function testCreatesApcSchemaInSeparateDatabaseWithExpectedIndexesAndParameters(): void
    {
        $db=$this->apcDatabase();$tables=$db->query("SELECT name FROM sqlite_master WHERE type='table' AND name LIKE 'apc_%' ORDER BY name")->fetchAll(\PDO::FETCH_COLUMN);
        self::assertSame(['apc_anexos','apc_auditoria','apc_bimestres','apc_componentes_curriculares','apc_entregas','apc_envio_turmas','apc_envios','apc_eventos','apc_habilidade_anos_series','apc_habilidades_curriculares','apc_parametros','apc_plano_componentes','apc_plano_habilidades','apc_planos'],$tables);self::assertSame(3,(int)$db->query('SELECT COUNT(*) FROM apc_parametros')->fetchColumn());self::assertSame(4,(int)$db->query('SELECT COUNT(*) FROM apc_bimestres WHERE ano_letivo=2026')->fetchColumn());$columns=$db->query('PRAGMA table_info(apc_eventos)')->fetchAll(\PDO::FETCH_ASSOC);self::assertContains('disponibilizado_em',array_column($columns,'name'));self::assertContains('disponibilizado_por',array_column($columns,'name'));
    }

    public function testUniqueConstraintsPreventDuplicatePlanAndStudentDelivery(): void
    {
        $db=$this->apcDatabase();$this->seedEvent($db);$db->exec("INSERT INTO apc_planos(id,evento_id,professor_usuario_id,professor_nome_snapshot,turma_id_externo,turma_nome_snapshot,componente_curricular)VALUES(1,1,3,'Professor',10,'7º A','Matemática');INSERT INTO apc_entregas(plano_id,aluno_id_externo,aluno_nome_snapshot)VALUES(1,100,'Aluno')");
        try{$db->exec("INSERT INTO apc_planos(evento_id,professor_usuario_id,professor_nome_snapshot,turma_id_externo,turma_nome_snapshot,componente_curricular)VALUES(1,3,'Professor',10,'7º A','matemática')");self::fail('Plano duplicado deveria ser recusado sem diferenciar maiúsculas.');}catch(PDOException){self::assertSame(1,(int)$db->query('SELECT COUNT(*) FROM apc_planos')->fetchColumn());}
        try{$db->exec("INSERT INTO apc_entregas(plano_id,aluno_id_externo,aluno_nome_snapshot)VALUES(1,100,'Aluno duplicado')");self::fail('Entrega duplicada deveria ser recusada.');}catch(PDOException){self::assertSame(1,(int)$db->query('SELECT COUNT(*) FROM apc_entregas')->fetchColumn());}
    }

    public function testReleaseMigrationPreservesOnlyEventsAlreadyInProgress():void
    {
        $db=new PDO('sqlite::memory:');$db->setAttribute(PDO::ATTR_ERRMODE,PDO::ERRMODE_EXCEPTION);$files=glob(dirname(__DIR__).'/apps/apc/database/migrations/*.sql')?:[];sort($files);foreach($files as$file){if(basename($file)==='004_liberacao_coordenacao.sql')continue;$db->exec((string)file_get_contents($file));}$db->exec("INSERT INTO apc_eventos(id,ano_letivo,data,titulo,tipo,origem,descricao,status,criado_por)VALUES(1,2026,'2026-08-15','Em andamento','OUTRO','ESCOLA','','ATIVO',1),(2,2026,'2026-09-15','Sem plano','OUTRO','ESCOLA','','ATIVO',1);INSERT INTO apc_planos(evento_id,professor_usuario_id,professor_nome_snapshot,turma_id_externo,turma_nome_snapshot,componente_curricular)VALUES(1,3,'Professor',10,'7º A','Matemática')");$db->exec((string)file_get_contents(dirname(__DIR__).'/apps/apc/database/migrations/004_liberacao_coordenacao.sql'));$rows=$db->query('SELECT id,disponibilizado_em,disponibilizado_por FROM apc_eventos ORDER BY id')->fetchAll(PDO::FETCH_ASSOC);self::assertNotNull($rows[0]['disponibilizado_em']);self::assertSame(1,(int)$rows[0]['disponibilizado_por']);self::assertNull($rows[1]['disponibilizado_em']);self::assertNull($rows[1]['disponibilizado_por']);
    }
}
