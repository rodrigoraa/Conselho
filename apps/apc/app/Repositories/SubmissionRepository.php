<?php declare(strict_types=1);

namespace Apc\Repositories;

use PDO;

final class SubmissionRepository
{
    public function __construct(public readonly PDO $db) {}

    public function existingForClass(int$eventId,int$userId,int$classId):?array
    {
        $statement=$this->db->prepare('SELECT s.* FROM apc_envios s WHERE s.evento_id=:evento AND s.professor_usuario_id=:usuario AND (s.turma_id_externo=:turma OR (s.turma_id_externo IS NULL AND (SELECT COUNT(*) FROM apc_envio_turmas c WHERE c.envio_id=s.id)=1 AND EXISTS(SELECT 1 FROM apc_envio_turmas t WHERE t.envio_id=s.id AND t.turma_id_externo=:turma))) LIMIT 1');$statement->execute([':evento'=>$eventId,':usuario'=>$userId,':turma'=>$classId]);return$statement->fetch()?:null;
    }

    public function save(?int$id,array$data):int
    {
        $parameters=[':evento'=>$data['evento_id'],':bimestre'=>$data['bimestre_id'],':usuario'=>$data['professor_usuario_id'],':professor'=>$data['professor_nome_snapshot'],':etapa'=>$data['etapa'],':serie'=>$data['ano_serie'],':turma'=>$data['turma_id_externo']??null,':original'=>$data['nome_original'],':armazenado'=>$data['nome_armazenado'],':mime'=>$data['mime_type'],':tamanho'=>$data['tamanho_bytes'],':sha'=>$data['sha256'],':caminho'=>$data['caminho_relativo'],':atrasado'=>$data['atrasado'],':dias'=>$data['dias_atraso'],':enviado'=>$data['enviado_em']];
        if($id===null){$statement=$this->db->prepare('INSERT INTO apc_envios(evento_id,bimestre_id,professor_usuario_id,professor_nome_snapshot,etapa,ano_serie,turma_id_externo,nome_original,nome_armazenado,mime_type,tamanho_bytes,sha256,caminho_relativo,atrasado,dias_atraso,enviado_em)VALUES(:evento,:bimestre,:usuario,:professor,:etapa,:serie,:turma,:original,:armazenado,:mime,:tamanho,:sha,:caminho,:atrasado,:dias,:enviado)');$statement->execute($parameters);return(int)$this->db->lastInsertId();}
        $statement=$this->db->prepare('UPDATE apc_envios SET bimestre_id=:bimestre,professor_nome_snapshot=:professor,nome_original=:original,nome_armazenado=:armazenado,mime_type=:mime,tamanho_bytes=:tamanho,sha256=:sha,caminho_relativo=:caminho,atrasado=:atrasado,dias_atraso=:dias,enviado_em=:enviado,atualizado_em=CURRENT_TIMESTAMP WHERE id=:id');$statement->execute(array_intersect_key($parameters,array_flip([':bimestre',':professor',':original',':armazenado',':mime',':tamanho',':sha',':caminho',':atrasado',':dias',':enviado']))+[':id'=>$id]);return$id;
    }

    public function syncClasses(int$id,array$classes):void
    {
        $this->db->prepare('DELETE FROM apc_envio_turmas WHERE envio_id=:envio')->execute([':envio'=>$id]);$statement=$this->db->prepare('INSERT INTO apc_envio_turmas(envio_id,turma_id_externo,turma_nome_snapshot)VALUES(:envio,:turma,:nome)');foreach($classes as$class)$statement->execute([':envio'=>$id,':turma'=>(int)$class['id'],':nome'=>(string)$class['nome']]);
    }

    public function find(int$id):?array
    {
        $statement=$this->db->prepare($this->select().' WHERE s.id=:id');$statement->execute([':id'=>$id]);return$statement->fetch()?:null;
    }

    public function list(int$userId,string$role):array
    {
        $where=$role==='PROFESSOR'?' WHERE s.professor_usuario_id=:usuario':'';$statement=$this->db->prepare($this->select().$where.' ORDER BY s.enviado_em DESC,s.id DESC');$statement->execute($role==='PROFESSOR'?[':usuario'=>$userId]:[]);return$statement->fetchAll();
    }

    public function forEvent(int$eventId,int$userId,string$role):array
    {
        $where=' WHERE s.evento_id=:evento';$parameters=[':evento'=>$eventId];if($role==='PROFESSOR'){$where.=' AND s.professor_usuario_id=:usuario';$parameters[':usuario']=$userId;}$statement=$this->db->prepare($this->select().$where.' ORDER BY s.professor_nome_snapshot,s.etapa,s.ano_serie');$statement->execute($parameters);return$statement->fetchAll();
    }

    private function select():string
    {
        return"SELECT s.*,e.data evento_data,e.titulo evento_titulo,e.tipo evento_tipo,e.ano_letivo,b.numero bimestre_numero,b.data_inicio bimestre_inicio,b.data_fim bimestre_fim,(SELECT GROUP_CONCAT(t.turma_nome_snapshot,' • ') FROM apc_envio_turmas t WHERE t.envio_id=s.id ORDER BY t.turma_nome_snapshot) turmas,(SELECT GROUP_CONCAT(t.turma_id_externo,',') FROM apc_envio_turmas t WHERE t.envio_id=s.id ORDER BY t.turma_id_externo) turma_ids FROM apc_envios s JOIN apc_eventos e ON e.id=s.evento_id JOIN apc_bimestres b ON b.id=s.bimestre_id";
    }
}
