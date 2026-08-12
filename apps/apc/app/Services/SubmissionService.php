<?php declare(strict_types=1);

namespace Apc\Services;

use Apc\Repositories\{AccessRepository,AuditRepository,EventRepository,SubmissionRepository,TermRepository};
use Apc\Storage\{NameSanitizer,StorageContext,StorageException,StorageFactory,StorageManager,UploadPreparer};
use Closure;
use Shared\Env;
use Shared\Exceptions\HttpException;

final class SubmissionService
{
    private const STAGES=['EF_AI','EF_AF','EM'];
    private const YEARS=['EF1','EF2','EF3','EF4','EF5','EF6','EF7','EF8','EF9','EM1','EM2','EM3'];
    private const MIME_EXTENSIONS=['application/pdf'=>'pdf','application/msword'=>'doc','application/vnd.openxmlformats-officedocument.wordprocessingml.document'=>'docx','application/vnd.oasis.opendocument.text'=>'odt','image/jpeg'=>'jpg','image/png'=>'png','image/webp'=>'webp'];
    private readonly SubmissionWindow$window;
    private readonly StorageManager$storage;
    private readonly UploadPreparer$preparer;

    public function __construct(private readonly SubmissionRepository$submissions,private readonly EventRepository$events,private readonly TermRepository$terms,private readonly AccessRepository$access,private readonly AuditRepository$audit,private readonly string$uploadsPath,private readonly int$maxBytes,?Closure$isUploaded=null,?Closure$moveUploaded=null,private readonly?string$fixedToday=null,?StorageManager$storage=null,?string$stagingPath=null)
    {
        $this->window=new SubmissionWindow($terms,$fixedToday);$this->storage=$storage??new StorageManager('local',['local'=>new \Apc\Storage\LocalFileStorage($uploadsPath)]);$this->preparer=new UploadPreparer($stagingPath??rtrim($uploadsPath,'/\\').'/.tmp',$maxBytes,self::MIME_EXTENSIONS,$isUploaded,$moveUploaded);
    }

    public static function fromEnvironment(SubmissionRepository$submissions,EventRepository$events,TermRepository$terms,AccessRepository$access,AuditRepository$audit,string$root,?StorageManager$storage=null):self
    {
        $uploads=Env::get('APC_UPLOADS_PATH',$root.'/storage/apc-uploads')??'';return new self($submissions,$events,$terms,$access,$audit,$uploads,Env::int('APC_UPLOAD_MAX_BYTES',10485760),null,null,null,$storage??StorageFactory::fromEnvironment($root),Env::get('APC_STAGING_PATH',rtrim($uploads,'/\\').'/.tmp'));
    }

    public function availableEvents():array
    {
        $available=[];foreach($this->events->active()as$event){$window=$this->window->describe($event);if($window['is_open'])$available[]=$event+['submission_window'=>$window];}return$available;
    }

    public function currentTerm():?array{return$this->terms->containing($this->today());}

    /**
     * @param array<int, array<string, mixed>> $series
     * @param array<int, array<string, mixed>> $submissions
     * @return array{available: array<int, array<string, mixed>>, future: array<int, array<string, mixed>>, submitted_classes: array<int, array<int, int>>}
     */
    public function teacherDashboard(array$series,array$submissions):array
    {
        $submitted=[];foreach($submissions as$submission)foreach($this->submissionClassIds($submission)as$classId)$submitted[$classId][]=(int)$submission['evento_id'];
        $available=[];$future=[];
        foreach($this->events->active()as$event){
            $window=$this->window->describe($event);if(!$window['is_open'])continue;
            $requirements=[];$sentCount=0;
            foreach($series as$item)foreach($item['turmas']as$class){$classId=(int)$class['id'];$sent=in_array((int)$event['id'],$submitted[$classId]??[],true);if($sent)$sentCount++;$requirements[]=$item+['turma'=>$class,'turma_id_externo'=>$classId,'turma_nome'=>(string)$class['nome'],'sent'=>$sent];}
            $total=count($requirements);$status=$total===0?'SEM_VINCULO':($sentCount===$total?'COMPLETO':($sentCount>0?'PARCIAL':'PENDENTE'));
            $scheduled=array_merge($event,['submission_window'=>$window,'requirements'=>$requirements,'pending_classes'=>array_values(array_filter($requirements,static fn(array$requirement):bool=>!$requirement['sent'])),'sent_count'=>$sentCount,'total_count'=>$total,'status'=>$status]);
            if($window['is_open'])$available[]=$scheduled;else$future[]=$scheduled;
        }
        return['available'=>$available,'future'=>$future,'submitted_classes'=>$submitted];
    }

