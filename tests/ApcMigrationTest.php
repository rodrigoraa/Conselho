<?php declare(strict_types=1);

namespace Tests;

use PDOException;

final class ApcMigrationTest extends ApcTestCase
{
    public function testCreatesApcSchemaInSeparateDatabaseWithExpectedIndexesAndParameters(): void
    {
        $db=$this->apcDatabase();$tables=$db->query("SELECT name FROM sqlite_master WHERE type='table' AND name LIKE 'apc_%' ORDER BY name")->fetchAll(\PDO::FETCH_COLUMN);
        self::assertSame(['apc_anexos','apc_auditoria','apc_componentes_curriculares','apc_entregas','apc_eventos','apc_habilidade_anos_series','apc_habilidades_curriculares','apc_parametros','apc_plano_componentes','apc_plano_habilidades','apc_planos'],$tables);self::assertSame(3,(int)$db->query('SELECT COUNT(*) FROM apc_parametros')->fetchColumn());
    }

    public function testUniqueConstraintsPreventDuplicatePlanAndStudentDelivery(): void
    {
        $db=$this->apcDatabase();$this->seedEvent($db);$db->exec("INSERT INTO apc_planos(id,evento_id,professor_usuario_id,professor_nome_snapshot,turma_id_externo,turma_nome_snapshot,componente_curricular)VALUES(1,1,3,'Professor',10,'7º A','Matemática');INSERT INTO apc_entregas(plano_id,aluno_id_externo,aluno_nome_snapshot)VALUES(1,100,'Aluno')");
        try{$db->exec("INSERT INTO apc_planos(evento_id,professor_usuario_id,professor_nome_snapshot,turma_id_externo,turma_nome_snapshot,componente_curricular)VALUES(1,3,'Professor',10,'7º A','matemática')");self::fail('Plano duplicado deveria ser recusado sem diferenciar maiúsculas.');}catch(PDOException){self::assertSame(1,(int)$db->query('SELECT COUNT(*) FROM apc_planos')->fetchColumn());}
        try{$db->exec("INSERT INTO apc_entregas(plano_id,aluno_id_externo,aluno_nome_snapshot)VALUES(1,100,'Aluno duplicado')");self::fail('Entrega duplicada deveria ser recusada.');}catch(PDOException){self::assertSame(1,(int)$db->query('SELECT COUNT(*) FROM apc_entregas')->fetchColumn());}
    }
}
