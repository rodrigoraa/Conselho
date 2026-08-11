<?php declare(strict_types=1);

namespace Apc\Services;

use Apc\Repositories\{AuditRepository,CurriculumRepository};
use RuntimeException;

final class CurriculumImporter
{
    private const STAGES=['EF_AI','EF_AF','EM'];
    private const YEARS=['EF1','EF2','EF3','EF4','EF5','EF6','EF7','EF8','EF9','EM1','EM2','EM3'];

    public function __construct(private readonly CurriculumRepository $curriculum,private readonly AuditRepository $audit,private readonly string $directory) {}

    public function import(?array $files=null,?int $userId=null,string $ip='CLI',string $agent='scripts/console.php'): array
    {
        $files??=['componentes.csv','habilidades_ef_ms.csv','habilidades_em_ms.csv','habilidades_tvt_essenciais.csv'];$componentRows=$this->read($this->directory.'/componentes.csv');$components=$this->validateComponents($componentRows);$abilityRows=[];foreach($files as$file)if($file!=='componentes.csv')$abilityRows=array_merge($abilityRows,$this->read($this->directory.'/'.$file));$abilities=$this->validateAbilities($abilityRows,$components);$warnings=$this->duplicateWarnings($abilities);
        $db=$this->curriculum->db;$db->beginTransaction();
        try{
            $componentIds=[];foreach($components as$key=>$component)$componentIds[$key]=$this->curriculum->upsertComponent($component);
            $abilityIds=[];$associations=0;
            foreach($abilities as$ability){$componentKey=$ability['etapa'].'_'.$ability['sigla'];$ability['componente_id']=$componentIds[$componentKey];$id=$this->curriculum->upsertAbility($ability);$abilityIds[$ability['chave_estavel']]=$id;foreach($ability['associacoes']as$association){$this->curriculum->addAbilityAssociation($id,$association);$associations++;}}
            $summary=['componentes'=>count($componentIds),'habilidades'=>count($abilityIds),'associacoes'=>$associations,'linhas'=>count($abilityRows),'advertencias'=>$warnings];$this->audit->record($userId,'CURRICULO_IMPORTADO','apc_curriculo',null,null,$summary,$ip,$agent);$db->commit();return$summary;
        }catch(\Throwable$exception){if($db->inTransaction())$db->rollBack();throw$exception;}
    }

    private function read(string $path): array
    {
        if(!is_file($path))throw new RuntimeException('Arquivo curricular não encontrado: '.basename($path));$stream=fopen($path,'rb');if(!$stream)throw new RuntimeException('Não foi possível abrir '.basename($path));$header=fgetcsv($stream);if(!$header){fclose($stream);throw new RuntimeException('CSV sem cabeçalho: '.basename($path));}$header[0]=preg_replace('/^\xEF\xBB\xBF/','',(string)$header[0]);$rows=[];$line=1;while(($values=fgetcsv($stream))!==false){$line++;if(count($values)!==count($header)){fclose($stream);throw new RuntimeException(basename($path).":$line possui ".count($values).' colunas; esperado: '.count($header));}$row=array_combine($header,$values);if(!is_array($row))throw new RuntimeException('CSV inválido.');$row['_arquivo']=basename($path);$row['_linha']=$line;$rows[]=$row;}fclose($stream);return$rows;
    }

    private function validateComponents(array $rows): array
    {
        $result=[];foreach($rows as$row){$this->utf8($row);foreach(['chave','nome','sigla','modalidade','etapa','area_conhecimento']as$field)if(trim((string)($row[$field]??''))==='')$this->invalid($row,"campo $field obrigatório");$stage=(string)$row['etapa'];if(!in_array($stage,self::STAGES,true))$this->invalid($row,'etapa inválida');$modality=(string)$row['modalidade'];if(!in_array($modality,['GERAL','EDUCACAO_DO_CAMPO'],true))$this->invalid($row,'modalidade inválida');$key=$stage.'_'.mb_strtoupper(trim((string)$row['sigla']));if(isset($result[$key]))$this->invalid($row,'componente duplicado por etapa/sigla');$result[$key]=['chave'=>trim((string)$row['chave']),'nome'=>trim((string)$row['nome']),'sigla'=>trim((string)$row['sigla']),'modalidade'=>$modality,'etapa'=>$stage,'area_conhecimento'=>trim((string)$row['area_conhecimento']),'ordem'=>(int)$row['ordem'],'ativo'=>(int)$row['ativo']===0?0:1];}return$result;
    }

