<?php declare(strict_types=1);

namespace Apc\Storage;

use Google\Service\Drive;
use Google\Service\Drive\DriveFile;

final class GoogleDriveFolderResolver
{
    public function __construct(private readonly Drive $drive,private readonly string $sharedDriveId) {}

    /** @param array<int, string> $folders */
    public function resolve(string $rootFolderId,array $folders): string
    {
        $parent=$rootFolderId;$logical=[];
        foreach($folders as$folder){$name=NameSanitizer::component($folder);$logical[]=$name;$key=hash('sha256',implode('/',array_map(static fn(string$value):string=>mb_strtolower($value),$logical)));$parent=$this->findOrCreate($parent,$name,$key);}
        return$parent;
    }

    private function findOrCreate(string $parentId,string $name,string $key): string
    {
        $existing=$this->find($parentId,$key);
        if($existing!==null)return$existing;
        try{
            $created=$this->drive->files->create(new DriveFile(['name'=>$name,'mimeType'=>'application/vnd.google-apps.folder','parents'=>[$parentId],'appProperties'=>['application'=>'conselho','module'=>'apc','folder_key'=>$key]]),['fields'=>'id','supportsAllDrives'=>true]);
            $createdId=(string)$created->getId();if($createdId==='')throw new StorageException('A API do Drive não retornou o ID da pasta.');
            $canonical=$this->find($parentId,$key);
            if($canonical!==null&&$canonical!==$createdId){try{$this->drive->files->delete($createdId,['supportsAllDrives'=>true]);}catch(\Throwable $exception){error_log('APC storage folder cleanup failed driver=google_drive file_id='.$createdId.' error='.get_class($exception));}return$canonical;}
            return$createdId;
        }catch(StorageException $exception){throw$exception;}
        catch(\Throwable $exception){throw new StorageException('Não foi possível criar a estrutura de pastas no Google Drive.',$this->temporary($exception),$exception);}
    }

    private function find(string $parentId,string $key): ?string
    {
        try{
            $query="mimeType='application/vnd.google-apps.folder' and trashed=false and '".$this->escape($parentId)."' in parents and appProperties has { key='folder_key' and value='".$this->escape($key)."' }";
            $result=$this->drive->files->listFiles(['q'=>$query,'corpora'=>'drive','driveId'=>$this->sharedDriveId,'includeItemsFromAllDrives'=>true,'supportsAllDrives'=>true,'spaces'=>'drive','pageSize'=>20,'orderBy'=>'createdTime,name','fields'=>'files(id,createdTime)']);
            $files=$result->getFiles();return$files?((string)$files[0]->getId()):null;
        }catch(\Throwable $exception){throw new StorageException('Não foi possível localizar uma pasta no Google Drive.',$this->temporary($exception),$exception);}
    }

    private function escape(string $value): string{return str_replace(['\\',"'"],['\\\\',"\\'"],$value);}
    private function temporary(\Throwable $exception): bool{return in_array((int)$exception->getCode(),[408,409,429],true)||(int)$exception->getCode()>=500;}
}
