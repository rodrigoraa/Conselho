<?php declare(strict_types=1);

namespace Apc\Repositories;

use PDO;

final class PlanRepository
{
    public function __construct(public readonly PDO $db) {}

    public function find(int $id): ?array
    {
        $statement=$this->db->prepare("SELECT p.*,e.data evento_data,e.ano_letivo,e.titulo evento_titulo,e.tipo evento_tipo,e.origem evento_origem,e.descricao evento_descricao,e.status evento_status,COUNT(DISTINCT ent.id) entregas_registradas,COUNT(DISTINCT CASE WHEN ent.entregue=1 THEN ent.id END) entregues,COUNT(DISTINCT an.id) anexos FROM apc_planos p JOIN apc_eventos e ON e.id=p.evento_id LEFT JOIN apc_entregas ent ON ent.plano_id=p.id LEFT JOIN apc_anexos an ON an.entrega_id=ent.id WHERE p.id=:id AND p.arquivado_em IS NULL GROUP BY p.id");
        $statement->execute([':id'=>$id]);return$statement->fetch()?:null;
    }

    public function list(int $userId,string $role,array $filters=[]): array
    {
        $where=['p.arquivado_em IS NULL'];$params=[];
        if($role==='PROFESSOR'){$where[]='p.professor_usuario_id=:usuario';$params[':usuario']=$userId;}
        foreach(['evento'=>'p.evento_id','turma'=>'p.turma_id_externo','professor'=>'p.professor_usuario_id']as$key=>$column){if(!empty($filters[$key])){$where[]="$column=:$key";$params[":$key"]=(int)$filters[$key];}}
        if(!empty($filters['ano'])){$where[]='e.ano_letivo=:ano';$params[':ano']=(int)$filters['ano'];}
        if(!empty($filters['status'])&&in_array($filters['status'],['RASCUNHO','FINALIZADO'],true)){$where[]='p.status=:status';$params[':status']=$filters['status'];}
        if(!empty($filters['componente'])){$where[]="p.componente_curricular LIKE :componente ESCAPE '\\'";$params[':componente']='%'.$this->escapeLike((string)$filters['componente']).'%';}
        if(!empty($filters['data'])){$where[]='e.data=:data';$params[':data']=$filters['data'];}
        $sql="SELECT p.*,e.data evento_data,e.ano_letivo,e.titulo evento_titulo,e.tipo evento_tipo,e.status evento_status,COUNT(DISTINCT ent.id) entregas_registradas,COUNT(DISTINCT CASE WHEN ent.entregue=1 THEN ent.id END) entregues,COUNT(DISTINCT an.id) anexos FROM apc_planos p JOIN apc_eventos e ON e.id=p.evento_id LEFT JOIN apc_entregas ent ON ent.plano_id=p.id LEFT JOIN apc_anexos an ON an.entrega_id=ent.id WHERE ".implode(' AND ',$where).' GROUP BY p.id ORDER BY e.data DESC,p.professor_nome_snapshot,p.turma_nome_snapshot,p.componente_curricular';
        $statement=$this->db->prepare($sql);$statement->execute($params);return$statement->fetchAll();
    }

    public function insert(array $data): int
    {
        $statement=$this->db->prepare('INSERT INTO apc_planos(evento_id,professor_usuario_id,professor_nome_snapshot,turma_id_externo,turma_nome_snapshot,componente_curricular,competencias_habilidades,conteudos,descricao_atividade,estrategia_devolucao,total_alunos_snapshot,etapa,ano_serie)VALUES(:evento,:professor,:professor_nome,:turma,:turma_nome,:componente,:competencias,:conteudos,:descricao,:estrategia,:total_alunos,:etapa,:ano_serie)');
        $statement->execute($this->parameters($data));return(int)$this->db->lastInsertId();
    }

    public function update(int $id,array $data): void
    {
        $statement=$this->db->prepare('UPDATE apc_planos SET componente_curricular=:componente,competencias_habilidades=:competencias,conteudos=:conteudos,descricao_atividade=:descricao,estrategia_devolucao=:estrategia,etapa=:etapa,ano_serie=:ano_serie,atualizado_em=CURRENT_TIMESTAMP WHERE id=:id');
        $statement->execute([':componente'=>$data['componente_curricular'],':competencias'=>$data['competencias_habilidades'],':conteudos'=>$data['conteudos'],':descricao'=>$data['descricao_atividade'],':estrategia'=>$data['estrategia_devolucao'],':etapa'=>$data['etapa']??null,':ano_serie'=>$data['ano_serie']??null,':id'=>$id]);
    }

