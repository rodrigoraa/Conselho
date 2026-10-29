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

    public function classKeysForEvent(int $eventId):array
    {
        $statement=$this->db->prepare('SELECT s.id,s.professor_usuario_id,s.turma_id_externo,(SELECT COUNT(*) FROM apc_envio_turmas t WHERE t.envio_id=s.id) turma_count,(SELECT MIN(t.turma_id_externo) FROM apc_envio_turmas t WHERE t.envio_id=s.id) unica_turma FROM apc_envios s WHERE s.evento_id=:evento');
        $statement->execute([':evento'=>$eventId]);
        $rows=$statement->fetchAll();$submissionKeys=[];
        foreach($rows as$row){$classId=(int)($row['turma_id_externo']??0);if($classId===0&&(int)$row['turma_count']===1)$classId=(int)$row['unica_turma'];$submissionKeys[]=$classId>0?(int)$row['professor_usuario_id'].'|'.$classId:null;}
        return['count'=>count($rows),'submission_keys'=>$submissionKeys];
    }

    public function save(?int$id,array$data):int
    {
        $parameters=[':evento'=>$data['evento_id'],':bimestre'=>$data['bimestre_id'],':usuario'=>$data['professor_usuario_id'],':professor'=>$data['professor_nome_snapshot'],':etapa'=>$data['etapa'],':serie'=>$data['ano_serie'],':turma'=>$data['turma_id_externo']??null,':original'=>$data['nome_original'],':armazenado'=>$data['nome_armazenado'],':mime'=>$data['mime_type'],':tamanho'=>$data['tamanho_bytes'],':sha'=>$data['sha256'],':caminho'=>$data['caminho_relativo']??null,':driver'=>$data['storage_driver']??'local',':file_id'=>$data['storage_file_id']??null,':folder_id'=>$data['storage_folder_id']??null,':atrasado'=>$data['atrasado'],':dias'=>$data['dias_atraso'],':enviado'=>$data['enviado_em']];
        if($id===null){$statement=$this->db->prepare('INSERT INTO apc_envios(evento_id,bimestre_id,professor_usuario_id,professor_nome_snapshot,etapa,ano_serie,turma_id_externo,nome_original,nome_armazenado,mime_type,tamanho_bytes,sha256,caminho_relativo,storage_driver,storage_file_id,storage_folder_id,atrasado,dias_atraso,enviado_em)VALUES(:evento,:bimestre,:usuario,:professor,:etapa,:serie,:turma,:original,:armazenado,:mime,:tamanho,:sha,:caminho,:driver,:file_id,:folder_id,:atrasado,:dias,:enviado)');$statement->execute($parameters);return(int)$this->db->lastInsertId();}
        $statement=$this->db->prepare('UPDATE apc_envios SET bimestre_id=:bimestre,professor_nome_snapshot=:professor,nome_original=:original,nome_armazenado=:armazenado,mime_type=:mime,tamanho_bytes=:tamanho,sha256=:sha,caminho_relativo=:caminho,storage_driver=:driver,storage_file_id=:file_id,storage_folder_id=:folder_id,atrasado=:atrasado,dias_atraso=:dias,enviado_em=:enviado,atualizado_em=CURRENT_TIMESTAMP WHERE id=:id');$statement->execute(array_intersect_key($parameters,array_flip([':bimestre',':professor',':original',':armazenado',':mime',':tamanho',':sha',':caminho',':driver',':file_id',':folder_id',':atrasado',':dias',':enviado']))+[':id'=>$id]);return$id;
    }

    public function syncClasses(int$id,array$classes):void
    {
        $this->db->prepare('DELETE FROM apc_envio_turmas WHERE envio_id=:envio')->execute([':envio'=>$id]);$statement=$this->db->prepare('INSERT INTO apc_envio_turmas(envio_id,turma_id_externo,turma_nome_snapshot)VALUES(:envio,:turma,:nome)');foreach($classes as$class)$statement->execute([':envio'=>$id,':turma'=>(int)$class['id'],':nome'=>(string)$class['nome']]);
    }

    public function delete(int$id):void
    {
        $this->db->prepare('DELETE FROM apc_envios WHERE id=:id')->execute([':id'=>$id]);
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

    /** The display page is deliberately separate from the full submission list used for obligations. */
    public function page(int $userId,string $role,array $filters=[],int $page=1,int $perPage=20):array
    {
        [$where,$parameters]=$this->libraryWhere($userId,$role,$filters);$perPage=max(1,min(100,$perPage));
        $from=' FROM apc_envios s JOIN apc_eventos e ON e.id=s.evento_id';
        $statement=$this->db->prepare('SELECT COUNT(*) total,COALESCE(SUM(s.atrasado),0) late'.$from.$where);$statement->execute($parameters);$counts=$statement->fetch();$total=(int)$counts['total'];$pages=max(1,(int)ceil($total/$perPage));$page=max(1,min($pages,$page));
        $statement=$this->db->prepare($this->select().$where.' ORDER BY s.enviado_em DESC,s.id DESC LIMIT :limite OFFSET :offset');
        foreach($parameters as$key=>$value)$statement->bindValue($key,$value,is_int($value)?PDO::PARAM_INT:PDO::PARAM_STR);
        $statement->bindValue(':limite',$perPage,PDO::PARAM_INT);$statement->bindValue(':offset',($page-1)*$perPage,PDO::PARAM_INT);$statement->execute();
        return['rows'=>$statement->fetchAll(),'total'=>$total,'late'=>(int)$counts['late'],'ontime'=>$total-(int)$counts['late'],'page'=>$page,'pages'=>$pages,'per_page'=>$perPage];
    }

    public function filterOptions(int $userId,string $role):array
    {
        $where=$role==='PROFESSOR'?' WHERE s.professor_usuario_id=:usuario':'';$parameters=$role==='PROFESSOR'?[':usuario'=>$userId]:[];
        $statement=$this->db->prepare('SELECT DISTINCT e.id,e.ano_letivo,e.data,e.titulo,e.turno FROM apc_envios s JOIN apc_eventos e ON e.id=s.evento_id'.$where.' ORDER BY e.data DESC,e.id DESC');$statement->execute($parameters);$events=$statement->fetchAll();
        $statement=$this->db->prepare('SELECT s.professor_usuario_id id,MAX(s.professor_nome_snapshot) nome FROM apc_envios s'.$where.' GROUP BY s.professor_usuario_id ORDER BY nome COLLATE NOCASE,s.professor_usuario_id');$statement->execute($parameters);
        $years=array_values(array_unique(array_map('intval',array_column($events,'ano_letivo'))));rsort($years);
        return['events'=>$events,'professors'=>$statement->fetchAll(),'years'=>$years];
    }

    private function libraryWhere(int $userId,string $role,array $filters):array
    {
        $where=[];$parameters=[];
        if($role==='PROFESSOR'){$where[]='s.professor_usuario_id=:owner';$parameters[':owner']=$userId;}
        foreach(['ano'=>'e.ano_letivo','evento'=>'s.evento_id','professor'=>'s.professor_usuario_id']as$key=>$column)if(($filters[$key]??'')!==''){$where[]=$column.'=:'.$key;$parameters[':'.$key]=(int)$filters[$key];}
        if(in_array($filters['situacao']??'',['ontime','late'],true)){$where[]='s.atrasado=:atrasado';$parameters[':atrasado']=$filters['situacao']==='late'?1:0;}
        $mimes=['pdf'=>'application/pdf','doc'=>'application/msword','docx'=>'application/vnd.openxmlformats-officedocument.wordprocessingml.document','odt'=>'application/vnd.oasis.opendocument.text'];
        if(isset($mimes[$filters['formato']??''])){$where[]='s.mime_type=:mime';$parameters[':mime']=$mimes[$filters['formato']];}
        elseif(($filters['formato']??'')==='image')$where[]="s.mime_type IN ('image/jpeg','image/png','image/webp')";
        if(($filters['q']??'')!==''){
            $where[]="(s.professor_nome_snapshot LIKE :busca ESCAPE '\\' OR s.nome_original LIKE :busca ESCAPE '\\' OR e.titulo LIKE :busca ESCAPE '\\' OR EXISTS(SELECT 1 FROM apc_envio_turmas t WHERE t.envio_id=s.id AND t.turma_nome_snapshot LIKE :busca ESCAPE '\\'))";
            $parameters[':busca']='%'.str_replace(['\\','%','_'],['\\\\','\\%','\\_'],(string)$filters['q']).'%';
        }
        return[$where?' WHERE '.implode(' AND ',$where):'',$parameters];
    }

    /** @return array<int, array<string, mixed>> */
    public function localForMigration(?int$id=null,int$limit=100):array
    {
        $where=" WHERE COALESCE(s.storage_driver,'local')='local'";$parameters=[];
        if($id!==null){$where.=' AND s.id=:id';$parameters[':id']=$id;}
        $statement=$this->db->prepare($this->select().$where.' ORDER BY s.id LIMIT '.max(1,$limit));$statement->execute($parameters);return$statement->fetchAll();
    }

    public function updateStorage(int$id,array$storage):void
    {
        $statement=$this->db->prepare('UPDATE apc_envios SET storage_driver=:driver,storage_file_id=:file_id,storage_folder_id=:folder_id,caminho_relativo=:caminho,atualizado_em=CURRENT_TIMESTAMP WHERE id=:id');
        $statement->execute([':driver'=>$storage['storage_driver'],':file_id'=>$storage['storage_file_id']??null,':folder_id'=>$storage['storage_folder_id']??null,':caminho'=>$storage['caminho_relativo']??null,':id'=>$id]);
    }

    private function select():string
    {
        return"SELECT s.*,e.data evento_data,e.titulo evento_titulo,e.tipo evento_tipo,e.turno evento_turno,e.dia_grade_referencia evento_dia_grade_referencia,e.ano_letivo,b.numero bimestre_numero,b.data_inicio bimestre_inicio,b.data_fim bimestre_fim,(SELECT GROUP_CONCAT(t.turma_nome_snapshot,' • ') FROM apc_envio_turmas t WHERE t.envio_id=s.id ORDER BY t.turma_nome_snapshot) turmas,(SELECT GROUP_CONCAT(t.turma_id_externo,',') FROM apc_envio_turmas t WHERE t.envio_id=s.id ORDER BY t.turma_id_externo) turma_ids FROM apc_envios s JOIN apc_eventos e ON e.id=s.evento_id JOIN apc_bimestres b ON b.id=s.bimestre_id";
    }
}
