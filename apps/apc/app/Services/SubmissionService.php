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

    public function submit(array$input,array$file,array$user,string$ip,string$userAgent):int
    {
        if(($user['perfil']??'')!=='PROFESSOR')throw new HttpException(403,'APC_FORBIDDEN','Somente professores podem enviar arquivos de APC.');$eventId=filter_var($input['evento_id']??null,FILTER_VALIDATE_INT,['options'=>['min_range'=>1]]);$event=$eventId?$this->events->find((int)$eventId):null;if(!$event||$event['status']!=='ATIVO')throw new HttpException(422,'APC_EVENT_NOT_FOUND','Selecione um evento APC válido.');$window=$this->window->assertOpen($event);$stage=trim((string)($input['etapa']??''));$year=trim((string)($input['ano_serie']??''));if(!in_array($stage,self::STAGES,true)||!in_array($year,self::YEARS,true)||$this->stageForYear($year)!==$stage)throw new HttpException(422,'APC_INVALID_SERIES','Selecione uma etapa e uma série válidas.');$classes=$this->access->classesForSeries((int)$user['id'],'PROFESSOR',$stage,$year);if(!$classes)throw new HttpException(403,'APC_SERIES_FORBIDDEN','A série selecionada não pertence às turmas vinculadas a este professor no Conselho.');$prepared=$this->prepare($file);$existing=$this->submissions->existing((int)$eventId,(int)$user['id'],$stage,$year);$today=$this->today();$eventDate=new \DateTimeImmutable((string)$event['data']);$sentDate=new \DateTimeImmutable($today);$late=$sentDate>$eventDate;$days=$late?(int)$eventDate->diff($sentDate)->format('%a'):0;$data=['evento_id'=>(int)$eventId,'bimestre_id'=>(int)$window['term']['id'],'professor_usuario_id'=>(int)$user['id'],'professor_nome_snapshot'=>(string)$user['nome'],'etapa'=>$stage,'ano_serie'=>$year,'nome_original'=>$prepared['nome_original'],'nome_armazenado'=>$prepared['nome_armazenado'],'mime_type'=>$prepared['mime_type'],'tamanho_bytes'=>$prepared['tamanho_bytes'],'sha256'=>$prepared['sha256'],'caminho_relativo'=>$prepared['caminho_relativo'],'atrasado'=>$late?1:0,'dias_atraso'=>$days,'enviado_em'=>$today.' '.date('H:i:s')];$oldPath=$existing?$this->absolute((string)$existing['caminho_relativo'],false):null;$quarantine=$oldPath&&is_file($oldPath)?$oldPath.'.replaced-'.bin2hex(random_bytes(8)):null;$final=$prepared['final'];
        $this->submissions->db->beginTransaction();
        try{
            if($quarantine&&!rename($oldPath,$quarantine))throw new \RuntimeException('Não foi possível preparar a substituição do arquivo anterior.');$id=$this->submissions->save($existing?(int)$existing['id']:null,$data);$this->submissions->syncClasses($id,$classes);$this->ensureDirectory(dirname($final));if(!rename($prepared['staging'],$final))throw new \RuntimeException('Não foi possível concluir o armazenamento do arquivo.');$this->audit->record((int)$user['id'],$existing?'SUBSTITUIR_ARQUIVO_APC':'ANEXAR_ARQUIVO_APC','apc_envios',$id,$existing?array_intersect_key($existing,array_flip(['nome_original','sha256','atrasado','dias_atraso'])):null,array_intersect_key($data,array_flip(['evento_id','etapa','ano_serie','nome_original','sha256','atrasado','dias_atraso']))+['turmas'=>array_column($classes,'nome')],$ip,$userAgent);$this->submissions->db->commit();if($quarantine&&is_file($quarantine)&&!unlink($quarantine))error_log('Falha ao limpar arquivo APC substituído: '.$id);return$id;
        }catch(\Throwable$exception){if($this->submissions->db->inTransaction())$this->submissions->db->rollBack();if(is_file($final))@unlink($final);if($quarantine&&is_file($quarantine))@rename($quarantine,$oldPath);if(is_file($prepared['staging']))@unlink($prepared['staging']);if($exception instanceof HttpException)throw$exception;throw new HttpException(500,'APC_SUBMISSION_FAILED','Não foi possível armazenar o arquivo da APC com segurança.');}
    }

    public function file(int$id,array$user):array
    {
        $submission=$this->submissions->find($id)??throw new HttpException(404,'APC_SUBMISSION_NOT_FOUND','Arquivo APC não encontrado.');$role=(string)($user['perfil']??'');if($role==='PROFESSOR'&&(int)$submission['professor_usuario_id']!==(int)$user['id'])throw new HttpException(403,'APC_FORBIDDEN','Você não pode acessar o arquivo de outro professor.');if(!in_array($role,['PROFESSOR','COORDENADOR','ADMIN'],true))throw new HttpException(403,'APC_FORBIDDEN','Você não pode acessar este arquivo.');$submission['caminho_absoluto']=$this->absolute((string)$submission['caminho_relativo']);return$submission;
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
    private function ensureRoot():string{if(trim($this->uploadsPath)==='')throw new \RuntimeException('Diretório de uploads APC não configurado.');$this->ensureDirectory($this->uploadsPath);$root=realpath($this->uploadsPath);if($root===false)throw new \RuntimeException('Diretório de uploads APC indisponível.');return rtrim($root,'/\\');}
    private function ensureDirectory(string$directory):void{if(!is_dir($directory)&&!mkdir($directory,0770,true)&&!is_dir($directory))throw new \RuntimeException('Diretório privado do APC indisponível.');}
    private function stageForYear(string$year):string{return str_starts_with($year,'EM')?'EM':((int)substr($year,2)<=5?'EF_AI':'EF_AF');}
    private function today():string{return$this->fixedToday??date('Y-m-d');}
}
