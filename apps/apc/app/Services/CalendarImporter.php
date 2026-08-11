<?php declare(strict_types=1);

namespace Apc\Services;

use Apc\Repositories\{AuditRepository,EventRepository};
use DateTimeImmutable;
use RuntimeException;
use Throwable;

final class CalendarImporter
{
    private const FIELDS=['chave_importacao','ano_letivo','data','titulo','tipo','origem','descricao','numero_processo','documento_referencia','fonte_pagina','status'];
    private const TYPES=['JORNADA_FORMATIVA','CONSELHO_CLASSE','EMENDA_FERIADO','EXCEPCIONAL','OUTRO'];
    private const ORIGINS=['SED','ESCOLA'];
    private const STATUSES=['ATIVO','CANCELADO'];

    public function __construct(private readonly EventRepository $events,private readonly AuditRepository $audit,private readonly string $csvPath) {}

    public function import(?int $userId=null,string $ip='console',string $userAgent='APC calendar importer'): array
    {
        $rows=$this->readRows();
        $summary=['total'=>count($rows),'criados'=>0,'atualizados'=>0,'conciliados'=>0,'inalterados'=>0,'por_tipo'=>array_fill_keys(self::TYPES,0)];
        $db=$this->events->db;$db->beginTransaction();
        try{
            foreach($rows as$row){
                $summary['por_tipo'][$row['tipo']]++;
                $event=$this->events->findByImportKey($row['chave_importacao']);$reconciled=false;
                if($event===null){$event=$this->equivalent($row);$reconciled=$event!==null;}
                if($event===null){$this->events->insertImported($row,$userId??0);$summary['criados']++;continue;}
                if($this->matches($event,$row)){$summary['inalterados']++;continue;}
                $this->events->updateImported((int)$event['id'],$row);$summary[$reconciled?'conciliados':'atualizados']++;
            }
            $this->audit->record($userId,'CALENDARIO_ESCOLAR_IMPORTADO','apc_eventos',null,null,$summary+['arquivo'=>basename($this->csvPath)],$ip,$userAgent);
            $db->commit();return$summary;
        }catch(Throwable$exception){if($db->inTransaction())$db->rollBack();throw$exception;}
    }

    private function equivalent(array $row): ?array
    {
        $events=$this->events->equivalents($row['ano_letivo'],$row['data'],$row['tipo']);
        if(count($events)===0)return null;
        if(count($events)===1)return$events[0];
        $title=mb_strtolower($row['titulo']);$matches=array_values(array_filter($events,static fn(array$event):bool=>mb_strtolower((string)$event['titulo'])===$title));
        if(count($matches)===1)return$matches[0];
        throw new RuntimeException("Há mais de um evento compatível com {$row['chave_importacao']}; a conciliação automática foi interrompida.");
    }

    private function matches(array $event,array $row): bool
    {
        foreach(self::FIELDS as$field){
            if($field==='ano_letivo'||$field==='fonte_pagina'){if((int)$event[$field]!==$row[$field])return false;continue;}
            if((string)($event[$field]??'')!==(string)$row[$field])return false;
        }
        return true;
    }

    private function readRows(): array
    {
        if(!is_file($this->csvPath)||!is_readable($this->csvPath))throw new RuntimeException('Arquivo de calendário não encontrado ou sem permissão de leitura.');
        $contents=file_get_contents($this->csvPath);if($contents===false||!mb_check_encoding($contents,'UTF-8'))throw new RuntimeException('O calendário precisa ser um CSV UTF-8 válido.');
        $handle=fopen($this->csvPath,'rb');if($handle===false)throw new RuntimeException('Não foi possível abrir o arquivo de calendário.');
        try{
            $header=fgetcsv($handle,0,',','"','\\');if($header===false)throw new RuntimeException('O arquivo de calendário está vazio.');
            $header[0]=preg_replace('/^\xEF\xBB\xBF/','',(string)$header[0])??'';
            if($header!==self::FIELDS)throw new RuntimeException('Cabeçalho inválido no arquivo de calendário.');
            $rows=[];$keys=[];$natural=[];$line=1;
            while(($values=fgetcsv($handle,0,',','"','\\'))!==false){$line++;if($values===[null])continue;if(count($values)!==count(self::FIELDS))throw new RuntimeException("Linha $line do calendário possui quantidade inválida de colunas.");$row=array_combine(self::FIELDS,array_map(static fn(mixed$value):string=>trim((string)$value),$values));if($row===false)throw new RuntimeException("Não foi possível interpretar a linha $line do calendário.");$row=$this->validate($row,$line);if(isset($keys[$row['chave_importacao']]))throw new RuntimeException("Chave de importação repetida na linha $line.");$identity=$row['ano_letivo'].'|'.$row['data'].'|'.$row['tipo'];if(isset($natural[$identity]))throw new RuntimeException("Data e tipo repetidos na linha $line.");$keys[$row['chave_importacao']]=true;$natural[$identity]=true;$rows[]=$row;}
            if($rows===[])throw new RuntimeException('O arquivo de calendário não contém eventos.');return$rows;
        }finally{fclose($handle);}
    }

    private function validate(array $row,int $line): array
    {
        if(!preg_match('/^[A-Z0-9_]{1,120}$/',$row['chave_importacao']))throw new RuntimeException("Chave de importação inválida na linha $line.");
        $year=filter_var($row['ano_letivo'],FILTER_VALIDATE_INT,['options'=>['min_range'=>2000,'max_range'=>2100]]);if($year===false)throw new RuntimeException("Ano letivo inválido na linha $line.");$date=DateTimeImmutable::createFromFormat('!Y-m-d',$row['data']);if($date===false||$date->format('Y-m-d')!==$row['data']||(int)$date->format('Y')!==$year)throw new RuntimeException("Data inválida na linha $line.");
        $page=filter_var($row['fonte_pagina'],FILTER_VALIDATE_INT,['options'=>['min_range'=>1,'max_range'=>999]]);if($page===false)throw new RuntimeException("Página de origem inválida na linha $line.");
        $this->length($row['titulo'],'Título',160,$line,true);$this->length($row['descricao'],'Descrição',4000,$line,false);$this->length($row['numero_processo'],'Número do processo',120,$line,false);$this->length($row['documento_referencia'],'Documento de referência',255,$line,true);
        if(!in_array($row['tipo'],self::TYPES,true))throw new RuntimeException("Tipo de evento inválido na linha $line.");if(!in_array($row['origem'],self::ORIGINS,true))throw new RuntimeException("Origem inválida na linha $line.");if(!in_array($row['status'],self::STATUSES,true))throw new RuntimeException("Status inválido na linha $line.");
        $row['ano_letivo']=$year;$row['fonte_pagina']=$page;return$row;
    }

    private function length(string $value,string $label,int $maximum,int $line,bool $required): void
    {
        $length=mb_strlen($value);if(($required&&$length===0)||$length>$maximum)throw new RuntimeException("$label inválido na linha $line.");
    }
}
