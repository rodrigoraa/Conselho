<?php declare(strict_types=1);

namespace Apc\Services;

use Apc\Repositories\{AuditRepository,DeliveryRepository};
use Closure;
use Shared\Env;
use Shared\Exceptions\HttpException;

final class AttachmentService
{
    private const MIME_EXTENSIONS=['application/pdf'=>'pdf','image/jpeg'=>'jpg','image/png'=>'png','image/webp'=>'webp'];
    private readonly Closure $isUploaded;
    private readonly Closure $moveUploaded;

    public function __construct(private readonly DeliveryRepository $deliveries,private readonly AuditRepository $audit,private readonly AuthorizationService $authorization,private readonly string $uploadsPath,private readonly int $maxBytes,?Closure $isUploaded=null,?Closure $moveUploaded=null,private readonly ?EventWindow $eventWindow=null)
    {
        $this->isUploaded=$isUploaded??static fn(string $path):bool=>is_uploaded_file($path);
        $this->moveUploaded=$moveUploaded??static fn(string $from,string $to):bool=>move_uploaded_file($from,$to);
    }

    public static function fromEnvironment(DeliveryRepository $deliveries,AuditRepository $audit,AuthorizationService $authorization,string $root,?EventWindow $eventWindow=null): self
    {
        return new self($deliveries,$audit,$authorization,Env::get('APC_UPLOADS_PATH',$root.'/storage/apc-uploads')??'',Env::int('APC_UPLOAD_MAX_BYTES',10485760),null,null,$eventWindow);
    }

    public function storeMany(int $deliveryId,array $files,array $user,string $ip,string $userAgent): array
    {
        $delivery=$this->deliveries->find($deliveryId)??throw new HttpException(404,'APC_DELIVERY_NOT_FOUND','Entrega não encontrada.');
        $plan=$this->authorization->editablePlan((int)$delivery['plano_id'],(int)$user['id'],(string)$user['perfil']);$this->window()->assertOpen($plan);
        $limit=max(1,Env::int('APC_UPLOAD_MAX_FILES',5));if(!$files||count($files)>$limit)throw new HttpException(422,'APC_UPLOAD_COUNT','Selecione entre 1 e '.$limit.' arquivo(s) por envio.');
        $root=$this->ensureRoot();$stagingDir=$root.DIRECTORY_SEPARATOR.'.tmp';$this->ensureDirectory($stagingDir);$prepared=[];
        try{
            foreach($files as$file)$prepared[]=$this->prepare($file,$root,$stagingDir,$deliveryId,(int)$user['id']);
            $this->deliveries->db->beginTransaction();$ids=[];
            foreach($prepared as&$item){
                $id=$this->deliveries->addAttachment($item['metadata']);$item['id']=$id;
                $this->ensureDirectory(dirname($item['final']));if(!rename($item['staging'],$item['final']))throw new \RuntimeException('Não foi possível concluir o armazenamento privado do anexo.');$item['moved']=true;
                $this->audit->record((int)$user['id'],'UPLOAD','apc_anexos',$id,null,array_diff_key($item['metadata'],['caminho_relativo'=>true]),$ip,$userAgent);$ids[]=$id;
            }
            unset($item);$this->deliveries->db->commit();return$ids;
        }catch(\Throwable $exception){if($this->deliveries->db->inTransaction())$this->deliveries->db->rollBack();foreach($prepared as$item){foreach(['staging','final']as$key)if(isset($item[$key])&&is_file($item[$key]))@unlink($item[$key]);}if($exception instanceof HttpException)throw$exception;throw new HttpException(500,'APC_UPLOAD_FAILED','Não foi possível armazenar o anexo com segurança.');}
    }

    public function file(int $attachmentId,array $user): array
    {
        $attachment=$this->deliveries->attachment($attachmentId)??throw new HttpException(404,'APC_ATTACHMENT_NOT_FOUND','Anexo não encontrado.');
        $this->authorization->plan((int)$attachment['plano_id'],(int)$user['id'],(string)$user['perfil']);$attachment['caminho_absoluto']=$this->absolute((string)$attachment['caminho_relativo']);return$attachment;
    }

    public function fileForDeliveryRedirect(int $deliveryId): array
    {
        return$this->deliveries->find($deliveryId)??throw new HttpException(404,'APC_DELIVERY_NOT_FOUND','Entrega não encontrada.');
    }

