<?php declare(strict_types=1);

namespace Apc\Services;

use Apc\Repositories\{AccessRepository,AuditRepository,EventRepository,SubmissionRepository,TermRepository};
use Closure;
use Shared\Env;
use Shared\Exceptions\HttpException;

final class SubmissionService
{
    private const STAGES=['EF_AI','EF_AF','EM'];
    private const YEARS=['EF1','EF2','EF3','EF4','EF5','EF6','EF7','EF8','EF9','EM1','EM2','EM3'];
    private const MIME_EXTENSIONS=['application/pdf'=>'pdf','application/msword'=>'doc','application/vnd.openxmlformats-officedocument.wordprocessingml.document'=>'docx','application/vnd.oasis.opendocument.text'=>'odt','image/jpeg'=>'jpg','image/png'=>'png','image/webp'=>'webp'];
    private readonly Closure$isUploaded;
    private readonly Closure$moveUploaded;
    private readonly SubmissionWindow$window;

    public function __construct(private readonly SubmissionRepository$submissions,private readonly EventRepository$events,private readonly TermRepository$terms,private readonly AccessRepository$access,private readonly AuditRepository$audit,private readonly string$uploadsPath,private readonly int$maxBytes,?Closure$isUploaded=null,?Closure$moveUploaded=null,private readonly?string$fixedToday=null)
    {
        $this->isUploaded=$isUploaded??static fn(string$path):bool=>is_uploaded_file($path);$this->moveUploaded=$moveUploaded??static fn(string$from,string$to):bool=>move_uploaded_file($from,$to);$this->window=new SubmissionWindow($terms,$fixedToday);
    }

    public static function fromEnvironment(SubmissionRepository$submissions,EventRepository$events,TermRepository$terms,AccessRepository$access,AuditRepository$audit,string$root):self
    {
        return new self($submissions,$events,$terms,$access,$audit,Env::get('APC_UPLOADS_PATH',$root.'/storage/apc-uploads')??'',Env::int('APC_UPLOAD_MAX_BYTES',10485760));
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
            $window=$this->window->describe($event);if($window['state']==='SEM_BIMESTRE')continue;
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
        if(($user['perfil']??'')!=='PROFESSOR')throw new HttpException(403,'APC_FORBIDDEN','Somente professores podem enviar arquivos de APC.');$eventId=filter_var($input['evento_id']??null,FILTER_VALIDATE_INT,['options'=>['min_range'=>1]]);$event=$eventId?$this->events->find((int)$eventId):null;if(!$event||$event['status']!=='ATIVO')throw new HttpException(422,'APC_EVENT_NOT_FOUND','Selecione um evento APC válido.');$window=$this->window->assertOpen($event);$stage=trim((string)($input['etapa']??''));$year=trim((string)($input['ano_serie']??''));if(!in_array($stage,self::STAGES,true)||!in_array($year,self::YEARS,true)||$this->stageForYear($year)!==$stage)throw new HttpException(422,'APC_INVALID_SERIES','Selecione uma etapa e uma série válidas.');$classId=filter_var($input['turma_id']??null,FILTER_VALIDATE_INT,['options'=>['min_range'=>1]]);$classes=$this->access->classesForSeries((int)$user['id'],'PROFESSOR',$stage,$year);$selectedClass=null;foreach($classes as$class)if((int)$class['id']===(int)$classId){$selectedClass=$class;break;}if($selectedClass===null)throw new HttpException(403,'APC_CLASS_FORBIDDEN','A turma selecionada não pertence a este professor, etapa e série.');if($this->submissions->existingForClass((int)$eventId,(int)$user['id'],(int)$classId))throw$this->alreadySubmitted();$prepared=$this->prepare($file);$today=$this->today();$eventDate=new \DateTimeImmutable((string)$event['data']);$sentDate=new \DateTimeImmutable($today);$late=$sentDate>$eventDate;$days=$late?(int)$eventDate->diff($sentDate)->format('%a'):0;$data=['evento_id'=>(int)$eventId,'bimestre_id'=>(int)$window['term']['id'],'professor_usuario_id'=>(int)$user['id'],'professor_nome_snapshot'=>(string)$user['nome'],'etapa'=>$stage,'ano_serie'=>$year,'turma_id_externo'=>(int)$classId,'nome_original'=>$prepared['nome_original'],'nome_armazenado'=>$prepared['nome_armazenado'],'mime_type'=>$prepared['mime_type'],'tamanho_bytes'=>$prepared['tamanho_bytes'],'sha256'=>$prepared['sha256'],'caminho_relativo'=>$prepared['caminho_relativo'],'atrasado'=>$late?1:0,'dias_atraso'=>$days,'enviado_em'=>$today.' '.date('H:i:s')];$final=$prepared['final'];
        $this->submissions->db->beginTransaction();
        try{
            $id=$this->submissions->save(null,$data);$this->submissions->syncClasses($id,[$selectedClass]);$this->ensureDirectory(dirname($final));if(!rename($prepared['staging'],$final))throw new \RuntimeException('Não foi possível concluir o armazenamento do arquivo.');$this->audit->record((int)$user['id'],'ANEXAR_ARQUIVO_APC','apc_envios',$id,null,array_intersect_key($data,array_flip(['evento_id','etapa','ano_serie','turma_id_externo','nome_original','sha256','atrasado','dias_atraso']))+['turma'=>$selectedClass['nome']],$ip,$userAgent);$this->submissions->db->commit();return$id;
        }catch(\Throwable$exception){if($this->submissions->db->inTransaction())$this->submissions->db->rollBack();if(is_file($final))@unlink($final);if(is_file($prepared['staging']))@unlink($prepared['staging']);if($exception instanceof HttpException)throw$exception;if($this->submissions->existingForClass((int)$eventId,(int)$user['id'],(int)$classId))throw$this->alreadySubmitted();throw new HttpException(500,'APC_SUBMISSION_FAILED','Não foi possível armazenar o arquivo da APC com segurança.');}
    }

