<?php declare(strict_types=1);

namespace Apc\Services;

use Apc\Repositories\{AccessRepository,AuditRepository,EventRepository,ScheduleRepository};
use Apc\Storage\UploadPreparer;
use Shared\Exceptions\HttpException;

final class ScheduleService
{
    private readonly UploadPreparer $preparer;
    public function __construct(private readonly ScheduleRepository$schedules,private readonly EventRepository$events,private readonly AccessRepository$access,private readonly AuditRepository$audit,private readonly XlsxScheduleParser$parser,string$stagingPath,int$maxBytes,?\Closure$isUploaded=null,?\Closure$moveUploaded=null)
    {
        $this->preparer=new UploadPreparer($stagingPath,$maxBytes,['application/vnd.openxmlformats-officedocument.spreadsheetml.sheet'=>'xlsx','application/zip'=>'xlsx','application/x-zip'=>'xlsx','application/x-zip-compressed'=>'xlsx','application/x-phar'=>'xlsx','application/octet-stream'=>'xlsx'],$isUploaded,$moveUploaded);
    }

    public function analyze(array$file,array$input):array
    {
        $year=filter_var($input['ano_letivo']??null,FILTER_VALIDATE_INT,['options'=>['min_range'=>2000,'max_range'=>2100]]);$shift=mb_strtoupper(trim((string)($input['turno']??'')));$from=$this->date($input['vigente_de']??null,'Início da vigência');$to=trim((string)($input['vigente_ate']??''));$to=$to===''?null:$this->date($to,'Fim da vigência');
        if($year===false||!in_array($shift,['MATUTINO','VESPERTINO'],true)||($to!==null&&$to<$from))throw new HttpException(422,'APC_SCHEDULE_METADATA','Confira ano letivo, turno e vigência da grade.');
        if(strtolower(pathinfo((string)($file['name']??''),PATHINFO_EXTENSION))!=='xlsx')throw new HttpException(422,'APC_SCHEDULE_TYPE','Envie somente arquivo XLSX, sem macros.');
        try{$upload=$this->preparer->prepare($file,'horarios','Envie somente arquivo XLSX válido, sem macros.');}catch(HttpException$exception){throw$exception;}
        try{$sheets=$this->parser->inspect($upload->path);}finally{$this->preparer->cleanup($upload);}
        $analysis=['ano_letivo'=>(int)$year,'turno'=>$shift,'vigente_de'=>$from,'vigente_ate'=>$to,'nome_arquivo'=>$upload->originalName,'sha256'=>$upload->sha256,'sheets'=>$sheets,'selected_sheet'=>null,'rows'=>[],'errors'=>[],'teacher_matches'=>[],'class_matches'=>[],'teachers'=>$this->access->teacherCandidates(),'classes'=>$this->access->classCandidates((int)$year,$shift),'counts'=>['aulas'=>0,'turmas'=>0,'professores'=>0]];
        return count($sheets)===1?$this->selectSheet($analysis,$sheets[0]['name']):$analysis;
    }

    public function selectSheet(array$analysis,string$name):array
    {
        foreach($analysis['sheets']as$sheet)if($sheet['name']===$name){$rows=$sheet['rows'];$teacherNames=array_values(array_unique(array_filter(array_column($rows,'professor_nome_importado'),fn($value)=>!in_array($this->parser->normalize((string)$value),['','VAGO','SEMPROFESSOR','SEMPROFESSORDEFINIDO','ADEFINIR'],true))));$teacherMatches=$this->matchNames($teacherNames,$analysis['teachers']);$classMatches=$this->matchNames(array_unique(array_column($rows,'turma_importada')),$analysis['classes']);foreach($rows as&$row){$row['professor_match']=$row['professor_ausente']?['status'=>'unassigned','id'=>null,'name'=>null,'suggestions'=>[]]:$teacherMatches[$row['professor_key']];$row['turma_match']=$classMatches[$row['turma_key']];}unset($row);return array_replace($analysis,['selected_sheet'=>$name,'rows'=>$rows,'errors'=>$sheet['errors'],'teacher_matches'=>$teacherMatches,'class_matches'=>$classMatches,'counts'=>['aulas'=>count($rows),'turmas'=>count($classMatches),'professores'=>count($teacherMatches)]]);}
        throw new HttpException(422,'APC_SCHEDULE_SHEET_INVALID','Selecione uma aba pertencente à planilha analisada.');
    }

