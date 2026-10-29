<?php declare(strict_types=1);

namespace Apc\Support;

/** Only list filters, never an arbitrary return URL. */
final class NavigationContext
{
    private function __construct(public readonly array $filters) {}

    public static function from(array $input):self
    {
        $filters=[];
        foreach(['aba','ano','evento','professor','q','situacao','formato','pagina']as$key){
            $value=$input[$key]??'';if(!is_scalar($value))continue;$value=trim((string)$value);if($value==='')continue;
            if($key==='aba'){if(in_array($value,['mine','files','tracking'],true))$filters[$key]=$value;}
            elseif($key==='situacao'){if(in_array($value,['ontime','late'],true))$filters[$key]=$value;}
            elseif($key==='formato'){if(in_array($value,['pdf','doc','docx','odt','image'],true))$filters[$key]=$value;}
            elseif($key==='q')$filters[$key]=mb_substr($value,0,200);
            else{$number=filter_var($value,FILTER_VALIDATE_INT,['options'=>['min_range'=>$key==='ano'?2000:1,'max_range'=>$key==='ano'?2100:2147483647]]);if($number!==false)$filters[$key]=(string)$number;}
        }
        // The personal view already has an obligatory owner scope and no professor control.
        if(($filters['aba']??'')==='mine')unset($filters['professor']);
        return new self($filters);
    }

    public function query(array $overrides=[]):string{return http_build_query(self::from(array_replace($this->filters,$overrides))->filters,'','&',PHP_QUERY_RFC3986);}
    public function url(array $overrides=[]):string
    {
        $filters=self::from(array_replace($this->filters,$overrides))->filters;$query=http_build_query($filters,'','&',PHP_QUERY_RFC3986);
        $anchor=match($filters['aba']??''){'files'=>'#arquivos','tracking'=>'#acompanhamento','mine'=>'#minhas-apcs',default=>''};
        return'/apc'.($query!==''?'?'.$query:'').$anchor;
    }

    public static function shift(array $event):string{return match($event['turno']??$event['evento_turno']??null){'MATUTINO'=>'Matutino','VESPERTINO'=>'Vespertino',default=>'Todos os turnos'};}
    public static function reference(array $event):string
    {
        $date=(string)($event['data']??$event['evento_data']??'');if($date==='')return'';
        $weekday=(int)(new \DateTimeImmutable($date,new \DateTimeZone('UTC')))->format('N');
        $day=$weekday<=5?$weekday:(int)($event['dia_grade_referencia']??$event['evento_dia_grade_referencia']??0);
        return[1=>'Segunda-feira',2=>'Terça-feira',3=>'Quarta-feira',4=>'Quinta-feira',5=>'Sexta-feira'][$day]??'Dia da grade pendente';
    }
}
