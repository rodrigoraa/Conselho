<?php declare(strict_types=1);

namespace Apc\Services;

use Apc\Repositories\{AuditRepository,SettingsRepository};
use Shared\Exceptions\HttpException;

final class SettingsService
{
    public function __construct(private readonly SettingsRepository $settings,private readonly AuditRepository $audit,private readonly \PDO $db) {}

    public function update(array $input,int $userId,string $ip,string $userAgent): void
    {
        $min=$this->number($input['nota_min']??null);$max=$this->number($input['nota_max']??null);$decimals=filter_var($input['nota_decimais']??null,FILTER_VALIDATE_INT,['options'=>['min_range'=>0,'max_range'=>4]]);
        if($min>=$max||$decimals===false)throw new HttpException(422,'APC_INVALID_SETTINGS','Confira os limites e as casas decimais da nota.');
        $before=$this->settings->all();$after=['nota_min'=>(string)$min,'nota_max'=>(string)$max,'nota_decimais'=>(string)$decimals];
        $this->db->beginTransaction();
        try{$this->settings->update($after,$userId);$this->audit->record($userId,'ALTERAR','apc_parametros',null,$before,$after,$ip,$userAgent);$this->db->commit();}
        catch(\Throwable $exception){if($this->db->inTransaction())$this->db->rollBack();throw$exception;}
    }

    private function number(mixed $value): float
    {
        $value=str_replace(',','.',trim((string)$value));if(!is_numeric($value))throw new HttpException(422,'APC_INVALID_SETTINGS','Confira os limites da nota.');return(float)$value;
    }
}