    private function validateAbilities(array $rows,array $components): array
    {
        $result=[];foreach($rows as$row){$this->utf8($row);foreach(['etapa','componente','sigla','descricao','origem','escopo','fonte_documento','tipo_associacao']as$field)if(trim((string)($row[$field]??''))==='')$this->invalid($row,"campo $field obrigatório");$stage=trim((string)$row['etapa']);$sigla=mb_strtoupper(trim((string)$row['sigla']));if(!isset($components[$stage.'_'.$sigla]))$this->invalid($row,'componente inexistente no catálogo para a etapa');$association=trim((string)$row['tipo_associacao']);if(!in_array($association,['CURRICULAR','RECOMPOSICAO'],true))$this->invalid($row,'tipo de associação inválido');$years=array_values(array_unique(array_filter(array_map('trim',explode('|',(string)$row['anos_series'])))));if(!$years)$this->invalid($row,'ano/série obrigatório');$associationRows=[];foreach($years as$year){if(!in_array($year,self::YEARS,true))$this->invalid($row,"ano/série inválido: $year");$associationRows[]=['etapa'=>$this->stageForYear($year),'ano_serie'=>$year,'tipo_associacao'=>$association];}$code=trim((string)($row['codigo']??''));$description=trim((string)$row['descricao']);$stable=hash('sha256',implode("\0",[$stage,$sigla,trim((string)$row['origem']),$code,$description]));if(!isset($result[$stable]))$result[$stable]=['chave_estavel'=>$stable,'etapa'=>$stage,'sigla'=>$sigla,'codigo'=>$code===''?null:$code,'descricao'=>$description,'unidade_tematica'=>trim((string)($row['unidade_tematica']??'')),'objeto_conhecimento'=>trim((string)($row['objeto_conhecimento']??'')),'origem'=>trim((string)$row['origem']),'escopo'=>trim((string)$row['escopo']),'fonte_documento'=>trim((string)$row['fonte_documento']),'fonte_pagina'=>trim((string)($row['fonte_pagina']??''))===''?null:(int)$row['fonte_pagina'],'ativo'=>1,'associacoes'=>[]];foreach($associationRows as$item)$result[$stable]['associacoes'][$item['etapa'].'|'.$item['ano_serie'].'|'.$item['tipo_associacao']]=$item;}
        foreach($result as&$row)$row['associacoes']=array_values($row['associacoes']);unset($row);return$result;
    }

    private function duplicateWarnings(array $abilities): array
    {
        $codes=[];foreach($abilities as$row)if($row['codigo']!==null)$codes[$row['origem'].'|'.$row['etapa'].'|'.$row['sigla'].'|'.$row['codigo']][$row['descricao']]=true;$warnings=[];foreach($codes as$key=>$descriptions)if(count($descriptions)>1)$warnings[]='Mesmo código com descrições diferentes: '.$key.' ('.count($descriptions).')';return$warnings;
    }

    private function utf8(array $row): void{foreach($row as$value)if(is_string($value)&&!mb_check_encoding($value,'UTF-8'))$this->invalid($row,'texto fora de UTF-8');}
    private function invalid(array $row,string $message): never{throw new RuntimeException(($row['_arquivo']??'CSV').':'.($row['_linha']??'?').' - '.$message);}
    private function stageForYear(string $year): string{if(str_starts_with($year,'EM'))return'EM';return(int)substr($year,2)<=5?'EF_AI':'EF_AF';}
}
