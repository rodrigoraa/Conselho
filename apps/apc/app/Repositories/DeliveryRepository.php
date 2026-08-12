<?php declare(strict_types=1);

namespace Apc\Repositories;

use PDO;

final class DeliveryRepository
{
    public function __construct(public readonly PDO $db) {}

    public function forPlan(int $planId): array
    {
        $statement=$this->db->prepare('SELECT e.*,COUNT(a.id) anexos FROM apc_entregas e LEFT JOIN apc_anexos a ON a.entrega_id=e.id WHERE e.plano_id=:plano GROUP BY e.id ORDER BY e.aluno_nome_snapshot COLLATE NOCASE');
        $statement->execute([':plano'=>$planId]);return$statement->fetchAll();
    }

    public function find(int $id): ?array
    {
        $statement=$this->db->prepare('SELECT e.*,p.professor_usuario_id,p.professor_nome_snapshot,p.turma_id_externo,p.turma_nome_snapshot,p.status plano_status,p.id plano_id,p.evento_id,ev.ano_letivo,ev.titulo evento_titulo FROM apc_entregas e JOIN apc_planos p ON p.id=e.plano_id JOIN apc_eventos ev ON ev.id=p.evento_id WHERE e.id=:id AND p.arquivado_em IS NULL');
        $statement->execute([':id'=>$id]);return$statement->fetch()?:null;
    }

    public function findByStudent(int $planId,int $studentId): ?array
    {
        $statement=$this->db->prepare('SELECT * FROM apc_entregas WHERE plano_id=:plano AND aluno_id_externo=:aluno');$statement->execute([':plano'=>$planId,':aluno'=>$studentId]);return$statement->fetch()?:null;
    }

    public function upsert(array $data): int
    {
        $statement=$this->db->prepare('INSERT INTO apc_entregas(plano_id,aluno_id_externo,aluno_nome_snapshot,entregue,data_entrega,nota,observacao)VALUES(:plano,:aluno,:nome,:entregue,:data,:nota,:observacao) ON CONFLICT(plano_id,aluno_id_externo) DO UPDATE SET aluno_nome_snapshot=excluded.aluno_nome_snapshot,entregue=excluded.entregue,data_entrega=excluded.data_entrega,nota=excluded.nota,observacao=excluded.observacao,atualizado_em=CURRENT_TIMESTAMP');
        $statement->execute([':plano'=>$data['plano_id'],':aluno'=>$data['aluno_id_externo'],':nome'=>$data['aluno_nome_snapshot'],':entregue'=>$data['entregue'],':data'=>$data['data_entrega'],':nota'=>$data['nota'],':observacao'=>$data['observacao']]);
        $existing=$this->findByStudent((int)$data['plano_id'],(int)$data['aluno_id_externo']);return(int)$existing['id'];
    }

    public function attachments(int $deliveryId): array
    {
        $statement=$this->db->prepare('SELECT * FROM apc_anexos WHERE entrega_id=:entrega ORDER BY id');$statement->execute([':entrega'=>$deliveryId]);return$statement->fetchAll();
    }

    public function attachmentsForPlan(int $planId): array
    {
        $statement=$this->db->prepare('SELECT a.* FROM apc_anexos a JOIN apc_entregas e ON e.id=a.entrega_id WHERE e.plano_id=:plano ORDER BY a.id');$statement->execute([':plano'=>$planId]);return$statement->fetchAll();
    }

    public function attachment(int $id): ?array
    {
        $statement=$this->db->prepare('SELECT a.*,e.plano_id,e.aluno_nome_snapshot,p.professor_usuario_id,p.professor_nome_snapshot,p.turma_id_externo,p.turma_nome_snapshot,p.status plano_status,p.evento_id,ev.ano_letivo,ev.titulo evento_titulo FROM apc_anexos a JOIN apc_entregas e ON e.id=a.entrega_id JOIN apc_planos p ON p.id=e.plano_id JOIN apc_eventos ev ON ev.id=p.evento_id WHERE a.id=:id AND p.arquivado_em IS NULL');$statement->execute([':id'=>$id]);return$statement->fetch()?:null;
    }

    public function addAttachment(array $data): int
    {
        $statement=$this->db->prepare('INSERT INTO apc_anexos(entrega_id,nome_original,nome_armazenado,mime_type,tamanho_bytes,sha256,caminho_relativo,storage_driver,storage_file_id,storage_folder_id,enviado_por)VALUES(:entrega,:original,:armazenado,:mime,:tamanho,:sha,:caminho,:driver,:file_id,:folder_id,:usuario)');
        $statement->execute([':entrega'=>$data['entrega_id'],':original'=>$data['nome_original'],':armazenado'=>$data['nome_armazenado'],':mime'=>$data['mime_type'],':tamanho'=>$data['tamanho_bytes'],':sha'=>$data['sha256'],':caminho'=>$data['caminho_relativo']??null,':driver'=>$data['storage_driver']??'local',':file_id'=>$data['storage_file_id']??null,':folder_id'=>$data['storage_folder_id']??null,':usuario'=>$data['enviado_por']]);return(int)$this->db->lastInsertId();
    }

    public function deleteAttachment(int $id): void
    {
        $this->db->prepare('DELETE FROM apc_anexos WHERE id=:id')->execute([':id'=>$id]);
    }

    /** @return array<int, array<string, mixed>> */
    public function localAttachmentsForMigration(?int$id=null,int$limit=100):array
    {
        $sql="SELECT a.*,e.plano_id,e.aluno_nome_snapshot,p.professor_usuario_id,p.professor_nome_snapshot,p.turma_id_externo,p.turma_nome_snapshot,p.evento_id,ev.ano_letivo,ev.titulo evento_titulo FROM apc_anexos a JOIN apc_entregas e ON e.id=a.entrega_id JOIN apc_planos p ON p.id=e.plano_id JOIN apc_eventos ev ON ev.id=p.evento_id WHERE COALESCE(a.storage_driver,'local')='local'";$parameters=[];
        if($id!==null){$sql.=' AND a.id=:id';$parameters[':id']=$id;}$sql.=' ORDER BY a.id LIMIT '.max(1,$limit);$statement=$this->db->prepare($sql);$statement->execute($parameters);return$statement->fetchAll();
    }

    public function updateAttachmentStorage(int$id,array$storage):void
    {
        $statement=$this->db->prepare('UPDATE apc_anexos SET storage_driver=:driver,storage_file_id=:file_id,storage_folder_id=:folder_id,caminho_relativo=:caminho WHERE id=:id');
        $statement->execute([':driver'=>$storage['storage_driver'],':file_id'=>$storage['storage_file_id']??null,':folder_id'=>$storage['storage_folder_id']??null,':caminho'=>$storage['caminho_relativo']??null,':id'=>$id]);
    }
}
