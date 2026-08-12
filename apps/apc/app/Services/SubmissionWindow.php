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
        $term=$this->terms->forEvent($event);$today=$this->date($this->fixedToday??date('Y-m-d'));$eventDate=$this->date((string)$event['data']);if($term===null)return['state'=>'SEM_BIMESTRE','is_open'=>false,'is_late'=>$today>$eventDate,'term'=>null,'opens_on'=>null,'closes_on'=>null];$released=trim((string)($event['disponibilizado_em']??''))!=='';$state=$released?'ABERTO':'AGUARDANDO_LIBERACAO';return['state'=>$state,'is_open'=>$released,'is_late'=>$today>$eventDate,'term'=>$term,'opens_on'=>$released?substr((string)$event['disponibilizado_em'],0,10):null,'closes_on'=>null];
    }

    public function assertOpen(array$event):array
    {
        $window=$this->describe($event);if($window['state']==='SEM_BIMESTRE')throw new HttpException(422,'APC_TERM_NOT_CONFIGURED','Este evento não possui um bimestre configurado para envio.');if($window['state']==='AGUARDANDO_LIBERACAO')throw new HttpException(422,'APC_EVENT_NOT_RELEASED','Esta APC ainda não foi disponibilizada pela coordenação.');return$window;
    }

    private function date(string$value):DateTimeImmutable
    {
        $date=DateTimeImmutable::createFromFormat('!Y-m-d',$value);if($date===false||$date->format('Y-m-d')!==$value)throw new \RuntimeException('Data inválida na configuração do APC.');return$date;
    }
}
