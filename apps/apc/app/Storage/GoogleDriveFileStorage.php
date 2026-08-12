<?php declare(strict_types=1);

namespace Apc\Storage;

use Google\Client;
use Google\Http\MediaFileUpload;
use Google\Service\Drive;
use Google\Service\Drive\DriveFile;
use Psr\Http\Message\ResponseInterface;

final class GoogleDriveFileStorage implements FileStorage
{
    private readonly GoogleDriveFolderResolver $folders;

    public function __construct(private readonly Client $client,private readonly Drive $drive,private readonly string $sharedDriveId,private readonly string $rootFolderId,private readonly int $chunkBytes=1048576)
    {
        if(trim($sharedDriveId)===''||trim($rootFolderId)==='')throw new StorageException('A configuração do Google Drive está incompleta.');
        $this->folders=new GoogleDriveFolderResolver($drive,$sharedDriveId);
    }

    public function driver(): string{return'google_drive';}

    public function store(StagedUpload $upload,StorageContext $context): StoredFile
    {
        if(!is_file($upload->path))throw new StorageException('Arquivo temporário indisponível.');
        $folderId=$this->folders->resolve($this->rootFolderId,$context->folders);$properties=$this->properties($context,$upload);
        $existing=$this->findByOperation($folderId,$upload->operationId);
        if($existing!==null)return new StoredFile('google_drive',$existing,null,$existing,$folderId);
        $metadata=new DriveFile(['name'=>NameSanitizer::file($context->visibleName),'parents'=>[$folderId],'appProperties'=>$properties]);
        $this->client->setDefer(true);$handle=null;
        try{
            $request=$this->drive->files->create($metadata,['fields'=>'id,parents,name','supportsAllDrives'=>true]);
            $media=new MediaFileUpload($this->client,$request,$upload->mimeType,null,true,$this->chunkBytes);$media->setFileSize($upload->size);$handle=fopen($upload->path,'rb');if($handle===false)throw new StorageException('Não foi possível ler o arquivo temporário.');$status=false;
            while($status===false&&!feof($handle)){$chunk=fread($handle,$this->chunkBytes);if($chunk===false)throw new StorageException('Não foi possível ler um bloco do arquivo temporário.');if($chunk==='')break;$status=$media->nextChunk($chunk);}
            if(!$status instanceof DriveFile||trim((string)$status->getId())==='')throw new StorageException('O Google Drive não confirmou o upload do arquivo.',true);
            $fileId=(string)$status->getId();return new StoredFile('google_drive',$fileId,null,$fileId,$folderId);
        }catch(\Throwable $exception){
            try{$existing=$this->findByOperation($folderId,$upload->operationId);if($existing!==null)return new StoredFile('google_drive',$existing,null,$existing,$folderId);}catch(\Throwable){}
            if($exception instanceof StorageException)throw$exception;
            throw new StorageException('O Google Drive não concluiu o upload.',$this->temporary($exception),$exception);
        }finally{if(is_resource($handle))fclose($handle);$this->client->setDefer(false);}
    }

    public function contents(string $identifier): string
    {
        $this->assertIdentifier($identifier);
        try{$response=$this->drive->files->get($identifier,['alt'=>'media','supportsAllDrives'=>true]);if($response instanceof ResponseInterface)return$response->getBody()->getContents();if(method_exists($response,'getBody'))return(string)$response->getBody();throw new StorageException('Resposta de conteúdo inválida do Google Drive.');}
        catch(StorageException $exception){throw$exception;}catch(\Throwable $exception){throw new StorageException('Não foi possível ler o arquivo no Google Drive.',$this->temporary($exception),$exception);}
    }

    public function delete(string $identifier): void
    {
        $this->assertIdentifier($identifier);
        try{$this->drive->files->delete($identifier,['supportsAllDrives'=>true]);}
        catch(\Throwable $exception){throw new StorageException('Não foi possível excluir o arquivo no Google Drive.',$this->temporary($exception),$exception);}
    }

    public function exists(string $identifier): bool
    {
        try{$this->assertIdentifier($identifier);$file=$this->drive->files->get($identifier,['fields'=>'id,trashed','supportsAllDrives'=>true]);return!$file->getTrashed();}
        catch(\Throwable $exception){if((int)$exception->getCode()===404)return false;throw new StorageException('Não foi possível consultar o arquivo no Google Drive.',$this->temporary($exception),$exception);}
    }

