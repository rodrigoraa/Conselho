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
        try{$rows=$this->parser->parse($upload->path);}finally{$this->preparer->cleanup($upload);}
        $teachers=$this->access->teacherCandidates();$classes=$this->access->classCandidates((int)$year,$shift);$teacherMatches=$this->matchNames(array_unique(array_column($rows,'professor_nome_importado')),$teachers);$classMatches=$this->matchNames(array_unique(array_column($rows,'turma_importada')),$classes);
        foreach($rows as&$row){$row['professor_match']=$teacherMatches[$row['professor_key']];$row['turma_match']=$classMatches[$row['turma_key']];}unset($row);
        return['ano_letivo'=>(int)$year,'turno'=>$shift,'vigente_de'=>$from,'vigente_ate'=>$to,'nome_arquivo'=>$upload->originalName,'sha256'=>$upload->sha256,'rows'=>$rows,'teacher_matches'=>$teacherMatches,'class_matches'=>$classMatches,'teachers'=>$teachers,'classes'=>$classes,'counts'=>['aulas'=>count($rows),'turmas'=>count($classMatches),'professores'=>count($teacherMatches)]];
    }

    public function confirm(array$analysis,array$input,int$userId,string$ip,string$userAgent):int
    {
        $teacherMap=is_array($input['professor_map']??null)?$input['professor_map']:[];$classMap=is_array($input['turma_map']??null)?$input['turma_map']:[];$teachers=array_column($analysis['teachers'],null,'id');$classes=array_column($analysis['classes'],null,'id');$resolved=[];
        $resolvedKeys=[];foreach($analysis['rows']as$row){$teacherId=$row['professor_match']['status']==='safe'?(int)$row['professor_match']['id']:(int)($teacherMap[$row['professor_key']]??0);$classId=$row['turma_match']['status']==='safe'?(int)$row['turma_match']['id']:(int)($classMap[$row['turma_key']]??0);
            if(!isset($teachers[$teacherId])||!isset($classes[$classId]))throw new HttpException(422,'APC_SCHEDULE_UNRESOLVED','Resolva todos os professores e turmas antes de confirmar.');
            if(!$this->access->hasActiveClassBinding($teacherId,$classId,(int)$analysis['ano_letivo'],(string)$analysis['turno']))throw new HttpException(422,'APC_SCHEDULE_BINDING','O professor selecionado não possui vínculo ativo com a turma, no ano e turno informados.');
            $resolvedKey=$classId.'|'.(int)$row['dia_semana'].'|'.(int)$row['numero_aula'];if(isset($resolvedKeys[$resolvedKey]))throw new HttpException(422,'APC_SCHEDULE_DUPLICATE','As associações escolhidas criam duas aulas para a mesma turma, dia e número.');$resolvedKeys[$resolvedKey]=true;
            $resolved[]=['turma_id_externo'=>$classId,'turma_nome_snapshot'=>(string)$classes[$classId]['nome'],'dia_semana'=>(int)$row['dia_semana'],'numero_aula'=>(int)$row['numero_aula'],'disciplina'=>(string)$row['disciplina'],'professor_usuario_id'=>$teacherId,'professor_nome_snapshot'=>(string)$teachers[$teacherId]['nome'],'professor_nome_importado'=>(string)$row['professor_nome_importado']];
        }
        $this->schedules->db->beginTransaction();try{$this->schedules->assertNoOverlap((int)$analysis['ano_letivo'],(string)$analysis['turno'],(string)$analysis['vigente_de'],$analysis['vigente_ate']);$id=$this->schedules->createImport($analysis+['usuario_id'=>$userId],$resolved);$summary=['ano_letivo'=>$analysis['ano_letivo'],'turno'=>$analysis['turno'],'vigente_de'=>$analysis['vigente_de'],'vigente_ate'=>$analysis['vigente_ate'],'aulas'=>count($resolved),'turmas'=>count(array_unique(array_column($resolved,'turma_id_externo'))),'professores'=>count(array_unique(array_column($resolved,'professor_usuario_id')))];$this->audit->record($userId,'IMPORTAR_HORARIO_APC','apc_horario_importacoes',$id,null,$summary+['arquivo'=>$analysis['nome_arquivo'],'sha256'=>$analysis['sha256']],$ip,$userAgent);$this->audit->record($userId,'CONFIRMAR_HORARIO_APC','apc_horario_importacoes',$id,null,$summary,$ip,$userAgent);$recalculated=0;foreach($this->events->all((int)$analysis['ano_letivo'],false)as$event){$date=(string)$event['data'];$covered=$date>=(string)$analysis['vigente_de']&&($analysis['vigente_ate']===null||$date<=(string)$analysis['vigente_ate']);if($covered&&$this->canRecalculate((int)$event['id'])){$this->schedules->resetSnapshot((int)$event['id']);$recalculated++;}$this->materialize($event);}if($recalculated>0)$this->audit->record($userId,'RECALCULAR_OBRIGACOES_APC','apc_evento_obrigacoes',null,null,$summary+['eventos'=>$recalculated],$ip,$userAgent);$this->schedules->db->commit();return$id;}catch(\DomainException$exception){if($this->schedules->db->inTransaction())$this->schedules->db->rollBack();throw new HttpException(422,'APC_SCHEDULE_OVERLAP',$exception->getMessage());}catch(\Throwable$exception){if($this->schedules->db->inTransaction())$this->schedules->db->rollBack();throw$exception;}
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
        $needed=$this->access->activeShiftsForYear((int)$event['ano_letivo']);$covering=$this->schedules->coveringImports((int)$event['ano_letivo'],(string)$event['data']);$covered=array_values(array_unique(array_column($covering,'turno')));if(!$needed)$needed=$covered;if(!$needed||array_diff($needed,$covered))return['status'=>'NAO_CONFIGURADA','requirements'=>[],'missing_shifts'=>array_values(array_diff($needed,$covered))];
        $weekday=(int)(new \DateTimeImmutable((string)$event['data'],new \DateTimeZone('UTC')))->format('N');$rows=$weekday<=5?$this->schedules->rowsForEvent((int)$event['ano_letivo'],(string)$event['data'],$weekday):[];$valid=[];foreach($rows as$row)if($this->access->isActiveTeacher((int)$row['professor_usuario_id'])&&$this->access->hasActiveClassBinding((int)$row['professor_usuario_id'],(int)$row['turma_id_externo'],(int)$event['ano_letivo'],(string)$row['turno']))$valid[]=$row;$this->schedules->saveSnapshot((int)$event['id'],$valid);return['status'=>'CONFIGURADO','requirements'=>$this->schedules->obligations((int)$event['id'])];
    }

    private function matchNames(array$names,array$candidates):array
    {
        $indexed=[];foreach($candidates as$candidate)$indexed[$this->parser->normalize((string)$candidate['nome'])][]=$candidate;$result=[];foreach($names as$name){$key=$this->parser->normalize((string)$name);$exact=$indexed[$key]??[];if(count($exact)===1){$result[$key]=['status'=>'safe','id'=>(int)$exact[0]['id'],'name'=>(string)$exact[0]['nome'],'suggestions'=>[]];continue;}if(count($exact)>1){$result[$key]=['status'=>'ambiguous','id'=>null,'name'=>null,'suggestions'=>$exact];continue;}$scores=[];foreach($candidates as$candidate){$candidateKey=$this->parser->normalize((string)$candidate['nome']);$distance=levenshtein($key,$candidateKey);if($distance<=max(2,(int)floor(max(strlen($key),1)*.2)))$scores[]=$candidate+['distance'=>$distance];}usort($scores,static fn($a,$b)=>$a['distance']<=>$b['distance']);$result[$key]=['status'=>$scores?'unmatched':'not_found','id'=>null,'name'=>null,'suggestions'=>array_slice($scores,0,3)];}return$result;
    }

    private function canRecalculate(int$eventId):bool{if($this->schedules->obligationState($eventId)===null)return false;$statement=$this->schedules->db->prepare('SELECT 1 FROM apc_envios WHERE evento_id=:evento LIMIT 1');$statement->execute([':evento'=>$eventId]);return!$statement->fetchColumn();}

    private function date(mixed$value,string$label):string{$value=trim((string)$value);$date=\DateTimeImmutable::createFromFormat('!Y-m-d',$value);if(!$date||$date->format('Y-m-d')!==$value)throw new HttpException(422,'APC_SCHEDULE_DATE',$label.' inválido.');return$value;}
}