    public function confirm(array$analysis,array$input,int$userId,string$ip,string$userAgent):int
    {
        if(($analysis['selected_sheet']??null)===null||!$analysis['rows']||!empty($analysis['errors']))throw new HttpException(422,'APC_SCHEDULE_STRUCTURE','Selecione uma aba com aulas válidas e corrija todos os erros estruturais antes de confirmar.');
        $teacherMap=is_array($input['professor_map']??null)?$input['professor_map']:[];$classMap=is_array($input['turma_map']??null)?$input['turma_map']:[];$teachers=array_column($analysis['teachers'],null,'id');$classes=array_column($analysis['classes'],null,'id');$resolved=[];
        $resolvedKeys=[];foreach($analysis['rows']as$row){$teacherId=$row['professor_ausente']?null:($row['professor_match']['status']==='safe'?(int)$row['professor_match']['id']:(int)($teacherMap[$row['professor_key']]??0));$classId=$row['turma_match']['status']==='safe'?(int)$row['turma_match']['id']:(int)($classMap[$row['turma_key']]??0);
            if(($teacherId!==null&&!isset($teachers[$teacherId]))||!isset($classes[$classId]))throw new HttpException(422,'APC_SCHEDULE_UNRESOLVED','Resolva todos os professores e turmas antes de confirmar.');
            if($teacherId!==null&&!$this->access->hasActiveClassBinding($teacherId,$classId,(int)$analysis['ano_letivo'],(string)$analysis['turno']))throw new HttpException(422,'APC_SCHEDULE_BINDING','O professor selecionado não possui vínculo ativo com a turma, no ano e turno informados.');
            $resolvedKey=$classId.'|'.(int)$row['dia_semana'].'|'.(int)$row['numero_aula'];if(isset($resolvedKeys[$resolvedKey]))throw new HttpException(422,'APC_SCHEDULE_DUPLICATE','As associações escolhidas criam duas aulas para a mesma turma, dia e número.');$resolvedKeys[$resolvedKey]=true;
            $resolved[]=['turma_id_externo'=>$classId,'turma_nome_snapshot'=>(string)$classes[$classId]['nome'],'dia_semana'=>(int)$row['dia_semana'],'numero_aula'=>(int)$row['numero_aula'],'disciplina'=>(string)$row['disciplina'],'professor_usuario_id'=>$teacherId,'professor_nome_snapshot'=>$teacherId===null?null:(string)$teachers[$teacherId]['nome'],'professor_nome_importado'=>(string)$row['professor_nome_importado']];
        }
        $this->schedules->db->beginTransaction();try{$this->schedules->assertNoOverlap((int)$analysis['ano_letivo'],(string)$analysis['turno'],(string)$analysis['vigente_de'],$analysis['vigente_ate']);$id=$this->schedules->createImport($analysis+['usuario_id'=>$userId],$resolved);$summary=['ano_letivo'=>$analysis['ano_letivo'],'turno'=>$analysis['turno'],'vigente_de'=>$analysis['vigente_de'],'vigente_ate'=>$analysis['vigente_ate'],'aba'=>$analysis['selected_sheet'],'aulas'=>count($resolved),'turmas'=>count(array_unique(array_column($resolved,'turma_id_externo'))),'professores'=>count(array_unique(array_filter(array_column($resolved,'professor_usuario_id'),static fn($id)=>$id!==null)))];$this->audit->record($userId,'IMPORTAR_HORARIO_APC','apc_horario_importacoes',$id,null,$summary+['arquivo'=>$analysis['nome_arquivo'],'sha256'=>$analysis['sha256']],$ip,$userAgent);$this->audit->record($userId,'CONFIRMAR_HORARIO_APC','apc_horario_importacoes',$id,null,$summary,$ip,$userAgent);$recalculated=0;foreach($this->events->all((int)$analysis['ano_letivo'],false)as$event){$date=(string)$event['data'];$covered=$date>=(string)$analysis['vigente_de']&&($analysis['vigente_ate']===null||$date<=(string)$analysis['vigente_ate']);if($covered&&$this->canRecalculate((int)$event['id'])){$this->schedules->resetSnapshot((int)$event['id']);$recalculated++;}$this->materialize($event);}if($recalculated>0)$this->audit->record($userId,'RECALCULAR_OBRIGACOES_APC','apc_evento_obrigacoes',null,null,$summary+['eventos'=>$recalculated],$ip,$userAgent);$this->schedules->db->commit();return$id;}catch(\DomainException$exception){if($this->schedules->db->inTransaction())$this->schedules->db->rollBack();throw new HttpException(422,'APC_SCHEDULE_OVERLAP',$exception->getMessage());}catch(\Throwable$exception){if($this->schedules->db->inTransaction())$this->schedules->db->rollBack();throw$exception;}
    }

    public function ensureEvent(array$event):array
    {
        $state=$this->schedules->obligationState((int)$event['id']);if($state)return['status'=>$state['status'],'requirements'=>$this->schedules->obligations((int)$event['id'])];
        $started=$this->schedules->db->inTransaction();if(!$started)$this->schedules->db->beginTransaction();try{$result=$this->materialize($event);if(!$started)$this->schedules->db->commit();return$result;}catch(\Throwable$exception){if(!$started&&$this->schedules->db->inTransaction())$this->schedules->db->rollBack();throw$exception;}
    }

