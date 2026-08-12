<?php declare(strict_types=1);

namespace Apc\Services;

use DateTimeImmutable;
use RuntimeException;
use Shared\Exceptions\HttpException;

final class EventWindow
{
    public const DAYS_BEFORE=7;
    public const DAYS_AFTER=7;

    public function __construct(private readonly ?string $fixedToday=null)
    {
        if($fixedToday!==null)$this->date($fixedToday,'data de referência');
    }

    public function describe(array $event): array
    {
        $eventDate=$this->date((string)($event['evento_data']??$event['data']??''),'data da APC');$today=$this->date($this->fixedToday??date('Y-m-d'),'data atual');$opens=$eventDate->modify('-'.self::DAYS_BEFORE.' days');$closes=$eventDate->modify('+'.self::DAYS_AFTER.' days');$eventStatus=(string)($event['evento_status']??$event['status']??'');$releasedAt=trim((string)($event['evento_disponibilizado_em']??$event['disponibilizado_em']??''));$isReleased=$releasedAt!=='';$isWithinWindow=$eventStatus==='ATIVO'&&$today>=$opens&&$today<=$closes;
        $state=$eventStatus!=='ATIVO'?'CANCELADA':($today<$opens?'AGUARDANDO':($today>$closes?'ENCERRADA':'ABERTA'));
        return['state'=>$state,'is_open'=>$state==='ABERTA','is_released'=>$isReleased,'is_within_window'=>$isWithinWindow,'released_at'=>$releasedAt?:null,'opens_on'=>$opens->format('Y-m-d'),'closes_on'=>$closes->format('Y-m-d'),'event_date'=>$eventDate->format('Y-m-d')];
    }

    public function assertOpen(array $event): void
    {
        $window=$this->describe($event);$period='de '.$this->format($window['opens_on']).' a '.$this->format($window['closes_on']);
        if($window['state']==='CANCELADA')throw new HttpException(422,'APC_EVENT_CANCELLED','Não é possível alterar dados de uma APC cancelada.');
        if($window['state']==='AGUARDANDO')throw new HttpException(422,'APC_EVENT_NOT_OPEN','Esta APC ainda não está aberta. O período de preenchimento será '.$period.'.');
        if($window['state']==='ENCERRADA')throw new HttpException(422,'APC_EVENT_CLOSED','O período de preenchimento desta APC foi encerrado em '.$this->format($window['closes_on']).'. A janela era '.$period.'.');
    }

    private function date(string $value,string $label): DateTimeImmutable
    {
        $date=DateTimeImmutable::createFromFormat('!Y-m-d',$value);if($date===false||$date->format('Y-m-d')!==$value)throw new RuntimeException("Não foi possível determinar a $label.");return$date;
    }

    private function format(string $date): string{return DateTimeImmutable::createFromFormat('!Y-m-d',$date)->format('d/m/Y');}
}
