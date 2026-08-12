<?php declare(strict_types=1);

namespace Apc\Repositories;

use PDO;

final class EventRepository
{
    public function __construct(public readonly PDO $db) {}

    public function all(?int $year=null,bool $includeCancelled=true): array
    {
        $where=[];$params=[];
        if($year!==null){$where[]='ano_letivo=:ano';$params[':ano']=$year;}
        if(!$includeCancelled)$where[]="status='ATIVO'";
        $sql='SELECT * FROM apc_eventos'.($where?' WHERE '.implode(' AND ',$where):'').' ORDER BY data DESC,id DESC';
        $statement=$this->db->prepare($sql);$statement->execute($params);return$statement->fetchAll();
    }

    public function active(): array
    {
        return$this->db->query("SELECT * FROM apc_eventos WHERE status='ATIVO' ORDER BY data,id")->fetchAll();
    }

    public function upcoming(int $limit=5,?string $today=null): array
    {
        $statement=$this->db->prepare("SELECT * FROM apc_eventos WHERE status='ATIVO' AND data>=:hoje ORDER BY data,id LIMIT :limite");$statement->bindValue(':hoje',$today??date('Y-m-d'));$statement->bindValue(':limite',$limit,PDO::PARAM_INT);$statement->execute();return$statement->fetchAll();
    }

    public function years(): array
    {
        return array_map('intval',$this->db->query('SELECT DISTINCT ano_letivo FROM apc_eventos ORDER BY ano_letivo DESC')->fetchAll(PDO::FETCH_COLUMN));
    }

    public function month(int $year,int $month): array
    {
        $start=sprintf('%04d-%02d-01',$year,$month);$end=(new \DateTimeImmutable($start))->modify('first day of next month')->format('Y-m-d');$statement=$this->db->prepare("SELECT * FROM apc_eventos WHERE status='ATIVO' AND data>=:inicio AND data<:fim ORDER BY data,id");$statement->execute([':inicio'=>$start,':fim'=>$end]);return$statement->fetchAll();
    }

    public function find(int $id): ?array
    {
        $statement=$this->db->prepare('SELECT * FROM apc_eventos WHERE id=:id');$statement->execute([':id'=>$id]);return$statement->fetch()?:null;
    }

    public function findByImportKey(string $key): ?array
    {
        $statement=$this->db->prepare('SELECT * FROM apc_eventos WHERE chave_importacao=:chave');$statement->execute([':chave'=>$key]);return$statement->fetch()?:null;
    }

    public function equivalents(int $year,string $date,string $type): array
    {
        $statement=$this->db->prepare('SELECT * FROM apc_eventos WHERE ano_letivo=:ano AND data=:data AND tipo=:tipo AND chave_importacao IS NULL ORDER BY id');$statement->execute([':ano'=>$year,':data'=>$date,':tipo'=>$type]);return$statement->fetchAll();
    }

    public function insert(array $data): int
    {
        $statement=$this->db->prepare('INSERT INTO apc_eventos(ano_letivo,data,titulo,tipo,origem,descricao,justificativa,numero_processo,documento_referencia,atividade_fornecida_sed,status,criado_por)VALUES(:ano,:data,:titulo,:tipo,:origem,:descricao,:justificativa,:processo,:documento,:sed,:status,:usuario)');
        $statement->execute($this->parameters($data)+[':usuario'=>$data['criado_por']]);return(int)$this->db->lastInsertId();
    }

    public function update(int $id,array $data): void
    {
        $statement=$this->db->prepare('UPDATE apc_eventos SET ano_letivo=:ano,data=:data,titulo=:titulo,tipo=:tipo,origem=:origem,descricao=:descricao,justificativa=:justificativa,numero_processo=:processo,documento_referencia=:documento,atividade_fornecida_sed=:sed,status=:status,atualizado_em=CURRENT_TIMESTAMP WHERE id=:id');
        $statement->execute($this->parameters($data)+[':id'=>$id]);
    }

    public function insertImported(array $data,int $userId): int
    {
        $statement=$this->db->prepare('INSERT INTO apc_eventos(ano_letivo,data,titulo,tipo,origem,descricao,justificativa,numero_processo,documento_referencia,atividade_fornecida_sed,status,criado_por,chave_importacao,fonte_pagina)VALUES(:ano,:data,:titulo,:tipo,:origem,:descricao,NULL,:processo,:documento,0,:status,:usuario,:chave,:pagina)');
        $statement->execute($this->importParameters($data)+[':usuario'=>$userId]);return(int)$this->db->lastInsertId();
    }

    public function updateImported(int $id,array $data): void
    {
        $statement=$this->db->prepare('UPDATE apc_eventos SET ano_letivo=:ano,data=:data,titulo=:titulo,tipo=:tipo,origem=:origem,descricao=:descricao,numero_processo=:processo,documento_referencia=:documento,status=:status,chave_importacao=:chave,fonte_pagina=:pagina,atualizado_em=CURRENT_TIMESTAMP WHERE id=:id');
        $statement->execute($this->importParameters($data)+[':id'=>$id]);
    }

    public function cancel(int $id): void
    {
        $this->db->prepare("UPDATE apc_eventos SET status='CANCELADO',disponibilizado_em=NULL,disponibilizado_por=NULL,atualizado_em=CURRENT_TIMESTAMP WHERE id=:id")->execute([':id'=>$id]);
    }

    public function reactivate(int $id): void
    {
        $this->db->prepare("UPDATE apc_eventos SET status='ATIVO',atualizado_em=CURRENT_TIMESTAMP WHERE id=:id")->execute([':id'=>$id]);
    }

    private function parameters(array $data): array
    {
        return[':ano'=>$data['ano_letivo'],':data'=>$data['data'],':titulo'=>$data['titulo'],':tipo'=>$data['tipo'],':origem'=>$data['origem'],':descricao'=>$data['descricao'],':justificativa'=>$data['justificativa'],':processo'=>$data['numero_processo'],':documento'=>$data['documento_referencia'],':sed'=>$data['atividade_fornecida_sed'],':status'=>$data['status']];
    }

    private function importParameters(array $data): array
    {
        return[':ano'=>$data['ano_letivo'],':data'=>$data['data'],':titulo'=>$data['titulo'],':tipo'=>$data['tipo'],':origem'=>$data['origem'],':descricao'=>$data['descricao'],':processo'=>$data['numero_processo'],':documento'=>$data['documento_referencia'],':status'=>$data['status'],':chave'=>$data['chave_importacao'],':pagina'=>$data['fonte_pagina']];
    }
}
