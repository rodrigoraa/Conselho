<?php declare(strict_types=1);

namespace Apc\Services;

use Apc\Repositories\TermRepository;
use DateTimeImmutable;
use Shared\Exceptions\HttpException;

final class SubmissionWindow
{
    public function __construct(private readonly TermRepository$terms,private readonly?string$fixedToday=null) {}

    public function describe(array$event):array
    {
        $term=$this->terms->forEvent($event);$today=$this->date($this->fixedToday??date('Y-m-d'));$eventDate=$this->date((string)$event['data']);$isLate=$today>$eventDate;if((string)($event['status']??'')!=='ATIVO')return['state'=>'CANCELADO','is_open'=>false,'is_late'=>$isLate,'term'=>$term,'opens_on'=>null,'closes_on'=>null];if($term===null)return['state'=>'SEM_BIMESTRE','is_open'=>false,'is_late'=>$isLate,'term'=>null,'opens_on'=>null,'closes_on'=>null];return['state'=>'ABERTO','is_open'=>true,'is_late'=>$isLate,'term'=>$term,'opens_on'=>$term['data_inicio'],'closes_on'=>null];
    }

    public function assertOpen(array$event):array
    {
        $window=$this->describe($event);if($window['state']==='CANCELADO')throw new HttpException(422,'APC_EVENT_CANCELLED','Esta APC está cancelada.');if($window['state']==='SEM_BIMESTRE')throw new HttpException(422,'APC_TERM_NOT_CONFIGURED','Este evento não possui um bimestre configurado para envio.');return$window;
    }

    private function date(string$value):DateTimeImmutable
    {
        $date=DateTimeImmutable::createFromFormat('!Y-m-d',$value);if($date===false||$date->format('Y-m-d')!==$value)throw new \RuntimeException('Data inválida na configuração do APC.');return$date;
    }
}