    /**
     * @return array{events: array<int, array<string, mixed>>, without_series: array<int, array<string, mixed>>}
     */
    public function tracking():array
    {
        $events=$this->availableEvents();$indexed=[];
        foreach($this->submissions->list(0,'COORDENADOR')as$submission)foreach($this->submissionClassIds($submission)as$classId)$indexed[(int)$submission['evento_id'].'|'.(int)$submission['professor_usuario_id'].'|'.$classId]=$submission;

        $roster=$this->access->submissionRoster();$withoutSeries=[];$trackedEvents=[];
        foreach($events as$event){
            foreach($roster['without_series']as$professor)$withoutSeries[(int)$professor['professor_usuario_id']]=$professor;

            $professors=[];
            foreach($roster['requirements']as$requirement){
                $userId=(int)$requirement['professor_usuario_id'];
                if(!isset($professors[$userId]))$professors[$userId]=['professor_usuario_id'=>$userId,'professor_nome'=>$requirement['professor_nome'],'requirements'=>[]];
                $key=(int)$event['id'].'|'.$userId.'|'.(int)$requirement['turma_id_externo'];$submission=$indexed[$key]??null;
                $professors[$userId]['requirements'][]=$requirement+['sent'=>$submission!==null,'submission'=>$submission];
            }

            $counts=['complete_count'=>0,'partial_count'=>0,'pending_count'=>0];
            foreach($professors as&$professor){
                $total=count($professor['requirements']);$sent=count(array_filter($professor['requirements'],static fn(array$requirement):bool=>$requirement['sent']));
                $professor['sent_count']=$sent;$professor['total_count']=$total;$professor['missing']=array_values(array_filter($professor['requirements'],static fn(array$requirement):bool=>!$requirement['sent']));
                if($sent===$total){$professor['status']='COMPLETO';$counts['complete_count']++;}elseif($sent>0){$professor['status']='PARCIAL';$counts['partial_count']++;}else{$professor['status']='PENDENTE';$counts['pending_count']++;}
            }unset($professor);
            $professors=array_values($professors);$order=['PENDENTE'=>0,'PARCIAL'=>1,'COMPLETO'=>2];
            usort($professors,static fn(array$a,array$b):int=>[$order[$a['status']],mb_strtoupper((string)$a['professor_nome'])]<=>[$order[$b['status']],mb_strtoupper((string)$b['professor_nome'])]);
            $trackedEvents[]=$event+['professors'=>$professors,'professor_count'=>count($professors),'incomplete_count'=>$counts['partial_count']+$counts['pending_count']]+$counts;
        }
        return['events'=>$trackedEvents,'without_series'=>array_values($withoutSeries)];
    }

