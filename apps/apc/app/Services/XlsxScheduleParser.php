<?php declare(strict_types=1);

namespace Apc\Services;

use Shared\Exceptions\HttpException;

final class XlsxScheduleParser
{
    private const DAYS=['SEGUNDA'=>1,'SEGUNDAFEIRA'=>1,'TERCA'=>2,'TERCAFEIRA'=>2,'QUARTA'=>3,'QUARTAFEIRA'=>3,'QUINTA'=>4,'QUINTAFEIRA'=>4,'SEXTA'=>5,'SEXTAFEIRA'=>5];

    /** @return array<int,array<string,mixed>> */
    public function parse(string $path):array
    {
        try{$archive=new \PharData($path,0,null,\Phar::ZIP);}catch(\Throwable){throw new HttpException(422,'APC_SCHEDULE_XLSX_INVALID','O arquivo não é um XLSX válido.');}
        if(!isset($archive['[Content_Types].xml'])||!isset($archive['xl/workbook.xml']))throw new HttpException(422,'APC_SCHEDULE_XLSX_INVALID','A estrutura OpenXML do arquivo é inválida.');
        $entryCount=0;$expandedBytes=0;foreach(new \RecursiveIteratorIterator($archive)as$file){$entryCount++;$expandedBytes+=(int)$file->getSize();if($entryCount>250||$expandedBytes>52428800)throw new HttpException(422,'APC_SCHEDULE_XLSX_LIMIT','A estrutura interna da planilha excede o limite seguro.');$name=strtolower(str_replace('\\','/',$file->getPathName()));if(str_contains($name,'vbaproject'))throw new HttpException(422,'APC_SCHEDULE_XLSX_MACRO','Planilhas com macros não são aceitas.');}
        $shared=$this->sharedStrings($archive);$sheets=$this->sheets($archive);$result=[];$seen=[];$errors=[];
        foreach($sheets as$sheet){$matrix=$this->matrix($archive,$sheet['path'],$shared);if(!$matrix)continue;$headerIndex=null;$columns=[];
            foreach($matrix as$index=>$cells){foreach($cells as$column=>$value){$normalized=$this->normalize($value);if(isset(self::DAYS[$normalized]))$columns[self::DAYS[$normalized]]=$column;elseif(in_array($normalized,['TURMA','CLASSE'],true))$columns['class']=$column;elseif(in_array($normalized,['AULA','HORARIO','ORDEM','NUMEROAULA'],true))$columns['lesson']=$column;}if(count(array_filter(array_keys($columns),'is_int'))>=1&&isset($columns['lesson'])){$headerIndex=$index;break;}}
            if($headerIndex===null){$errors[]="Aba {$sheet['name']}: cabeçalho não encontrado.";continue;}foreach($matrix[$headerIndex]as$value){$normalized=$this->normalize((string)$value);if($normalized!==''&&!isset(self::DAYS[$normalized])&&!in_array($normalized,['TURMA','CLASSE','AULA','HORARIO','ORDEM','NUMEROAULA'],true))$errors[]="Aba {$sheet['name']}: dia ou coluna desconhecida '{$value}'.";}
            $lastClass='';foreach($matrix as$index=>$cells){if($index<=$headerIndex)continue;$classColumn=$columns['class']??null;$class=$classColumn!==null?trim((string)($cells[$classColumn]??'')):trim($sheet['name']);if($class!=='')$lastClass=$class;else$class=$lastClass;$lessonText=trim((string)($cells[$columns['lesson']]??''));if($lessonText===''&&count(array_filter($cells,static fn($v):bool=>trim((string)$v)!==''))===0)continue;if(!preg_match('/\d+/',$lessonText,$lessonMatch)){$errors[]="Aba {$sheet['name']}, linha ".($index+1).': número da aula inválido.';continue;}$lesson=(int)$lessonMatch[0];
                foreach($columns as$day=>$column){if(!is_int($day))continue;$value=trim((string)($cells[$column]??''));if($value==='')continue;if($class===''){$errors[]="Aba {$sheet['name']}, linha ".($index+1).': turma ausente.';continue;}if(!preg_match('/^(.+?)\s*\(([^()]+)\)\s*$/u',$value,$match)){$errors[]="Aba {$sheet['name']}, linha ".($index+1).": use DISCIPLINA(PROFESSOR) em '{$value}'.";continue;}$discipline=trim($match[1]);$teacher=trim($match[2]);if($discipline===''||$teacher===''){$errors[]="Aba {$sheet['name']}, linha ".($index+1).': disciplina ou professor ausente.';continue;}$key=$this->normalize($class).'|'.$day.'|'.$lesson;if(isset($seen[$key])){$errors[]="Aula duplicada: {$class}, dia {$day}, {$lesson}ª aula.";continue;}$seen[$key]=true;$result[]=['sheet'=>$sheet['name'],'row'=>$index+1,'turma_importada'=>$class,'turma_key'=>$this->normalize($class),'dia_semana'=>$day,'numero_aula'=>$lesson,'disciplina'=>$discipline,'professor_nome_importado'=>$teacher,'professor_key'=>$this->normalize($teacher)];}
            }
        }
        if($errors)throw new HttpException(422,'APC_SCHEDULE_STRUCTURE',implode(' ',array_slice($errors,0,12)));if(!$result)throw new HttpException(422,'APC_SCHEDULE_EMPTY','Nenhuma aula válida foi encontrada na planilha.');if(count($result)>5000)throw new HttpException(422,'APC_SCHEDULE_XLSX_LIMIT','A planilha excede o limite de 5.000 aulas.');return$result;
    }