    public function finalize(int $id): void
    {
        $this->db->prepare("UPDATE apc_planos SET status='FINALIZADO',finalizado_em=CURRENT_TIMESTAMP,atualizado_em=CURRENT_TIMESTAMP WHERE id=:id")->execute([':id'=>$id]);
    }

    public function reopen(int $id,int $userId,string $reason): void
    {
        $this->db->prepare("UPDATE apc_planos SET status='RASCUNHO',finalizado_em=NULL,reaberto_em=CURRENT_TIMESTAMP,reaberto_por=:usuario,motivo_reabertura=:motivo,atualizado_em=CURRENT_TIMESTAMP WHERE id=:id")->execute([':usuario'=>$userId,':motivo'=>$reason,':id'=>$id]);
    }

    public function updateStudentTotal(int $id,int $total): void
    {
        $this->db->prepare("UPDATE apc_planos SET total_alunos_snapshot=:total WHERE id=:id AND (status='RASCUNHO' OR total_alunos_snapshot IS NULL)")->execute([':total'=>$total,':id'=>$id]);
    }

    public function dashboardMetrics(int $userId,string $role): array
    {
        $where='arquivado_em IS NULL';$params=[];
        if($role==='PROFESSOR'){$where.=' AND professor_usuario_id=:usuario';$params[':usuario']=$userId;}
        $statement=$this->db->prepare("SELECT COUNT(*) total,SUM(CASE WHEN status='RASCUNHO' THEN 1 ELSE 0 END) pendentes,SUM(CASE WHEN status='FINALIZADO' THEN 1 ELSE 0 END) finalizados FROM apc_planos WHERE $where");
        $statement->execute($params);return$statement->fetch()?:['total'=>0,'pendentes'=>0,'finalizados'=>0];
    }

    public function eventSummary(int $eventId,int $userId,string $role): array
    {
        $where='evento_id=:evento AND arquivado_em IS NULL';$params=[':evento'=>$eventId];if($role==='PROFESSOR'){$where.=' AND professor_usuario_id=:usuario';$params[':usuario']=$userId;}$statement=$this->db->prepare("SELECT COUNT(*) total,SUM(CASE WHEN status='RASCUNHO' THEN 1 ELSE 0 END) pendentes,SUM(CASE WHEN status='FINALIZADO' THEN 1 ELSE 0 END) finalizados FROM apc_planos WHERE $where");$statement->execute($params);return$statement->fetch()?:['total'=>0,'pendentes'=>0,'finalizados'=>0];
    }

    public function eventPlansForTeacher(int $eventId,int $userId): array
    {
        $statement=$this->db->prepare('SELECT id,turma_nome_snapshot,componente_curricular,status FROM apc_planos WHERE evento_id=:evento AND professor_usuario_id=:usuario AND arquivado_em IS NULL ORDER BY turma_nome_snapshot,componente_curricular');$statement->execute([':evento'=>$eventId,':usuario'=>$userId]);return$statement->fetchAll();
    }

    private function parameters(array $data): array
    {
        return[':evento'=>$data['evento_id'],':professor'=>$data['professor_usuario_id'],':professor_nome'=>$data['professor_nome_snapshot'],':turma'=>$data['turma_id_externo'],':turma_nome'=>$data['turma_nome_snapshot'],':componente'=>$data['componente_curricular'],':competencias'=>$data['competencias_habilidades'],':conteudos'=>$data['conteudos'],':descricao'=>$data['descricao_atividade'],':estrategia'=>$data['estrategia_devolucao'],':total_alunos'=>$data['total_alunos_snapshot']??null,':etapa'=>$data['etapa']??null,':ano_serie'=>$data['ano_serie']??null];
    }

    private function escapeLike(string $value): string
    {
        return str_replace(['\\','%','_'],['\\\\','\\%','\\_'],$value);
    }
}
