<?php declare(strict_types=1);

namespace Apc\Repositories;

use PDO;

final class AccessRepository
{
    public function __construct(private readonly PDO $db) {}

    public function classesFor(int $userId,string $role): array
    {
        if($role==='PROFESSOR'){
            $statement=$this->db->prepare("SELECT DISTINCT v.turma_externa_id id,v.turma_nome_snapshot nome,v.turma_ano_letivo_snapshot ano_letivo FROM vinculos_professor_turma v JOIN professores p ON p.id=v.professor_id JOIN usuarios u ON u.id=p.usuario_id WHERE p.usuario_id=:usuario AND v.ativo=1 AND p.ativo=1 AND u.ativo=1 AND u.excluido_em IS NULL ORDER BY v.turma_nome_snapshot COLLATE NOCASE");
            $statement->execute([':usuario'=>$userId]);
            return$statement->fetchAll();
        }
        return$this->db->query("SELECT v.turma_externa_id id,MAX(v.turma_nome_snapshot) nome,MAX(v.turma_ano_letivo_snapshot) ano_letivo FROM vinculos_professor_turma v JOIN professores p ON p.id=v.professor_id JOIN usuarios u ON u.id=p.usuario_id WHERE v.ativo=1 AND p.ativo=1 AND u.ativo=1 AND u.excluido_em IS NULL GROUP BY v.turma_externa_id ORDER BY nome COLLATE NOCASE")->fetchAll();
    }

    public function classFor(int $classId,int $userId,string $role): ?array
    {
        foreach($this->classesFor($userId,$role)as$class)if((int)$class['id']===$classId)return$class;
        return null;
    }
}
