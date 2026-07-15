<?php declare(strict_types=1);
namespace Tests;

use PDO;
use PHPUnit\Framework\TestCase;

final class WorkflowMigrationTest extends TestCase
{
    public function testMigrationConsolidaCamposAntigosSemApagarAlunos():void
    {
        $db=new PDO('sqlite::memory:');
        $db->setAttribute(PDO::ATTR_ERRMODE,PDO::ERRMODE_EXCEPTION);
        $base=dirname(__DIR__).'/apps/preconselho-web/database/migrations/';
        foreach(glob($base.'*.sql')?:[] as$file){if(basename($file)==='008_class_level_pedagogical_fields.sql')break;$db->exec((string)file_get_contents($file));}
        $hash=password_hash('senha-segura',PASSWORD_DEFAULT);
        $db->exec("INSERT INTO usuarios(id,nome,email,senha_hash,perfil)VALUES(1,'Admin','a@test','$hash','ADMIN'),(2,'Professor','p@test','$hash','PROFESSOR');INSERT INTO professores(id,usuario_id)VALUES(1,2);INSERT INTO disciplinas(id,nome)VALUES(1,'Matemática');INSERT INTO vinculos_professor_turma_disciplina(id,professor_id,turma_externa_id,turma_nome_snapshot,turma_ano_letivo_snapshot,disciplina_id)VALUES(1,1,10,'7 A',2025,1);INSERT INTO periodos_pre_conselho(id,nome,ano_letivo,etapa,data_inicio,data_fim,status,criado_por)VALUES(1,'Bimestre',2026,'1º','2026-01-01','2026-12-31','ABERTO',1);INSERT INTO relatorios_pre_conselho(id,periodo_id,vinculo_id)VALUES(1,1,1);INSERT INTO relatorio_alunos(relatorio_id,aluno_externo_id,aluno_nome_snapshot,aluno_data_nascimento_snapshot,turma_externa_id,nota,motivo_rav,dificuldades,intervencoes)VALUES(1,5,'Aluno 5','2013-01-01',10,6,'','Leitura e interpretação','Aplicação de atividades de reforço')");

        $db->exec((string)file_get_contents($base.'008_class_level_pedagogical_fields.sql'));
        $report=$db->query('SELECT * FROM relatorios_pre_conselho WHERE id=1')->fetch(PDO::FETCH_ASSOC);
        self::assertSame('Leitura e interpretação',$report['dificuldades_gerais']);
        self::assertSame('Aplicação de atividades de reforço',$report['medidas_adotadas']);
        self::assertSame(1,(int)$db->query('SELECT COUNT(*) FROM relatorio_alunos')->fetchColumn());
    }
}
