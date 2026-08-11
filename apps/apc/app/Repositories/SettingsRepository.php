<?php declare(strict_types=1);

namespace Apc\Repositories;

use PDO;

final class SettingsRepository
{
    public function __construct(private readonly PDO $db) {}

    public function all(): array
    {
        $rows=$this->db->query('SELECT chave,valor FROM apc_parametros')->fetchAll();$settings=[];
        foreach($rows as$row)$settings[$row['chave']]=$row['valor'];
        return$settings+['nota_min'=>'0','nota_max'=>'10','nota_decimais'=>'1'];
    }

    public function update(array $values,int $userId): void
    {
        $statement=$this->db->prepare('INSERT INTO apc_parametros(chave,valor,atualizado_por)VALUES(:chave,:valor,:usuario) ON CONFLICT(chave) DO UPDATE SET valor=excluded.valor,atualizado_por=excluded.atualizado_por,atualizado_em=CURRENT_TIMESTAMP');
        foreach($values as$key=>$value)$statement->execute([':chave'=>$key,':valor'=>(string)$value,':usuario'=>$userId]);
    }
}