    public function submit(array$input,array$file,array$user,string$ip,string$userAgent):int
    {
        $userId=(int)($user['id']??0);$role=(string)($user['perfil']??'');
        if($role!=='PROFESSOR'&&!$this->access->isActiveTeacher($userId))throw new HttpException(403,'APC_FORBIDDEN','Somente usuários com cadastro docente ativo podem enviar arquivos de APC.');
        $eventId=filter_var($input['evento_id']??null,FILTER_VALIDATE_INT,['options'=>['min_range'=>1]]);$event=$eventId?$this->events->find((int)$eventId):null;
        if(!$event||$event['status']!=='ATIVO')throw new HttpException(422,'APC_EVENT_NOT_FOUND','Selecione um evento APC válido.');
        $window=$this->window->assertOpen($event);$stage=trim((string)($input['etapa']??''));$year=trim((string)($input['ano_serie']??''));
        if(!in_array($stage,self::STAGES,true)||!in_array($year,self::YEARS,true)||$this->stageForYear($year)!==$stage)throw new HttpException(422,'APC_INVALID_SERIES','Selecione uma etapa e uma série válidas.');
        $classId=filter_var($input['turma_id']??null,FILTER_VALIDATE_INT,['options'=>['min_range'=>1]]);$classes=$this->access->classesForSeries($userId,'PROFESSOR',$stage,$year);$selectedClass=null;
        foreach($classes as$class)if((int)$class['id']===(int)$classId){$selectedClass=$class;break;}
        if($selectedClass===null)throw new HttpException(403,'APC_CLASS_FORBIDDEN','A turma selecionada não pertence a este professor, etapa e série.');
        if($this->submissions->existingForClass((int)$eventId,(int)$user['id'],(int)$classId))throw$this->alreadySubmitted();
        $upload=$this->preparer->prepare($file,'envios/'.date('Y').'/'.date('m'),'Envie um arquivo PDF, Word, ODT, JPEG, PNG ou WebP.');$stored=null;
        try{
            $context=new StorageContext('submission',['APCs',(string)$event['ano_letivo'],(int)$window['term']['numero'].'º Bimestre',(string)$event['titulo'],(string)$selectedClass['nome'],(string)$user['nome']],NameSanitizer::file('APC - '.$user['nome'].' - '.$selectedClass['nome'].' - '.$event['titulo'].' - '.$upload->originalName),['event_id'=>(string)$eventId,'teacher_id'=>(string)$user['id'],'class_id'=>(string)$classId]);
            try{$stored=$this->storage->store($upload,$context);}catch(StorageException$exception){$this->logFailure('upload',$this->storage->configuredDriver(),$exception);throw new HttpException(503,'APC_STORAGE_UNAVAILABLE','Não foi possível enviar o arquivo neste momento. Tente novamente em alguns minutos.');}
            $today=$this->today();$eventDate=new \DateTimeImmutable((string)$event['data']);$sentDate=new \DateTimeImmutable($today);$late=$sentDate>$eventDate;$days=$late?(int)$eventDate->diff($sentDate)->format('%a'):0;
            $data=['evento_id'=>(int)$eventId,'bimestre_id'=>(int)$window['term']['id'],'professor_usuario_id'=>(int)$user['id'],'professor_nome_snapshot'=>(string)$user['nome'],'etapa'=>$stage,'ano_serie'=>$year,'turma_id_externo'=>(int)$classId,'nome_original'=>$upload->originalName,'nome_armazenado'=>$upload->storedName,'mime_type'=>$upload->mimeType,'tamanho_bytes'=>$upload->size,'sha256'=>$upload->sha256,'atrasado'=>$late?1:0,'dias_atraso'=>$days,'enviado_em'=>$today.' '.date('H:i:s')]+$stored->databaseFields();
            $this->submissions->db->beginTransaction();
            try{$id=$this->submissions->save(null,$data);$this->submissions->syncClasses($id,[$selectedClass]);$this->audit->record((int)$user['id'],'ANEXAR_ARQUIVO_APC','apc_envios',$id,null,array_intersect_key($data,array_flip(['evento_id','etapa','ano_serie','turma_id_externo','nome_original','sha256','atrasado','dias_atraso','storage_driver','storage_file_id']))+['turma'=>$selectedClass['nome']],$ip,$userAgent);$this->submissions->db->commit();return$id;}
            catch(\Throwable$exception){if($this->submissions->db->inTransaction())$this->submissions->db->rollBack();try{$this->storage->deleteStored($stored);}catch(\Throwable$cleanup){$this->logFailure('compensate_upload',$stored->driver,$cleanup,null,$stored->fileId);}if($this->submissions->existingForClass((int)$eventId,(int)$user['id'],(int)$classId))throw$this->alreadySubmitted();if($exception instanceof HttpException)throw$exception;throw new HttpException(500,'APC_SUBMISSION_FAILED','Não foi possível armazenar o arquivo da APC com segurança.');}
        }finally{$this->preparer->cleanup($upload);}
    }

    public function file(int$id,array$user):array
    {
        $submission=$this->authorizedFile($id,$user);
        try{if(!$this->storage->exists($submission))throw new HttpException(404,'APC_SUBMISSION_FILE_MISSING','O arquivo da APC não está disponível.');$absolute=$this->storage->localAbsolutePath($submission);if($absolute!==null)$submission['caminho_absoluto']=$absolute;return$submission;}
        catch(HttpException$exception){throw$exception;}catch(StorageException$exception){$this->logFailure('exists',$this->storage->recordDriver($submission),$exception,$id,(string)($submission['storage_file_id']??''));throw new HttpException(503,'APC_STORAGE_UNAVAILABLE','O arquivo está temporariamente indisponível. Tente novamente em alguns minutos.');}
    }