    public function file(int$id,array$user):array
    {
        $submission=$this->submissions->find($id)??throw new HttpException(404,'APC_SUBMISSION_NOT_FOUND','Arquivo APC não encontrado.');$role=(string)($user['perfil']??'');if($role==='PROFESSOR'&&(int)$submission['professor_usuario_id']!==(int)$user['id'])throw new HttpException(403,'APC_FORBIDDEN','Você não pode acessar o arquivo de outro professor.');if(!in_array($role,['PROFESSOR','COORDENADOR','ADMIN'],true))throw new HttpException(403,'APC_FORBIDDEN','Você não pode acessar este arquivo.');$submission['caminho_absoluto']=$this->absolute((string)$submission['caminho_relativo']);return$submission;
    }

    public function delete(int$id,array$user,string$ip,string$userAgent):void
    {
        if(!in_array((string)($user['perfil']??''),['COORDENADOR','ADMIN'],true))throw new HttpException(403,'APC_FORBIDDEN','Apenas a coordenação ou a administração pode excluir um envio de APC.');
        $submission=$this->submissions->find($id)??throw new HttpException(404,'APC_SUBMISSION_NOT_FOUND','Envio de APC não encontrado.');$absolute=$this->absolute((string)$submission['caminho_relativo'],false);$quarantine=null;
        if(is_file($absolute)){$quarantine=$absolute.'.deleting-'.bin2hex(random_bytes(8));if(!rename($absolute,$quarantine))throw new HttpException(500,'APC_SUBMISSION_DELETE_FAILED','Não foi possível preparar a remoção segura do arquivo.');}
        $this->submissions->db->beginTransaction();
        try{$this->submissions->delete($id);$this->audit->record((int)$user['id'],'EXCLUIR','apc_envios',$id,array_diff_key($submission,['caminho_relativo'=>true,'nome_armazenado'=>true]),null,$ip,$userAgent);$this->submissions->db->commit();}
        catch(\Throwable$exception){if($this->submissions->db->inTransaction())$this->submissions->db->rollBack();if($quarantine!==null&&is_file($quarantine))@rename($quarantine,$absolute);throw$exception;}
        if($quarantine!==null&&is_file($quarantine)&&!unlink($quarantine))error_log('Falha ao limpar arquivo de envio APC removido: '.$id);
    }