    public function deactivate(int$id,int$userId,string$ip,string$userAgent):void
    {
        $this->schedules->db->beginTransaction();try{$this->schedules->deactivate($id,$userId);$this->audit->record($userId,'DESATIVAR_HORARIO_APC','apc_horario_importacoes',$id,null,['status'=>'DESATIVADO'],$ip,$userAgent);$this->schedules->db->commit();}catch(\Throwable$exception){if($this->schedules->db->inTransaction())$this->schedules->db->rollBack();throw$exception;}
    }

    private function materialize(array$event):array
    {
        $state=$this->schedules->obligationState((int)$event['id']);if($state)return['status'=>$state['status'],'requirements'=>$this->schedules->obligations((int)$event['id'])];
        $submission=$this->schedules->db->prepare('SELECT 1 FROM apc_envios WHERE evento_id=:evento LIMIT 1');$submission->execute([':evento'=>$event['id']]);if($submission->fetchColumn()){$this->schedules->saveSnapshot((int)$event['id'],[],'LEGADO','Evento anterior com envios preservados.');return['status'=>'LEGADO','requirements'=>[]];}
        $weekday=(int)(new \DateTimeImmutable((string)$event['data'],new \DateTimeZone('UTC')))->format('N');$reference=$event['dia_grade_referencia']??null;if($weekday>5&&$reference===null)return['status'=>'DIA_REFERENCIA_NAO_CONFIGURADO','requirements'=>[]];
        $needed=$this->access->activeShiftsForYear((int)$event['ano_letivo']);$covering=$this->schedules->coveringImports((int)$event['ano_letivo'],(string)$event['data']);$covered=array_values(array_unique(array_column($covering,'turno')));if(!$needed)$needed=$covered;if(!$needed||array_diff($needed,$covered))return['status'=>'NAO_CONFIGURADA','requirements'=>[],'missing_shifts'=>array_values(array_diff($needed,$covered))];
        $day=$reference===null?$weekday:(int)$reference;$rows=$this->schedules->rowsForEvent((int)$event['ano_letivo'],(string)$event['data'],$day);$valid=[];foreach($rows as$row)if($this->access->isActiveTeacher((int)$row['professor_usuario_id'])&&$this->access->hasActiveClassBinding((int)$row['professor_usuario_id'],(int)$row['turma_id_externo'],(int)$event['ano_letivo'],(string)$row['turno']))$valid[]=$row;$this->schedules->saveSnapshot((int)$event['id'],$valid);return['status'=>'CONFIGURADO','requirements'=>$this->schedules->obligations((int)$event['id'])];
    }

    private function matchNames(array$names,array$candidates):array
    {
        $indexed=[];foreach($candidates as$candidate)$indexed[$this->parser->normalize((string)$candidate['nome'])][]=$candidate;$result=[];foreach($names as$name){$key=$this->parser->normalize((string)$name);$exact=$indexed[$key]??[];if(count($exact)===1){$result[$key]=['status'=>'safe','id'=>(int)$exact[0]['id'],'name'=>(string)$exact[0]['nome'],'suggestions'=>[]];continue;}if(count($exact)>1){$result[$key]=['status'=>'ambiguous','id'=>null,'name'=>null,'suggestions'=>$exact];continue;}$scores=[];foreach($candidates as$candidate){$candidateKey=$this->parser->normalize((string)$candidate['nome']);$distance=levenshtein($key,$candidateKey);if($distance<=max(2,(int)floor(max(strlen($key),1)*.2)))$scores[]=$candidate+['distance'=>$distance];}usort($scores,static fn($a,$b)=>$a['distance']<=>$b['distance']);$result[$key]=['status'=>$scores?'unmatched':'not_found','id'=>null,'name'=>null,'suggestions'=>array_slice($scores,0,3)];}return$result;
    }

    private function canRecalculate(int$eventId):bool{$state=$this->schedules->obligationState($eventId);if($state===null||$state['status']==='LEGADO')return false;$statement=$this->schedules->db->prepare('SELECT 1 FROM apc_envios WHERE evento_id=:evento LIMIT 1');$statement->execute([':evento'=>$eventId]);if($statement->fetchColumn())return false;$event=$this->events->find($eventId);if($event===null)return false;foreach($this->schedules->obligations($eventId)as$obligation)if(!$this->access->isActiveTeacher((int)$obligation['professor_usuario_id'])||!$this->access->hasActiveClassBinding((int)$obligation['professor_usuario_id'],(int)$obligation['turma_id_externo'],(int)$event['ano_letivo'],(string)$obligation['turno']))return false;return true;}

    private function date(mixed$value,string$label):string{$value=trim((string)$value);$date=\DateTimeImmutable::createFromFormat('!Y-m-d',$value);if(!$date||$date->format('Y-m-d')!==$value)throw new HttpException(422,'APC_SCHEDULE_DATE',$label.' inválido.');return$value;}
}