    public function beginDeletion(string $identifier): PendingDeletion
    {
        $this->assertIdentifier($identifier);
        try{$this->drive->files->update($identifier,new DriveFile(['trashed'=>true]),['fields'=>'id,trashed','supportsAllDrives'=>true]);}
        catch(\Throwable $exception){throw new StorageException('Não foi possível preparar a exclusão no Google Drive.',$this->temporary($exception),$exception);}
        return new CallbackPendingDeletion(
            function()use($identifier):void{$this->delete($identifier);},
            function()use($identifier):void{try{$this->drive->files->update($identifier,new DriveFile(['trashed'=>false]),['fields'=>'id,trashed','supportsAllDrives'=>true]);}catch(\Throwable $exception){throw new StorageException('Não foi possível restaurar o arquivo no Google Drive.',$this->temporary($exception),$exception);}},
        );
    }

    public function healthCheck(): array
    {
        $createdId=null;
        try{
            $drive=$this->drive->drives->get($this->sharedDriveId,['fields'=>'id,name']);
            $root=$this->drive->files->get($this->rootFolderId,['fields'=>'id,name,mimeType,driveId,capabilities(canAddChildren)','supportsAllDrives'=>true]);
            if((string)$root->getDriveId()!==$this->sharedDriveId)throw new StorageException('A pasta raiz não pertence ao Drive Compartilhado configurado.');
            if((string)$root->getMimeType()!=='application/vnd.google-apps.folder')throw new StorageException('O ID raiz configurado não é uma pasta.');
            $probe=$this->drive->files->create(new DriveFile(['name'=>'.conselho-storage-check-'.bin2hex(random_bytes(6)).'.txt','parents'=>[$this->rootFolderId],'appProperties'=>['application'=>'conselho','module'=>'apc','record_type'=>'health_check']]),['data'=>'ok','mimeType'=>'text/plain','uploadType'=>'multipart','fields'=>'id','supportsAllDrives'=>true]);$createdId=(string)$probe->getId();if($createdId==='')throw new StorageException('O Google Drive não confirmou a escrita de teste.');$contents=$this->contents($createdId);if($contents!=='ok')throw new StorageException('A leitura de teste no Google Drive não corresponde ao conteúdo enviado.');$this->delete($createdId);$createdId=null;
            return['Driver'=>'google_drive','Credenciais'=>'OK','Shared Drive'=>'OK — '.NameSanitizer::component((string)$drive->getName()),'Pasta raiz'=>'OK — '.NameSanitizer::component((string)$root->getName()),'Permissão de leitura'=>'OK','Permissão de escrita'=>'OK'];
        }catch(StorageException $exception){throw$exception;}catch(\Throwable $exception){throw new StorageException('A verificação do Google Drive falhou.',$this->temporary($exception),$exception);}
        finally{if($createdId!==null){try{$this->delete($createdId);}catch(\Throwable $exception){error_log('APC storage health cleanup failed driver=google_drive file_id='.$createdId.' error='.get_class($exception));}}}
    }

    private function findByOperation(string $folderId,string $operationId): ?string
    {
        $query="trashed=false and '".$this->escape($folderId)."' in parents and appProperties has { key='operation_id' and value='".$this->escape($operationId)."' }";
        try{$result=$this->drive->files->listFiles(['q'=>$query,'corpora'=>'drive','driveId'=>$this->sharedDriveId,'includeItemsFromAllDrives'=>true,'supportsAllDrives'=>true,'spaces'=>'drive','pageSize'=>10,'fields'=>'files(id,createdTime)']);$files=$result->getFiles();return$files?((string)$files[0]->getId()):null;}
        catch(\Throwable $exception){throw new StorageException('Não foi possível verificar a idempotência do upload no Google Drive.',$this->temporary($exception),$exception);}
    }

    /** @return array<string, string> */
    private function properties(StorageContext $context,StagedUpload $upload): array
    {
        $properties=['application'=>'conselho','module'=>'apc','record_type'=>NameSanitizer::component($context->recordType,60),'operation_id'=>$upload->operationId,'sha256'=>$upload->sha256];
        foreach($context->appProperties as$key=>$value){$key=preg_replace('/[^a-zA-Z0-9_]+/','_',mb_substr((string)$key,0,60))??'';if($key!=='')$properties[$key]=mb_substr((string)$value,0,120);}
        return$properties;
    }

    private function assertIdentifier(string $identifier): void{if(!preg_match('/^[A-Za-z0-9_-]{5,200}$/D',$identifier))throw new StorageException('Identificador do Google Drive inválido.');}
    private function escape(string $value): string{return str_replace(['\\',"'"],['\\\\',"\\'"],$value);}
    private function temporary(\Throwable $exception): bool{return in_array((int)$exception->getCode(),[408,409,429],true)||(int)$exception->getCode()>=500;}
}