    private function prepare(array$file):array
    {
        if(($file['error']??UPLOAD_ERR_NO_FILE)!==UPLOAD_ERR_OK)throw new HttpException(422,'APC_UPLOAD_INVALID','Selecione um arquivo válido para a APC.');$temporary=(string)($file['tmp_name']??'');if($temporary===''||!($this->isUploaded)($temporary)||!is_file($temporary))throw new HttpException(422,'APC_UPLOAD_INVALID','O arquivo enviado é inválido.');$size=filesize($temporary);if($size===false||$size<1)throw new HttpException(422,'APC_UPLOAD_EMPTY','O arquivo está vazio.');if($size>$this->maxBytes)throw new HttpException(413,'APC_UPLOAD_TOO_LARGE','O arquivo ultrapassa o tamanho máximo permitido.');$mime=(new \finfo(FILEINFO_MIME_TYPE))->file($temporary);if(!is_string($mime)||!isset(self::MIME_EXTENSIONS[$mime]))throw new HttpException(422,'APC_UPLOAD_TYPE','Envie um arquivo PDF, Word, ODT, JPEG, PNG ou WebP.');$root=$this->ensureRoot();$stagingDir=$root.DIRECTORY_SEPARATOR.'.tmp';$this->ensureDirectory($stagingDir);$stored=bin2hex(random_bytes(16)).'.'.self::MIME_EXTENSIONS[$mime];$relative='envios/'.date('Y').'/'.date('m').'/'.$stored;$staging=$stagingDir.DIRECTORY_SEPARATOR.bin2hex(random_bytes(16)).'.tmp';if(!($this->moveUploaded)($temporary,$staging))throw new HttpException(500,'APC_SUBMISSION_FAILED','Não foi possível preparar o arquivo para armazenamento.');$hash=hash_file('sha256',$staging);if($hash===false){@unlink($staging);throw new HttpException(500,'APC_SUBMISSION_FAILED','Não foi possível verificar a integridade do arquivo.');}return['staging'=>$staging,'final'=>$root.DIRECTORY_SEPARATOR.str_replace('/',DIRECTORY_SEPARATOR,$relative),'caminho_relativo'=>$relative,'nome_original'=>$this->originalName((string)($file['name']??'arquivo')),'nome_armazenado'=>$stored,'mime_type'=>$mime,'tamanho_bytes'=>(int)$size,'sha256'=>$hash];
    }

    private function absolute(string$relative,bool$mustExist=true):string
    {
        if(!preg_match('#^envios/[0-9]{4}/[0-9]{2}/[a-f0-9]{32}\.(pdf|doc|docx|odt|jpg|png|webp)$#D',$relative))throw new HttpException(404,'APC_SUBMISSION_NOT_FOUND','Arquivo APC não encontrado.');$path=$this->ensureRoot().DIRECTORY_SEPARATOR.str_replace('/',DIRECTORY_SEPARATOR,$relative);if($mustExist&&!is_file($path))throw new HttpException(404,'APC_SUBMISSION_FILE_MISSING','O arquivo da APC não está disponível.');return$path;
    }
    private function originalName(string$name):string{$name=basename(str_replace('\\','/',$name));$name=preg_replace('/[\x00-\x1F\x7F]+/u','',$name)??'';$name=trim($name);return mb_substr($name===''?'arquivo':$name,0,180);}
    private function alreadySubmitted():HttpException{return new HttpException(409,'APC_SUBMISSION_ALREADY_EXISTS','A APC deste evento e turma já foi anexada. O reenvio não é permitido.');}
    private function ensureRoot():string{if(trim($this->uploadsPath)==='')throw new \RuntimeException('Diretório de uploads APC não configurado.');$this->ensureDirectory($this->uploadsPath);$root=realpath($this->uploadsPath);if($root===false)throw new \RuntimeException('Diretório de uploads APC indisponível.');return rtrim($root,'/\\');}
    private function ensureDirectory(string$directory):void{if(!is_dir($directory)&&!mkdir($directory,0770,true)&&!is_dir($directory))throw new \RuntimeException('Diretório privado do APC indisponível.');}
    private function stageForYear(string$year):string{return str_starts_with($year,'EM')?'EM':((int)substr($year,2)<=5?'EF_AI':'EF_AF');}
    /** @return array<int, int> */
    private function submissionClassIds(array$submission):array{$primary=(int)($submission['turma_id_externo']??0);if($primary>0)return[$primary];$ids=array_values(array_unique(array_filter(array_map('intval',explode(',',(string)($submission['turma_ids']??''))),static fn(int$id):bool=>$id>0)));return count($ids)===1?$ids:[];}
    private function today():string{return$this->fixedToday??date('Y-m-d');}
}
