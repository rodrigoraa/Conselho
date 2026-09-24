<?php declare(strict_types=1);

namespace Apc\Repositories;

use PDO;

final class ScheduleRepository
{
    public function __construct(public readonly PDO $db) {}

    public function imports(?int $year=null):array
    {
        $sql='SELECT i.*,COUNT(h.id) aula_count,COUNT(DISTINCT h.turma_id_externo) turma_count,COUNT(DISTINCT h.professor_usuario_id) professor_count FROM apc_horario_importacoes i LEFT JOIN apc_horarios h ON h.importacao_id=i.id';
        $params=[];if($year!==null){$sql.=' WHERE i.ano_letivo=:ano';$params[':ano']=$year;}
        $sql.=' GROUP BY i.id ORDER BY i.ano_letivo DESC,i.vigente_de DESC,i.turno';$statement=$this->db->prepare($sql);$statement->execute($params);return$statement->fetchAll();
    }

    public function assertNoOverlap(int $year,string $shift,string $from,?string $to):void
    {
        $statement=$this->db->prepare("SELECT id FROM apc_horario_importacoes WHERE ano_letivo=:ano AND turno=:turno AND status='ATIVO' AND vigente_de<=COALESCE(:ate,'9999-12-31') AND COALESCE(vigente_ate,'9999-12-31')>=:de LIMIT 1");
        $statement->execute([':ano'=>$year,':turno'=>$shift,':de'=>$from,':ate'=>$to]);
        if($statement->fetchColumn()!==false)throw new \DomainException('Já existe uma grade ativa deste turno com vigência sobreposta. Desative-a ou ajuste as datas.');
    }

    public function createImport(array $data,array $rows):int
    {
        $statement=$this->db->prepare('INSERT INTO apc_horario_importacoes(ano_letivo,turno,vigente_de,vigente_ate,nome_arquivo_snapshot,sha256,criado_por)VALUES(:ano,:turno,:de,:ate,:arquivo,:sha,:usuario)');
        $statement->execute([':ano'=>$data['ano_letivo'],':turno'=>$data['turno'],':de'=>$data['vigente_de'],':ate'=>$data['vigente_ate'],':arquivo'=>$data['nome_arquivo'],':sha'=>$data['sha256'],':usuario'=>$data['usuario_id']]);$id=(int)$this->db->lastInsertId();
        $insert=$this->db->prepare('INSERT INTO apc_horarios(importacao_id,turma_id_externo,turma_nome_snapshot,dia_semana,numero_aula,disciplina,professor_usuario_id,professor_nome_snapshot,professor_nome_importado)VALUES(:importacao,:turma,:turma_nome,:dia,:aula,:disciplina,:professor,:professor_nome,:importado)');
        foreach($rows as$row)$insert->execute([':importacao'=>$id,':turma'=>$row['turma_id_externo'],':turma_nome'=>$row['turma_nome_snapshot'],':dia'=>$row['dia_semana'],':aula'=>$row['numero_aula'],':disciplina'=>$row['disciplina'],':professor'=>$row['professor_usuario_id'],':professor_nome'=>$row['professor_nome_snapshot'],':importado'=>$row['professor_nome_importado']]);
        return$id;
    }

    public function coveringImports(int $year,string $date):array
    {
        $statement=$this->db->prepare("SELECT * FROM apc_horario_importacoes WHERE ano_letivo=:ano AND status='ATIVO' AND vigente_de<=:data AND (vigente_ate IS NULL OR vigente_ate>=:data) ORDER BY turno,id");$statement->execute([':ano'=>$year,':data'=>$date]);return$statement->fetchAll();
    }

    public function rowsForEvent(int $year,string $date,int $weekday):array
    {
        $statement=$this->db->prepare("SELECT h.*,i.turno FROM apc_horarios h JOIN apc_horario_importacoes i ON i.id=h.importacao_id WHERE i.ano_letivo=:ano AND i.status='ATIVO' AND i.vigente_de<=:data AND (i.vigente_ate IS NULL OR i.vigente_ate>=:data) AND h.dia_semana=:dia AND h.professor_usuario_id IS NOT NULL ORDER BY h.turma_nome_snapshot,h.numero_aula");$statement->execute([':ano'=>$year,':data'=>$date,':dia'=>$weekday]);return$statement->fetchAll();
    }

    public function obligationState(int $eventId):?array
    {
        $statement=$this->db->prepare('SELECT * FROM apc_evento_obrigacao_estados WHERE evento_id=:evento');$statement->execute([':evento'=>$eventId]);return$statement->fetch()?:null;
    }

    public function obligations(int $eventId,?int $userId=null):array
    {
        $sql='SELECT * FROM apc_evento_obrigacoes WHERE evento_id=:evento';$params=[':evento'=>$eventId];if($userId!==null){$sql.=' AND professor_usuario_id=:usuario';$params[':usuario']=$userId;}$sql.=' ORDER BY professor_nome_snapshot COLLATE NOCASE,turma_nome_snapshot COLLATE NOCASE';$statement=$this->db->prepare($sql);$statement->execute($params);return$statement->fetchAll();
    }

    public function hasObligation(int $eventId,int $userId,int $classId):bool
    {
        $statement=$this->db->prepare('SELECT 1 FROM apc_evento_obrigacoes WHERE evento_id=:evento AND professor_usuario_id=:usuario AND turma_id_externo=:turma LIMIT 1');$statement->execute([':evento'=>$eventId,':usuario'=>$userId,':turma'=>$classId]);return(bool)$statement->fetchColumn();
    }

    public function saveSnapshot(int $eventId,array $rows,string $status='CONFIGURADO',?string $reason=null):void
    {
        $insert=$this->db->prepare('INSERT OR IGNORE INTO apc_evento_obrigacoes(evento_id,professor_usuario_id,professor_nome_snapshot,turma_id_externo,turma_nome_snapshot,turno,disciplina_snapshot,origem_horario_id)VALUES(:evento,:professor,:professor_nome,:turma,:turma_nome,:turno,:disciplina,:horario)');
        foreach($rows as$row)$insert->execute([':evento'=>$eventId,':professor'=>$row['professor_usuario_id'],':professor_nome'=>$row['professor_nome_snapshot'],':turma'=>$row['turma_id_externo'],':turma_nome'=>$row['turma_nome_snapshot'],':turno'=>$row['turno'],':disciplina'=>$row['disciplina'],':horario'=>$row['id']]);
        $state=$this->db->prepare('INSERT OR IGNORE INTO apc_evento_obrigacao_estados(evento_id,status,motivo)VALUES(:evento,:status,:motivo)');$state->execute([':evento'=>$eventId,':status'=>$status,':motivo'=>$reason]);
    }

    public function resetSnapshot(int$eventId):void
    {
        $this->db->prepare('DELETE FROM apc_evento_obrigacoes WHERE evento_id=:evento')->execute([':evento'=>$eventId]);$this->db->prepare('DELETE FROM apc_evento_obrigacao_estados WHERE evento_id=:evento')->execute([':evento'=>$eventId]);
    }

    public function deactivate(int $id,int $userId):void
    {
        $statement=$this->db->prepare("UPDATE apc_horario_importacoes SET status='DESATIVADO',desativado_por=:usuario,desativado_em=CURRENT_TIMESTAMP WHERE id=:id AND status='ATIVO'");$statement->execute([':id'=>$id,':usuario'=>$userId]);
    }
}