    /** @return array{file:array<string,mixed>,contents:string} */
    public function contents(int$id,array$user):array
    {
        $file=$this->file($id,$user);try{return['file'=>$file,'contents'=>$this->storage->contents($file)];}catch(StorageException$exception){$this->logFailure('download',$this->storage->recordDriver($file),$exception,$id,(string)($file['storage_file_id']??''));throw new HttpException(503,'APC_STORAGE_UNAVAILABLE','O arquivo está temporariamente indisponível. Tente novamente em alguns minutos.');}
    }

    public function delete(int$id,array$user,string$ip,string$userAgent):void
    {
        if(!in_array((string)($user['perfil']??''),['COORDENADOR','ADMIN'],true))throw new HttpException(403,'APC_FORBIDDEN','Apenas a coordenação ou a administração pode excluir um envio de APC.');
        $submission=$this->authorizedFile($id,$user);
        try{$pending=$this->storage->beginDeletion($submission);}catch(StorageException$exception){$this->logFailure('begin_delete',$this->storage->recordDriver($submission),$exception,$id,(string)($submission['storage_file_id']??''));throw new HttpException(503,'APC_STORAGE_UNAVAILABLE','Não foi possível excluir o arquivo neste momento. Tente novamente.');}
        $this->submissions->db->beginTransaction();
        try{$this->submissions->delete($id);$this->audit->record((int)$user['id'],'EXCLUIR','apc_envios',$id,array_diff_key($submission,['caminho_relativo'=>true,'nome_armazenado'=>true]),null,$ip,$userAgent);$this->submissions->db->commit();}
        catch(\Throwable$exception){if($this->submissions->db->inTransaction())$this->submissions->db->rollBack();try{$pending->rollback();}catch(\Throwable$rollback){$this->logFailure('rollback_delete',$this->storage->recordDriver($submission),$rollback,$id,(string)($submission['storage_file_id']??''));}throw$exception;}
        try{$pending->commit();}catch(\Throwable$exception){$this->logFailure('commit_delete',$this->storage->recordDriver($submission),$exception,$id,(string)($submission['storage_file_id']??''));}
    }

    private function authorizedFile(int$id,array$user):array
    {
        $submission=$this->submissions->find($id)??throw new HttpException(404,'APC_SUBMISSION_NOT_FOUND','Arquivo APC não encontrado.');$role=(string)($user['perfil']??'');if($role==='PROFESSOR'&&(int)$submission['professor_usuario_id']!==(int)$user['id'])throw new HttpException(403,'APC_FORBIDDEN','Você não pode acessar o arquivo de outro professor.');if(!in_array($role,['PROFESSOR','COORDENADOR','ADMIN'],true))throw new HttpException(403,'APC_FORBIDDEN','Você não pode acessar este arquivo.');return$submission;
    }

    private function logFailure(string$operation,string$driver,\Throwable$exception,?int$id=null,?string$fileId=null):void
    {
        $summary=preg_replace('/\s+/u',' ',mb_substr($exception->getMessage(),0,180))??'erro';$parts=['APC storage failure','operation='.$operation,'driver='.$driver,'error='.get_class($exception),'summary='.json_encode($summary,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES)];if($id!==null)$parts[]='submission_id='.$id;if($fileId!==null&&$fileId!=='')$parts[]='file_id='.$fileId;error_log(implode(' ',$parts));
    }
    private function alreadySubmitted():HttpException{return new HttpException(409,'APC_SUBMISSION_ALREADY_EXISTS','A APC deste evento e turma já foi anexada. O reenvio não é permitido.');}
    private function stageForYear(string$year):string{return str_starts_with($year,'EM')?'EM':((int)substr($year,2)<=5?'EF_AI':'EF_AF');}
    /** @return array<int, int> */
    private function submissionClassIds(array$submission):array{$primary=(int)($submission['turma_id_externo']??0);if($primary>0)return[$primary];$ids=array_values(array_unique(array_filter(array_map('intval',explode(',',(string)($submission['turma_ids']??''))),static fn(int$id):bool=>$id>0)));return count($ids)===1?$ids:[];}
    private function today():string{return$this->fixedToday??date('Y-m-d');}
}