    public function delete(int $attachmentId,array $user,string $ip,string $userAgent): int
    {
        $attachment=$this->file($attachmentId,$user);$plan=$this->authorization->editablePlan((int)$attachment['plano_id'],(int)$user['id'],(string)$user['perfil']);$this->window()->assertOpen($plan);
        $absolute=$attachment['caminho_absoluto'];$quarantine=$absolute.'.deleting-'.bin2hex(random_bytes(8));if(!rename($absolute,$quarantine))throw new HttpException(500,'APC_ATTACHMENT_DELETE_FAILED','Não foi possível preparar a remoção segura do anexo.');
        $this->deliveries->db->beginTransaction();
        try{$this->deliveries->deleteAttachment($attachmentId);$this->audit->record((int)$user['id'],'EXCLUIR','apc_anexos',$attachmentId,array_diff_key($attachment,['caminho_absoluto'=>true]),null,$ip,$userAgent);$this->deliveries->db->commit();}
        catch(\Throwable $exception){if($this->deliveries->db->inTransaction())$this->deliveries->db->rollBack();@rename($quarantine,$absolute);throw$exception;}
        if(!unlink($quarantine))error_log('Falha ao limpar anexo APC removido: '.$attachmentId);return(int)$attachment['plano_id'];
    }

    private function prepare(array $file,string $root,string $stagingDir,int $deliveryId,int $userId): array
    {
        if(($file['error']??UPLOAD_ERR_NO_FILE)!==UPLOAD_ERR_OK)throw new HttpException(422,'APC_UPLOAD_INVALID','Não foi possível receber um dos arquivos.');
        $temporary=(string)($file['tmp_name']??'');if($temporary===''||!($this->isUploaded)($temporary)||!is_file($temporary))throw new HttpException(422,'APC_UPLOAD_INVALID','O arquivo enviado é inválido.');
        $size=filesize($temporary);if($size===false||$size<1)throw new HttpException(422,'APC_UPLOAD_EMPTY','O arquivo enviado está vazio.');if($size>$this->maxBytes)throw new HttpException(413,'APC_UPLOAD_TOO_LARGE','O arquivo ultrapassa o tamanho máximo permitido.');
        $mime=(new \finfo(FILEINFO_MIME_TYPE))->file($temporary);if(!is_string($mime)||!isset(self::MIME_EXTENSIONS[$mime]))throw new HttpException(422,'APC_UPLOAD_TYPE','O arquivo enviado não é permitido.');
        $random=bin2hex(random_bytes(16));$stored=$random.'.'.self::MIME_EXTENSIONS[$mime];$relative=date('Y').'/'.date('m').'/'.$stored;$staging=$stagingDir.DIRECTORY_SEPARATOR.bin2hex(random_bytes(16)).'.tmp';
        if(!($this->moveUploaded)($temporary,$staging))throw new HttpException(500,'APC_UPLOAD_FAILED','Não foi possível mover o arquivo para o armazenamento privado.');
        $hash=hash_file('sha256',$staging);if($hash===false){@unlink($staging);throw new HttpException(500,'APC_UPLOAD_FAILED','Não foi possível verificar a integridade do arquivo.');}
        $original=$this->originalName((string)($file['name']??'arquivo'));
        return['staging'=>$staging,'final'=>$root.DIRECTORY_SEPARATOR.str_replace('/',DIRECTORY_SEPARATOR,$relative),'moved'=>false,'metadata'=>['entrega_id'=>$deliveryId,'nome_original'=>$original,'nome_armazenado'=>$stored,'mime_type'=>$mime,'tamanho_bytes'=>$size,'sha256'=>$hash,'caminho_relativo'=>$relative,'enviado_por'=>$userId]];
    }

    private function originalName(string $name): string
    {
        $name=basename(str_replace('\\','/',$name));$name=preg_replace('/[\x00-\x1F\x7F]+/u','',$name)??'';$name=trim($name);return mb_substr($name===''?'arquivo':$name,0,180);
    }

    private function absolute(string $relative): string
    {
        if(!preg_match('#^[0-9]{4}/[0-9]{2}/[a-f0-9]{32}\.(pdf|jpg|png|webp)$#D',$relative))throw new HttpException(404,'APC_ATTACHMENT_NOT_FOUND','Anexo não encontrado.');
        $path=$this->ensureRoot().DIRECTORY_SEPARATOR.str_replace('/',DIRECTORY_SEPARATOR,$relative);if(!is_file($path))throw new HttpException(404,'APC_ATTACHMENT_FILE_MISSING','O arquivo do anexo não está disponível.');return$path;
    }

    private function ensureRoot(): string
    {
        if(trim($this->uploadsPath)==='')throw new \RuntimeException('Diretório de uploads APC não configurado.');$this->ensureDirectory($this->uploadsPath);$root=realpath($this->uploadsPath);if($root===false)throw new \RuntimeException('Diretório de uploads APC indisponível.');return rtrim($root,'/\\');
    }

    private function ensureDirectory(string $directory): void
    {
        if(!is_dir($directory)&&!mkdir($directory,0770,true)&&!is_dir($directory))throw new \RuntimeException('Diretório privado do APC indisponível.');
    }

    private function window(): EventWindow{return$this->eventWindow??new EventWindow();}
}
