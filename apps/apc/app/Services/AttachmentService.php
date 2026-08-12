<?php declare(strict_types=1);

namespace Apc\Services;

use Apc\Repositories\{AuditRepository,DeliveryRepository};
use Apc\Storage\{NameSanitizer,StorageContext,StorageException,StorageFactory,StorageManager,UploadPreparer};
use Closure;
use Shared\Env;
use Shared\Exceptions\HttpException;

final class AttachmentService
{
    private const MIME_EXTENSIONS=['application/pdf'=>'pdf','image/jpeg'=>'jpg','image/png'=>'png','image/webp'=>'webp'];
    private readonly StorageManager $storage;
    private readonly UploadPreparer $preparer;

    public function __construct(private readonly DeliveryRepository $deliveries,private readonly AuditRepository $audit,private readonly AuthorizationService $authorization,private readonly string $uploadsPath,private readonly int $maxBytes,?Closure $isUploaded=null,?Closure $moveUploaded=null,private readonly ?EventWindow $eventWindow=null,?StorageManager$storage=null,?string$stagingPath=null)
    {
        $this->storage=$storage??new StorageManager('local',['local'=>new \Apc\Storage\LocalFileStorage($uploadsPath)]);$this->preparer=new UploadPreparer($stagingPath??rtrim($uploadsPath,'/\\').'/.tmp',$maxBytes,self::MIME_EXTENSIONS,$isUploaded,$moveUploaded);
    }

    public static function fromEnvironment(DeliveryRepository $deliveries,AuditRepository $audit,AuthorizationService $authorization,string $root,?EventWindow $eventWindow=null,?StorageManager$storage=null): self
    {
        $uploads=Env::get('APC_UPLOADS_PATH',$root.'/storage/apc-uploads')??'';return new self($deliveries,$audit,$authorization,$uploads,Env::int('APC_UPLOAD_MAX_BYTES',10485760),null,null,$eventWindow,$storage??StorageFactory::fromEnvironment($root),Env::get('APC_STAGING_PATH',rtrim($uploads,'/\\').'/.tmp'));
    }

    public function storeMany(int $deliveryId,array $files,array $user,string $ip,string $userAgent): array
    {
        $delivery=$this->deliveries->find($deliveryId)??throw new HttpException(404,'APC_DELIVERY_NOT_FOUND','Entrega não encontrada.');
        $plan=$this->authorization->editablePlan((int)$delivery['plano_id'],(int)$user['id'],(string)$user['perfil']);$this->window()->assertOpen($plan);
        $limit=max(1,Env::int('APC_UPLOAD_MAX_FILES',5));if(!$files||count($files)>$limit)throw new HttpException(422,'APC_UPLOAD_COUNT','Selecione entre 1 e '.$limit.' arquivo(s) por envio.');
        $prepared=[];$stored=[];
        try{
            foreach($files as$file)$prepared[]=$this->preparer->prepare($file,date('Y').'/'.date('m'),'Envie somente arquivos PDF, JPEG, PNG ou WebP.');
            foreach($prepared as$index=>$upload){
                $context=new StorageContext('attachment',['APCs',(string)$delivery['ano_letivo'],'Entregas de alunos',(string)$delivery['evento_titulo'],(string)$delivery['turma_nome_snapshot'],(string)$delivery['professor_nome_snapshot'],(string)$delivery['aluno_nome_snapshot']],NameSanitizer::file('Anexo - '.$delivery['aluno_nome_snapshot'].' - '.$upload->originalName),['event_id'=>(string)$delivery['evento_id'],'teacher_id'=>(string)$delivery['professor_usuario_id'],'class_id'=>(string)$delivery['turma_id_externo'],'delivery_id'=>(string)$deliveryId]);
                try{$stored[$index]=$this->storage->store($upload,$context);}catch(StorageException$exception){$this->logFailure('upload',$this->storage->configuredDriver(),$exception,null);throw new HttpException(503,'APC_STORAGE_UNAVAILABLE','Não foi possível enviar os anexos neste momento. Tente novamente em alguns minutos.');}
            }
            $this->deliveries->db->beginTransaction();$ids=[];
            foreach($prepared as$index=>$upload){
                $metadata=['entrega_id'=>$deliveryId,'nome_original'=>$upload->originalName,'nome_armazenado'=>$upload->storedName,'mime_type'=>$upload->mimeType,'tamanho_bytes'=>$upload->size,'sha256'=>$upload->sha256,'enviado_por'=>(int)$user['id']]+$stored[$index]->databaseFields();
                $id=$this->deliveries->addAttachment($metadata);$this->audit->record((int)$user['id'],'UPLOAD','apc_anexos',$id,null,array_diff_key($metadata,['caminho_relativo'=>true])+['storage_file_id'=>$metadata['storage_file_id']],$ip,$userAgent);$ids[]=$id;
            }
            $this->deliveries->db->commit();return$ids;
        }catch(\Throwable $exception){if($this->deliveries->db->inTransaction())$this->deliveries->db->rollBack();foreach($stored as$file){try{$this->storage->deleteStored($file);}catch(\Throwable$cleanup){$this->logFailure('compensate_upload',$file->driver,$cleanup,null,$file->fileId);}}if($exception instanceof HttpException)throw$exception;throw new HttpException(500,'APC_UPLOAD_FAILED','Não foi possível armazenar o anexo com segurança.');}
        finally{foreach($prepared as$upload)$this->preparer->cleanup($upload);}
    }

