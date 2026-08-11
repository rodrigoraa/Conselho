<?php declare(strict_types=1);

namespace Apc\Repositories;

use PDO;

final class AuditRepository
{
    public function __construct(private readonly PDO $db) {}

    public function record(?int $userId,string $action,string $entity,?int $entityId,mixed $before,mixed $after,string $ip,string $userAgent): void
    {
        $statement=$this->db->prepare('INSERT INTO apc_auditoria(usuario_id,acao,entidade,entidade_id,dados_antes,dados_depois,ip,user_agent)VALUES(:usuario,:acao,:entidade,:id,:antes,:depois,:ip,:agente)');
        $statement->execute([
            ':usuario'=>$userId,':acao'=>$action,':entidade'=>$entity,':id'=>$entityId,
            ':antes'=>$before===null?null:json_encode($before,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES),
            ':depois'=>$after===null?null:json_encode($after,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES),
            ':ip'=>mb_substr($ip,0,64),':agente'=>mb_substr($userAgent,0,255),
        ]);
    }

    public function recent(int $limit=100): array
    {
        $statement=$this->db->prepare('SELECT * FROM apc_auditoria ORDER BY id DESC LIMIT :limite');
        $statement->bindValue(':limite',$limit,PDO::PARAM_INT);$statement->execute();return$statement->fetchAll();
    }
}
