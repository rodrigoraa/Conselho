<?php declare(strict_types=1);

namespace Apc\Services;

use Shared\Exceptions\HttpException;

final class XlsxScheduleParser
{
    private const DAYS=['SEGUNDA'=>1,'SEGUNDAFEIRA'=>1,'TERCA'=>2,'TERCAFEIRA'=>2,'QUARTA'=>3,'QUARTAFEIRA'=>3,'QUINTA'=>4,'QUINTAFEIRA'=>4,'SEXTA'=>5,'SEXTAFEIRA'=>5];
    private const UNASSIGNED=['','VAGO','SEMPROFESSOR','SEMPROFESSORDEFINIDO','ADEFINIR'];

    /** @return array<int,array{name:string,rows:array,errors:array,classes:int}> */
    public function inspect(string $path):array
    {
        try{$archive=new \PharData($path,0,null,\Phar::ZIP);}catch(\Throwable){throw new HttpException(422,'APC_SCHEDULE_XLSX_INVALID','O arquivo não é um XLSX válido.');}
        if(!isset($archive['[Content_Types].xml'])||!isset($archive['xl/workbook.xml']))throw new HttpException(422,'APC_SCHEDULE_XLSX_INVALID','A estrutura OpenXML do arquivo é inválida.');
        $entries=0;$expanded=0;
        foreach(new \RecursiveIteratorIterator($archive)as$file){$entries++;$expanded+=(int)$file->getSize();if($entries>250||$expanded>52428800)throw new HttpException(422,'APC_SCHEDULE_XLSX_LIMIT','A estrutura interna da planilha excede o limite seguro.');if(str_contains(mb_strtolower($file->getPathName()),'vbaproject'))throw new HttpException(422,'APC_SCHEDULE_XLSX_MACRO','Planilhas com macros não são aceitas.');}
        $shared=$this->sharedStrings($archive);$result=[];
        foreach($this->sheets($archive)as$sheet){$parsed=$this->parseMatrix($this->matrix($archive,$sheet['path'],$shared),$sheet['name']);if(!$parsed['recognized'])continue;$result[]=['name'=>$sheet['name'],'rows'=>$parsed['rows'],'errors'=>$parsed['errors'],'classes'=>count(array_unique(array_column($parsed['rows'],'turma_key')))];}
        if(!$result)throw new HttpException(422,'APC_SCHEDULE_EMPTY','Nenhuma aba com dias e aulas foi encontrada na planilha.');
        return$result;
    }

    /** @return array<int,array<string,mixed>> */
    public function parse(string $path,?string $sheetName=null):array
    {
        $sheets=$this->inspect($path);if($sheetName===null&&count($sheets)>1)throw new HttpException(422,'APC_SCHEDULE_SHEET_REQUIRED','Selecione a aba que contém a grade a importar.');
        foreach($sheets as$sheet)if($sheetName===null||$sheet['name']===$sheetName){if($sheet['errors'])throw new HttpException(422,'APC_SCHEDULE_STRUCTURE',$this->formatError($sheet['errors'][0]));if(!$sheet['rows'])throw new HttpException(422,'APC_SCHEDULE_EMPTY','A aba selecionada não possui aulas válidas.');return$sheet['rows'];}
        throw new HttpException(422,'APC_SCHEDULE_SHEET_INVALID','A aba selecionada não pertence a esta planilha.');
    }

    public function formatError(array$error):string
    {
        return 'Aba '.$error['sheet'].'; turma '.($error['class']?:'não identificada').'; linha '.$error['row'].'; '.($error['day']?:'dia não identificado').'; '.($error['lesson']?:'aula não identificada').'; valor: '.($error['value']?:'(vazio)').'; erro: '.$error['reason'];
    }

    public function normalize(string$value):string
    {
        $ascii=iconv('UTF-8','ASCII//TRANSLIT//IGNORE',trim($value));return preg_replace('/[^A-Z0-9]+/','',mb_strtoupper($ascii===false?$value:$ascii))??'';
    }

