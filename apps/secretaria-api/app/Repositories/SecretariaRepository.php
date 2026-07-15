<?php declare(strict_types=1);
namespace SecretariaApi\Repositories;
use PDO;

final class SecretariaRepository
{
    public function __construct(private readonly PDO $db) {}
    public function health(): bool
    { $required=['alunos','turmas']; $stmt=$this->db->query("SELECT name FROM sqlite_master WHERE type='table' AND name IN ('alunos','turmas')"); return count(array_intersect($required, $stmt->fetchAll(PDO::FETCH_COLUMN))) === 2; }
    public function turmas(?int $ano, ?string $busca, int $page, int $limit): array
    {
        [$where,$params]=$this->filters($ano,$busca); $offset=($page-1)*$limit;
        $count=$this->db->prepare("SELECT COUNT(*) FROM turmas $where"); $count->execute($params);
        $stmt=$this->db->prepare("SELECT id,nome_turma,ano_letivo FROM turmas $where ORDER BY ano_letivo DESC,nome_turma LIMIT :limite OFFSET :offset");
        foreach($params as $k=>$v) $stmt->bindValue($k,$v); $stmt->bindValue(':limite',$limit,PDO::PARAM_INT); $stmt->bindValue(':offset',$offset,PDO::PARAM_INT); $stmt->execute();
        return [$stmt->fetchAll(),(int)$count->fetchColumn()];
    }
    public function turma(int $id): ?array { $s=$this->db->prepare('SELECT id,nome_turma,ano_letivo FROM turmas WHERE id=:id');$s->execute([':id'=>$id]);return $s->fetch()?:null; }
    public function alunosDaTurma(int $turmaId, ?string $busca, int $page, int $limit): array
    {
        $where='WHERE id_turma=:turma';$params=[':turma'=>$turmaId]; if($busca!==null){$where.=" AND nome_completo LIKE :busca ESCAPE '\\'";$params[':busca']='%'.$this->escapeLike($busca).'%';}
        $c=$this->db->prepare("SELECT COUNT(*) FROM alunos $where");$c->execute($params);$offset=($page-1)*$limit;
        $s=$this->db->prepare("SELECT id,nome_completo,data_nascimento,id_turma FROM alunos $where ORDER BY nome_completo LIMIT :limite OFFSET :offset");
        foreach($params as $k=>$v)$s->bindValue($k,$v);$s->bindValue(':limite',$limit,PDO::PARAM_INT);$s->bindValue(':offset',$offset,PDO::PARAM_INT);$s->execute();return [$s->fetchAll(),(int)$c->fetchColumn()];
    }
    public function aluno(int $id): ?array { $s=$this->db->prepare('SELECT id,nome_completo,data_nascimento,id_turma FROM alunos WHERE id=:id');$s->execute([':id'=>$id]);return $s->fetch()?:null; }
    private function filters(?int $ano,?string $busca): array { $parts=[];$p=[];if($ano!==null){$parts[]='ano_letivo=:ano';$p[':ano']=$ano;}if($busca!==null){$parts[]="nome_turma LIKE :busca ESCAPE '\\'";$p[':busca']='%'.$this->escapeLike($busca).'%';}return [$parts?'WHERE '.implode(' AND ',$parts):'',$p]; }
    private function escapeLike(string $v): string{return str_replace(['\\','%','_'],['\\\\','\\%','\\_'],$v);}
}
