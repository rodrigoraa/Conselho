<?php declare(strict_types=1);

namespace Apc\Services;

use Apc\Storage\UploadPreparer;
use Closure;
use Shared\Exceptions\HttpException;
use Smalot\PdfParser\Parser;

final class CalendarPdfExtractor
{
    private const MONTHS=['JANEIRO'=>1,'FEVEREIRO'=>2,'MARCO'=>3,'ABRIL'=>4,'MAIO'=>5,'JUNHO'=>6,'JULHO'=>7,'AGOSTO'=>8,'SETEMBRO'=>9,'OUTUBRO'=>10,'NOVEMBRO'=>11,'DEZEMBRO'=>12];
    private const TYPE_DATA=[
        'JORNADA_FORMATIVA'=>['code'=>'JF','title'=>'Jornada Formativa com APC'],
        'CONSELHO_CLASSE'=>['code'=>'CC','title'=>'Conselho de Classe com APC'],
        'EMENDA_FERIADO'=>['code'=>'EM','title'=>'Emenda de feriado com APC'],
        'EXCEPCIONAL'=>['code'=>'EX','title'=>'APC indicada no calendário'],
    ];

    private readonly Closure $pageReader;

    public function __construct(private readonly UploadPreparer $uploads,?Closure$pageReader=null)
    {
        $this->pageReader=$pageReader??static function(string$path):array{$pdf=(new Parser())->parseFile($path);return array_map(static fn($page):string=>$page->getText(),$pdf->getPages());};
    }

    /** @return array<string,mixed> */
    public function analyze(array$file):array
    {
        $upload=$this->uploads->prepare($file,'calendarios','Selecione um calendário válido em PDF.');
        try{
            try{$pages=($this->pageReader)($upload->path);}catch(\Throwable$exception){throw new HttpException(422,'APC_CALENDAR_PDF_UNREADABLE','Não foi possível ler o texto deste PDF. Confira se o calendário não está protegido ou digitalizado somente como imagem.');}
            if(!is_array($pages)||$pages===[]||count($pages)>30)throw new HttpException(422,'APC_CALENDAR_PDF_INVALID','O PDF do calendário está vazio ou possui páginas demais.');
            return$this->analyzePages(array_map('strval',$pages),$upload->originalName,$upload->sha256);
        }finally{$this->uploads->cleanup($upload);}
    }

    /** @param array<int,string> $pages @return array<string,mixed> */
    public function analyzePages(array$pages,string$fileName='calendario.pdf',string$sha256=''):array
    {
        $fullText=implode("\n",$pages);$year=$this->year($fullText);$process=$this->processNumber($fullText);$declared=$this->declaredTotals($fullText);$events=[];$warnings=[];
        foreach($pages as$pageIndex=>$pageText){
            $pageEvents=$this->eventsFromPage($pageText,$pageIndex+1,$year,$fileName,$process,$warnings);foreach($pageEvents as$event)$events[$event['data'].'|'.$event['tipo']]=$event;
        }
        $events=array_values($events);usort($events,static fn(array$a,array$b):int=>[$a['data'],$a['tipo']]<=>[$b['data'],$b['tipo']]);$counts=array_count_values(array_column($events,'tipo'));
        if($events===[])throw new HttpException(422,'APC_CALENDAR_NO_EVENTS','Nenhuma data descrita como APC foi encontrada no calendário.');
        if($declared['total']===null)$warnings[]='O PDF não informa um total anual de APCs para conferência automática.';
        elseif($declared['total']!==count($events))$warnings[]="O calendário declara {$declared['total']} APC(s), mas a extração encontrou ".count($events).'. Revise todas as datas antes de importar.';
        foreach($declared['by_type']as$type=>$expected)if(($counts[$type]??0)!==$expected)$warnings[]=$this->typeLabel($type).": o calendário declara $expected, mas foram encontradas ".($counts[$type]??0).'.';
        $verified=$warnings===[];$safeName=mb_substr(basename(str_replace('\\','/',$fileName)),0,180);
        return['year'=>$year,'file_name'=>$safeName,'sha256'=>$sha256,'page_count'=>count($pages),'process_number'=>$process,'declared_total'=>$declared['total'],'declared_by_type'=>$declared['by_type'],'counts'=>$counts,'events'=>$events,'warnings'=>array_values(array_unique($warnings)),'verified'=>$verified];
    }