    private function parseMatrix(array$matrix,string$sheet):array
    {
        $rows=[];$errors=[];$seen=[];$class='';$columns=[];$classColumn=null;$lessonColumn=null;$recognized=false;
        foreach($matrix as$line){$cells=$line['cells'];$number=$line['row'];$filled=array_filter($cells,static fn($value):bool=>trim((string)$value)!=='');if(!$filled)continue;
            if(count($filled)===1){$single=trim((string)reset($filled));if(preg_match('/(?:ENSINO\s+FUNDAMENTAL|ENSINO\s+M[ÉE]DIO)/iu',$single)&&preg_match('/\d/u',$single)){$class=$single;$columns=[];$classColumn=null;$lessonColumn=null;continue;}}
            $found=[];$candidateClass=null;$candidateLesson=null;
            foreach($cells as$column=>$value){$name=$this->normalize((string)$value);if(isset(self::DAYS[$name]))$found[self::DAYS[$name]]=$column;elseif(in_array($name,['TURMA','CLASSE'],true))$candidateClass=$column;elseif(in_array($name,['AULA','HORARIO','ORDEM','NUMEROAULA'],true))$candidateLesson=$column;}
            if($found&&($class!==''||$candidateLesson!==null)){$columns=$found;$classColumn=$candidateClass;$lessonColumn=$candidateLesson??max(0,min($found)-1);$recognized=true;continue;}
            if(!$columns)continue;
            $label=trim((string)($cells[$lessonColumn]??''));if($label==='')continue;
            if(!preg_match('/^\s*(\d+)\s*(?:[ªº°])?\s*(?:AULA)?\s*$/iu',$label,$match))continue;
            $lesson=(int)$match[1];if($lesson<1)continue;
            $rowClass=$classColumn===null?$class:trim((string)($cells[$classColumn]??''));if($rowClass!=='')$class=$rowClass;else$rowClass=$class;
            foreach($columns as$day=>$column){$value=trim((string)($cells[$column]??''));if($value==='')continue;$dayName=array_search($day,self::DAYS,true);$context=['sheet'=>$sheet,'class'=>$rowClass,'row'=>$number,'day'=>$dayName===false?'':$dayName,'lesson'=>$lesson.'ª aula','value'=>$value];
                if($rowClass===''){$errors[]=$context+['reason'=>'turma ausente antes do cabeçalho'];continue;}
                if(!preg_match('/^(.+)\(([^()]*)\)\s*$/us',$value,$parts)){$reason=str_contains($value,'(')&&!str_ends_with($value,')')?'falta fechar o nome do professor com ")"':'use DISCIPLINA(PROFESSOR)';$errors[]=$context+['reason'=>$reason];continue;}
                $discipline=trim(preg_replace('/\s+/u',' ',$parts[1])??'');$teacher=trim($parts[2]);if($discipline===''){$errors[]=$context+['reason'=>'disciplina ausente'];continue;}
                $teacherKey=$this->normalize($teacher);$unassigned=in_array($teacherKey,self::UNASSIGNED,true);
                $key=$this->normalize($rowClass).'|'.$day.'|'.$lesson;if(isset($seen[$key])){$errors[]=$context+['reason'=>'aula duplicada na mesma turma e dia'];continue;}$seen[$key]=true;
                $rows[]=['sheet'=>$sheet,'row'=>$number,'turma_importada'=>$rowClass,'turma_key'=>$this->normalize($rowClass),'dia_semana'=>$day,'numero_aula'=>$lesson,'disciplina'=>$discipline,'professor_nome_importado'=>$teacher,'professor_key'=>$unassigned?null:$teacherKey,'professor_ausente'=>$unassigned];
                if(count($rows)>5000)throw new HttpException(422,'APC_SCHEDULE_XLSX_LIMIT','A planilha excede o limite de 5.000 aulas.');
            }
        }
        return['recognized'=>$recognized,'rows'=>$rows,'errors'=>$errors];
    }

    private function sharedStrings(\PharData$archive):array
    {
        if(!isset($archive['xl/sharedStrings.xml']))return[];$xml=$this->xml($archive['xl/sharedStrings.xml']->getContent());$values=[];foreach($xml->si as$item){$parts=[];if(isset($item->t))$parts[]=(string)$item->t;foreach($item->r as$run)$parts[]=(string)$run->t;$values[]=implode('',$parts);}return$values;
    }

    private function sheets(\PharData$archive):array
    {
        $workbook=$this->xml($archive['xl/workbook.xml']->getContent());$relationships=[];if(isset($archive['xl/_rels/workbook.xml.rels'])){$rels=$this->xml($archive['xl/_rels/workbook.xml.rels']->getContent());foreach($rels->Relationship as$rel){$target=ltrim((string)$rel['Target'],'/');$relationships[(string)$rel['Id']]=str_starts_with($target,'xl/')?$target:'xl/'.$target;}}
        $sheets=[];$index=1;foreach($workbook->sheets->sheet as$sheet){$attributes=$sheet->attributes('r',true);$path=$relationships[(string)$attributes['id']]??'xl/worksheets/sheet'.$index.'.xml';if(str_starts_with($path,'xl/../'))$path=substr($path,6);if(str_contains($path,'..'))throw new HttpException(422,'APC_SCHEDULE_XLSX_INVALID','Caminho interno inválido na planilha.');$sheets[]=['name'=>(string)$sheet['name'],'path'=>$path];$index++;}return$sheets;
    }

    private function matrix(\PharData$archive,string$path,array$shared):array
    {
        if(!isset($archive[$path]))return[];$xml=$this->xml($archive[$path]->getContent());$rows=[];foreach($xml->sheetData->row as$row){$cells=[];foreach($row->c as$cell){$reference=(string)$cell['r'];preg_match('/^[A-Z]+/',$reference,$match);$column=$this->columnIndex($match[0]??'A');$type=(string)$cell['t'];$value='';if($type==='inlineStr'){$parts=[];if(isset($cell->is->t))$parts[]=(string)$cell->is->t;foreach($cell->is->r as$run)$parts[]=(string)$run->t;$value=implode('',$parts);}else{$raw=(string)$cell->v;$value=$type==='s'?(string)($shared[(int)$raw]??''):$raw;}$cells[$column]=$value;}$rows[]=['row'=>(int)$row['r'],'cells'=>$cells];}return$rows;
    }

    private function columnIndex(string$letters):int{$index=0;foreach(str_split($letters)as$letter)$index=$index*26+(ord($letter)-64);return$index-1;}
    private function xml(string$contents):\SimpleXMLElement{$previous=libxml_use_internal_errors(true);try{$xml=simplexml_load_string($contents);if(!$xml)throw new HttpException(422,'APC_SCHEDULE_XLSX_INVALID','Um XML interno da planilha é inválido.');return$xml;}finally{libxml_clear_errors();libxml_use_internal_errors($previous);}}
}
