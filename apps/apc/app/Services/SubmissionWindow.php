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
        $term=$this->terms->forEvent($event);$today=$this->date($this->fixedToday??date('Y-m-d'));$eventDate=$this->date((string)$event['data']);if($term===null)return['state'=>'SEM_BIMESTRE','is_open'=>false,'is_late'=>$today>$eventDate,'term'=>null,'opens_on'=>null,'closes_on'=>null];$start=$this->date((string)$term['data_inicio']);$end=$this->date((string)$term['data_fim']);$state=$today<$start?'AGUARDANDO_BIMESTRE':($today>$end?'ENCERRADO':'ABERTO');return['state'=>$state,'is_open'=>$state==='ABERTO','is_late'=>$today>$eventDate,'term'=>$term,'opens_on'=>$term['data_inicio'],'closes_on'=>$term['data_fim']];
    }

    public function assertOpen(array$event):array
    {
        $window=$this->describe($event);if($window['state']==='SEM_BIMESTRE')throw new HttpException(422,'APC_TERM_NOT_CONFIGURED','Este evento não possui um bimestre configurado para envio.');if($window['state']==='AGUARDANDO_BIMESTRE')throw new HttpException(422,'APC_TERM_NOT_OPEN','O envio deste evento começa em '.$this->format((string)$window['opens_on']).'.');if($window['state']==='ENCERRADO')throw new HttpException(422,'APC_TERM_CLOSED','O prazo de envio deste bimestre terminou em '.$this->format((string)$window['closes_on']).'.');return$window;
    }

    private function date(string$value):DateTimeImmutable
    {
        $date=DateTimeImmutable::createFromFormat('!Y-m-d',$value);if($date===false||$date->format('Y-m-d')!==$value)throw new \RuntimeException('Data inválida na configuração do APC.');return$date;
    }
    private function format(string$date):string{return$this->date($date)->format('d/m/Y');}
}