    /** @return array<int,array<string,mixed>> */
    public function confirmedRows(array$analysis,array$input):array
    {
        if(($input['revisado']??'')!=='1')throw new HttpException(422,'APC_CALENDAR_REVIEW_REQUIRED','Confirme que você revisou as datas extraídas do calendário.');
        $posted=is_array($input['eventos']??null)?$input['eventos']:[];$rows=[];$year=(int)$analysis['year'];
        foreach($analysis['events']as$index=>$base){
            $values=is_array($posted[$index]??null)?$posted[$index]:[];if(($values['incluir']??'')!=='1')continue;
            $date=trim((string)($values['data']??$base['data']));$type=trim((string)($values['tipo']??$base['tipo']));$title=trim((string)($values['titulo']??$base['titulo']));
            if(!preg_match('/^\d{4}-\d{2}-\d{2}$/D',$date)||(int)substr($date,0,4)!==$year)throw new HttpException(422,'APC_CALENDAR_DATE_INVALID','Todas as datas selecionadas precisam pertencer ao ano letivo identificado.');
            if(!isset(self::TYPE_DATA[$type])||$title===''||mb_strlen($title)>160)throw new HttpException(422,'APC_CALENDAR_EVENT_INVALID','Confira o tipo e o título das APCs selecionadas.');
            $row=$base;$row['data']=$date;$row['tipo']=$type;$row['titulo']=$title;$row['chave_importacao']=$this->importKey($year,$type,$date);unset($row['evidencia']);$rows[]=$row;
        }
        if($rows===[])throw new HttpException(422,'APC_CALENDAR_EMPTY_SELECTION','Selecione ao menos uma data de APC para importar.');return$rows;
    }

    /** @param array<int,string> $warnings @return array<int,array<string,mixed>> */
    private function eventsFromPage(string$text,int$page,int$year,string$fileName,string$process,array&$warnings):array
    {
        $lines=preg_split('/\R/u',$text)?:[];$groups=[];
        foreach($lines as$index=>$line){$months=$this->monthsIn($line);if(count($months)>=2)$groups[]=['index'=>$index,'months'=>$months];}
        $events=[];
        foreach($groups as$groupIndex=>$group){
            $end=$groups[$groupIndex+1]['index']??count($lines);$region=array_slice($lines,$group['index']+1,$end-$group['index']-1);$blocks=$this->monthBlocks($region);
            if(count($blocks)<count($group['months'])){$warnings[]='A página '.$page.' não pôde ser separada completamente por mês; confira as datas extraídas.';}
            foreach($group['months']as$position=>$month){if(!isset($blocks[$position]))continue;foreach($this->apcDescriptions($blocks[$position])as$description){$type=$this->type($description['text']);foreach($this->days($description['days'])as$day){if(!checkdate($month,$day,$year)){$warnings[]="Data inválida ignorada na página $page: $day/{$month}/$year.";continue;}$date=sprintf('%04d-%02d-%02d',$year,$month,$day);$events[]=$this->eventRow($year,$date,$type,$description['text'],$fileName,$process,$page);}}}
        }
        return$events;
    }

    /** @param array<int,string> $lines @return array<int,array<int,string>> */
    private function monthBlocks(array$lines):array
    {
        $blocks=[];$current=[];
        foreach($lines as$line){$clean=trim(preg_replace('/[\t ]+/u',' ',$line)??'');if($clean==='')continue;if(str_starts_with($this->canonical($clean),'PROCESSO:'))break;$current[]=$clean;$canonical=$this->canonical($clean);if(str_contains($canonical,'FERIAS ESCOLARES')||preg_match('/^\d+\s+DIAS?\s+LETIVOS?$/D',$canonical)){if($current!==[])$blocks[]=$current;$current=[];}}
        if($current!==[])$blocks[]=$current;return$blocks;
    }

    /** @param array<int,string> $lines @return array<int,array{days:string,text:string}> */
    private function apcDescriptions(array$lines):array
    {
        $descriptions=[];$current=null;
        foreach($lines as$line){
            if(preg_match('/^(\d{1,2}(?:(?:\s*(?:a|e|,)\s*)\d{1,2})*)\s*[-–—]\s*(.+)$/ui',$line,$matches)){if($current!==null)$descriptions[]=$current;$current=['days'=>trim($matches[1]),'text'=>trim($matches[2])];continue;}
            if($current!==null&&!preg_match('/^\d+\s+dias?\s+letivos?/ui',$line))$current['text'].=' '.trim($line);
        }
        if($current!==null)$descriptions[]=$current;return array_values(array_filter($descriptions,fn(array$item):bool=>str_contains($this->canonical($item['text']),'APC')));
    }

