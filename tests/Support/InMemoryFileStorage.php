<?php declare(strict_types=1);

namespace Tests\Support;

use Apc\Storage\{CallbackPendingDeletion,FileStorage,PendingDeletion,StagedUpload,StorageContext,StorageException,StoredFile};

final class InMemoryFileStorage implements FileStorage
{
    /** @var array<string, string> */
    public array $files=[];
    public int $deleteCount=0;
    public bool $failStore=false;

    public function driver(): string{return'google_drive';}

    public function store(StagedUpload$upload,StorageContext$context):StoredFile
    {
        if($this->failStore)throw new StorageException('Falha simulada.',true);$contents=file_get_contents($upload->path);if($contents===false)throw new StorageException('Staging ausente.');$id='drive-file-'.(count($this->files)+1).'-'.substr($upload->operationId,0,8);$this->files[$id]=$contents;return new StoredFile('google_drive',$id,null,$id,'drive-folder-1');
    }

    public function contents(string$identifier):string
    {
        return$this->files[$identifier]??throw new StorageException('Arquivo não encontrado.');
    }

    public function delete(string$identifier):void{unset($this->files[$identifier]);$this->deleteCount++;}
    public function exists(string$identifier):bool{return isset($this->files[$identifier]);}

    public function beginDeletion(string$identifier):PendingDeletion
    {
        if(!isset($this->files[$identifier]))return new CallbackPendingDeletion(static function():void{},static function():void{});$contents=$this->files[$identifier];unset($this->files[$identifier]);return new CallbackPendingDeletion(function()use($identifier):void{$this->deleteCount++;},function()use($identifier,$contents):void{$this->files[$identifier]=$contents;});
    }

    public function healthCheck():array{return['Driver'=>'google_drive','Credenciais'=>'OK','Shared Drive'=>'OK','Pasta raiz'=>'OK','Permissão de leitura'=>'OK','Permissão de escrita'=>'OK'];}
}