    public function normalize(string $value):string
    {
        $ascii=iconv('UTF-8','ASCII//TRANSLIT//IGNORE',trim($value));return preg_replace('/[^A-Z0-9]+/','',mb_strtoupper($ascii===false?$value:$ascii))??'';
    }

    private function sharedStrings(\PharData $archive):array
    {
        if(!isset($archive['xl/sharedStrings.xml']))return[];$xml=$this->xml($archive['xl/sharedStrings.xml']->getContent());$values=[];foreach($xml->si as$item){$parts=[];if(isset($item->t))$parts[]=(string)$item->t;foreach($item->r as$run)$parts[]=(string)$run->t;$values[]=implode('',$parts);}return$values;
    }

    private function sheets(\PharData $archive):array
    {
        $workbook=$this->xml($archive['xl/workbook.xml']->getContent());$relationships=[];if(isset($archive['xl/_rels/workbook.xml.rels'])){$rels=$this->xml($archive['xl/_rels/workbook.xml.rels']->getContent());foreach($rels->Relationship as$rel){$target=ltrim((string)$rel['Target'],'/');$relationships[(string)$rel['Id']]=str_starts_with($target,'xl/')?$target:'xl/'.$target;}}
        $workbook->registerXPathNamespace('r','http://schemas.openxmlformats.org/officeDocument/2006/relationships');$sheets=[];$index=1;foreach($workbook->sheets->sheet as$sheet){$attributes=$sheet->attributes('r',true);$path=$relationships[(string)$attributes['id']]??'xl/worksheets/sheet'.$index.'.xml';if(str_starts_with($path,'xl/../'))$path=substr($path,6);$sheets[]=['name'=>(string)$sheet['name'],'path'=>$path];$index++;}return$sheets;
    }

    private function matrix(\PharData $archive,string $path,array $shared):array
    {
        if(!isset($archive[$path]))return[];$xml=$this->xml($archive[$path]->getContent());$rows=[];foreach($xml->sheetData->row as$row){$cells=[];foreach($row->c as$cell){$reference=(string)$cell['r'];preg_match('/^[A-Z]+/',$reference,$match);$column=$this->columnIndex($match[0]??'A');$type=(string)$cell['t'];$value='';if($type==='inlineStr'){$parts=[];if(isset($cell->is->t))$parts[]=(string)$cell->is->t;foreach($cell->is->r as$run)$parts[]=(string)$run->t;$value=implode('',$parts);}else{$raw=(string)$cell->v;$value=$type==='s'?(string)($shared[(int)$raw]??''):$raw;}$cells[$column]=$value;}$rows[]=$cells;}return$rows;
    }

    private function columnIndex(string $letters):int{$index=0;foreach(str_split($letters)as$letter)$index=$index*26+(ord($letter)-64);return$index-1;}
    private function xml(string $contents):\SimpleXMLElement{$previous=libxml_use_internal_errors(true);try{$xml=simplexml_load_string($contents);if(!$xml)throw new HttpException(422,'APC_SCHEDULE_XLSX_INVALID','Um XML interno da planilha é inválido.');return$xml;}finally{libxml_clear_errors();libxml_use_internal_errors($previous);}}
}
