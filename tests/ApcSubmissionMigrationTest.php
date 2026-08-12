<?php declare(strict_types=1);

namespace Tests;

use PDO;
use PHPUnit\Framework\TestCase;

final class ApcSubmissionMigrationTest extends TestCase
{
    public function testClassMigrationPreservesLegacyFileAndAllowsOneSubmissionPerClass():void
    {
        $db=new PDO('sqlite::memory:');$db->setAttribute(PDO::ATTR_ERRMODE,PDO::ERRMODE_EXCEPTION);$db->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE,PDO::FETCH_ASSOC);$db->exec('PRAGMA foreign_keys=ON');
        $directory=dirname(__DIR__).'/apps/apc/database/migrations';
        foreach(['001_initial.sql','002_curriculo_estruturado.sql','003_calendario_escolar_importacao.sql','004_liberacao_coordenacao.sql','005_envio_simplificado.sql']as$file)$db->exec((string)file_get_contents($directory.'/'.$file));

        $db->exec("INSERT INTO apc_eventos(id,ano_letivo,data,titulo,tipo,origem,descricao,status,criado_por)VALUES(1,2026,'2026-08-15','APC','OUTRO','ESCOLA','','ATIVO',1);INSERT INTO apc_envios(id,evento_id,bimestre_id,professor_usuario_id,professor_nome_snapshot,etapa,ano_serie,nome_original,nome_armazenado,mime_type,tamanho_bytes,sha256,caminho_relativo,atrasado,dias_atraso,enviado_em)VALUES(1,1,3,10,'Professor','EF_AF','EF7','antiga.pdf','aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa.pdf','application/pdf',100,'".str_repeat('a',64)."','envios/2026/08/aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa.pdf',0,0,'2026-08-11 10:00:00');INSERT INTO apc_envio_turmas(envio_id,turma_id_externo,turma_nome_snapshot)VALUES(1,70,'7º A'),(1,71,'7º B')");

        $db->beginTransaction();$db->exec((string)file_get_contents($directory.'/006_envio_por_turma.sql'));$db->commit();

        $legacy=$db->query('SELECT turma_id_externo,nome_original FROM apc_envios WHERE id=1')->fetch();
        self::assertNull($legacy['turma_id_externo']);self::assertSame('antiga.pdf',$legacy['nome_original']);self::assertSame(2,(int)$db->query('SELECT COUNT(*) FROM apc_envio_turmas WHERE envio_id=1')->fetchColumn());self::assertSame([],$db->query('PRAGMA foreign_key_check')->fetchAll());

        $insert=$db->prepare('INSERT INTO apc_envios(evento_id,bimestre_id,professor_usuario_id,professor_nome_snapshot,etapa,ano_serie,turma_id_externo,nome_original,nome_armazenado,mime_type,tamanho_bytes,sha256,caminho_relativo,atrasado,dias_atraso,enviado_em)VALUES(1,3,10,\'Professor\',\'EF_AF\',\'EF7\',:turma,:original,:armazenado,\'application/pdf\',100,:sha,:caminho,0,0,\'2026-08-11 11:00:00\')');
        foreach([72,73]as$classId)$insert->execute([':turma'=>$classId,':original'=>'turma-'.$classId.'.pdf',':armazenado'=>str_pad((string)$classId,32,'b').'.pdf',':sha'=>str_repeat((string)($classId%10),64),':caminho'=>'envios/2026/08/'.str_pad((string)$classId,32,'b').'.pdf']);
        self::assertSame(3,(int)$db->query('SELECT COUNT(*) FROM apc_envios')->fetchColumn());
    }
}
