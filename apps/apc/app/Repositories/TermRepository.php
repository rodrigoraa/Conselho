<?php declare(strict_types=1);

namespace Apc\Repositories;

use PDO;

final class TermRepository
{
    public function __construct(private readonly PDO $db) {}

    public function forEvent(array $event):?array
    {
        $statement=$this->db->prepare('SELECT * FROM apc_bimestres WHERE ano_letivo=:ano AND data_inicio<=:data AND data_fim>=:data ORDER BY numero LIMIT 1');$statement->execute([':ano'=>(int)$event['ano_letivo'],':data'=>(string)$event['data']]);return$statement->fetch()?:null;
    }

    public function containing(string$date):?array
    {
        $statement=$this->db->prepare('SELECT * FROM apc_bimestres WHERE data_inicio<=:data AND data_fim>=:data ORDER BY ano_letivo DESC,numero LIMIT 1');$statement->execute([':data'=>$date]);return$statement->fetch()?:null;
    }
}