    /** @return array<int,int> */
    private function days(string$expression):array
    {
        preg_match_all('/(\d{1,2})(?:\s*a\s*(\d{1,2}))?/ui',$expression,$matches,PREG_SET_ORDER);$days=[];
        foreach($matches as$match){$start=(int)$match[1];$end=isset($match[2])&&$match[2]!==''?(int)$match[2]:$start;if($end<$start)[$start,$end]=[$end,$start];for($day=$start;$day<=$end;$day++)$days[$day]=$day;}
        ksort($days);return array_values($days);
    }

    /** @return array<string,mixed> */
    private function eventRow(int$year,string$date,string$type,string$description,string$fileName,string$process,int$page):array
    {
        $typeData=self::TYPE_DATA[$type];$reference=mb_substr("Calendário Escolar $year - ".basename(str_replace('\\','/',$fileName)),0,255);
        return['chave_importacao'=>$this->importKey($year,$type,$date),'ano_letivo'=>$year,'data'=>$date,'titulo'=>$typeData['title'],'tipo'=>$type,'origem'=>'ESCOLA','descricao'=>mb_substr(trim(preg_replace('/\s+/u',' ',$description)??''),0,4000),'numero_processo'=>mb_substr($process,0,120),'documento_referencia'=>$reference,'fonte_pagina'=>$page,'status'=>'ATIVO','evidencia'=>trim($description)];
    }

    private function importKey(int$year,string$type,string$date):string{return'EE_SAO_JOSE_'.$year.'_'.self::TYPE_DATA[$type]['code'].'_'.str_replace('-','_',$date);}
    private function type(string$text):string{$canonical=$this->canonical($text);if(str_contains($canonical,'JORNADA FORMATIVA'))return'JORNADA_FORMATIVA';if(str_contains($canonical,'CONSELHO DE CLASSE'))return'CONSELHO_CLASSE';if(str_contains($canonical,'EMENDA'))return'EMENDA_FERIADO';return'EXCEPCIONAL';}
    private function typeLabel(string$type):string{return self::TYPE_DATA[$type]['title']??$type;}

    /** @return array<int,int> */
    private function monthsIn(string$line):array
    {
        $canonical=$this->canonical($line);$found=[];foreach(self::MONTHS as$name=>$number)if(preg_match('/\b'.preg_quote($name,'/').'\b/u',$canonical))$found[]=$number;return$found;
    }

    private function year(string$text):int
    {
        $canonical=$this->canonical($text);if(!preg_match('/CALENDARIO\s+ESCOLAR\s+((?:\d\s*){4})/u',$canonical,$matches))throw new HttpException(422,'APC_CALENDAR_YEAR_NOT_FOUND','Não foi possível identificar o ano letivo no título do calendário.');$year=(int)preg_replace('/\D/','',$matches[1]);if($year<2000||$year>2100)throw new HttpException(422,'APC_CALENDAR_YEAR_INVALID','O ano identificado no calendário é inválido.');return$year;
    }

    private function processNumber(string$text):string{return preg_match('/Processo:\s*([^\r\n]+)/ui',$text,$matches)?trim($matches[1]):'';}

    /** @return array{total:?int,by_type:array<string,int>} */
    private function declaredTotals(string$text):array
    {
        $canonical=preg_replace('/\s+/u',' ',$this->canonical($text))??'';$total=preg_match('/TOTAL DE APCS NO ANO LETIVO:\s*(\d+)/u',$canonical,$match)?(int)$match[1]:null;$types=[];
        if(preg_match('/SENDO\s+(\d+)\s+DE\s+JORNADA FORMATIVA\s*,?\s*(\d+)\s+DE\s+EMENDAS? DE FERIADO\s+E\s+(\d+)\s+DE\s+CONSELHO DE CLASSE/u',$canonical,$matches))$types=['JORNADA_FORMATIVA'=>(int)$matches[1],'EMENDA_FERIADO'=>(int)$matches[2],'CONSELHO_CLASSE'=>(int)$matches[3]];
        return['total'=>$total,'by_type'=>$types];
    }

    private function canonical(string$text):string
    {
        return strtr(mb_strtoupper($text),['Á'=>'A','À'=>'A','Â'=>'A','Ã'=>'A','Ä'=>'A','É'=>'E','È'=>'E','Ê'=>'E','Ë'=>'E','Í'=>'I','Ì'=>'I','Î'=>'I','Ï'=>'I','Ó'=>'O','Ò'=>'O','Ô'=>'O','Õ'=>'O','Ö'=>'O','Ú'=>'U','Ù'=>'U','Û'=>'U','Ü'=>'U','Ç'=>'C','º'=>'O','°'=>'O']);
    }
}