    public function file(int $attachmentId,array $user): array
    {
        $attachment=$this->authorizedFile($attachmentId,$user);
        try{if(!$this->storage->exists($attachment))throw new HttpException(404,'APC_ATTACHMENT_FILE_MISSING','O arquivo do anexo não está disponível.');$absolute=$this->storage->localAbsolutePath($attachment);if($absolute!==null)$attachment['caminho_absoluto']=$absolute;return$attachment;}
        catch(HttpException$exception){throw$exception;}catch(StorageException$exception){$this->logFailure('exists',$this->storage->recordDriver($attachment),$exception,$attachmentId,(string)($attachment['storage_file_id']??''));throw new HttpException(503,'APC_STORAGE_UNAVAILABLE','O anexo está temporariamente indisponível. Tente novamente.');}
    }

    /** @return array{file:array<string,mixed>,contents:string} */
    public function contents(int$attachmentId,array$user):array
    {
        $file=$this->file($attachmentId,$user);try{return['file'=>$file,'contents'=>$this->storage->contents($file)];}catch(StorageException$exception){$this->logFailure('download',$this->storage->recordDriver($file),$exception,$attachmentId,(string)($file['storage_file_id']??''));throw new HttpException(503,'APC_STORAGE_UNAVAILABLE','O anexo está temporariamente indisponível. Tente novamente.');}
    }

    public function fileForDeliveryRedirect(int $deliveryId): array
    {
        return$this->deliveries->find($deliveryId)??throw new HttpException(404,'APC_DELIVERY_NOT_FOUND','Entrega não encontrada.');
    }

    public function delete(int $attachmentId,array $user,string $ip,string $userAgent): int
    {
        $attachment=$this->file($attachmentId,$user);$plan=$this->authorization->editablePlan((int)$attachment['plano_id'],(int)$user['id'],(string)$user['perfil']);$this->window()->assertOpen($plan);
        try{$pending=$this->storage->beginDeletion($attachment);}catch(StorageException$exception){$this->logFailure('begin_delete',$this->storage->recordDriver($attachment),$exception,$attachmentId,(string)($attachment['storage_file_id']??''));throw new HttpException(503,'APC_STORAGE_UNAVAILABLE','Não foi possível excluir o anexo neste momento. Tente novamente.');}
        $this->deliveries->db->beginTransaction();
        try{$this->deliveries->deleteAttachment($attachmentId);$this->audit->record((int)$user['id'],'EXCLUIR','apc_anexos',$attachmentId,array_diff_key($attachment,['caminho_absoluto'=>true]),null,$ip,$userAgent);$this->deliveries->db->commit();}
        catch(\Throwable $exception){if($this->deliveries->db->inTransaction())$this->deliveries->db->rollBack();try{$pending->rollback();}catch(\Throwable$rollback){$this->logFailure('rollback_delete',$this->storage->recordDriver($attachment),$rollback,$attachmentId,(string)($attachment['storage_file_id']??''));}throw$exception;}
        try{$pending->commit();}catch(\Throwable$exception){$this->logFailure('commit_delete',$this->storage->recordDriver($attachment),$exception,$attachmentId,(string)($attachment['storage_file_id']??''));}return(int)$attachment['plano_id'];
    }

    private function authorizedFile(int$id,array$user):array
    {
        $attachment=$this->deliveries->attachment($id)??throw new HttpException(404,'APC_ATTACHMENT_NOT_FOUND','Anexo não encontrado.');$this->authorization->plan((int)$attachment['plano_id'],(int)$user['id'],(string)$user['perfil']);return$attachment;
    }

    private function logFailure(string$operation,string$driver,\Throwable$exception,?int$id=null,?string$fileId=null):void
    {
        $summary=preg_replace('/\s+/u',' ',mb_substr($exception->getMessage(),0,180))??'erro';$parts=['APC storage failure','operation='.$operation,'driver='.$driver,'error='.get_class($exception),'summary='.json_encode($summary,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES)];if($id!==null)$parts[]='attachment_id='.$id;if($fileId!==null&&$fileId!=='')$parts[]='file_id='.$fileId;error_log(implode(' ',$parts));
    }

    private function window(): EventWindow{return$this->eventWindow??new EventWindow();}
}
