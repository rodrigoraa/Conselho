<?php declare(strict_types=1);

namespace Apc\Storage;

use Closure;

final class StorageManager
{
    /** @var array<string, FileStorage|Closure():FileStorage> */
    private array $storages;

    /** @param array<string, FileStorage|Closure():FileStorage> $storages */
    public function __construct(private readonly string $configuredDriver,array $storages)
    {
        if(!in_array($configuredDriver,['local','google_drive'],true))throw new StorageException('Driver de armazenamento APC inválido.');
        $this->storages=$storages;
    }

    public function configuredDriver(): string{return$this->configuredDriver;}

    public function store(StagedUpload $upload,StorageContext $context,?string $driver=null): StoredFile
    {
        return$this->storage($driver??$this->configuredDriver)->store($upload,$context);
    }

    public function contents(array $record): string
    {
        try{return$this->storageFor($record)->contents($this->identifier($record));}
        catch(StorageException$exception){$fallback=$this->localFallback($record);if($fallback!==null)return$this->storage('local')->contents($fallback);throw$exception;}
    }

    public function exists(array $record): bool
    {
        try{$exists=$this->storageFor($record)->exists($this->identifier($record));if($exists)return true;}
        catch(StorageException$exception){$fallback=$this->localFallback($record);if($fallback===null)throw$exception;}
        $fallback=$this->localFallback($record);return$fallback!==null&&$this->storage('local')->exists($fallback);
    }

    public function beginDeletion(array $record): PendingDeletion
    {
        $primary=$this->storageFor($record)->beginDeletion($this->identifier($record));$fallback=$this->localFallback($record);
        if($fallback===null||!$this->storage('local')->exists($fallback))return$primary;
        try{$local=$this->storage('local')->beginDeletion($fallback);return new CompositePendingDeletion([$primary,$local]);}
        catch(\Throwable$exception){try{$primary->rollback();}catch(\Throwable){}throw$exception;}
    }

    public function deleteStored(StoredFile $file): void{$this->storage($file->driver)->delete($file->identifier);}

    public function deleteRecord(array $record): void{$this->storageFor($record)->delete($this->identifier($record));}

    /** @return array<string, string> */
    public function health(?string $driver=null): array{return$this->storage($driver??$this->configuredDriver)->healthCheck();}

    public function localAbsolutePath(array $record,bool $mustExist=true): ?string
    {
        if($this->recordDriver($record)!=='local')return null;
        $storage=$this->storage('local');
        return$storage instanceof LocalFileStorage?$storage->absolutePath($this->identifier($record),$mustExist):null;
    }

    public function storage(string $driver): FileStorage
    {
        $entry=$this->storages[$driver]??null;
        if($entry===null)throw new StorageException('O driver necessário para este arquivo não está configurado.');
        if($entry instanceof Closure){$entry=$entry();if(!$entry instanceof FileStorage)throw new StorageException('Factory de armazenamento inválida.');$this->storages[$driver]=$entry;}
        return$entry;
    }

    public function recordDriver(array $record): string
    {
        $driver=trim((string)($record['storage_driver']??''));return$driver===''?'local':$driver;
    }

    private function storageFor(array $record): FileStorage{return$this->storage($this->recordDriver($record));}

    private function localFallback(array$record):?string
    {
        if($this->recordDriver($record)!=='google_drive')return null;$relative=trim((string)($record['caminho_relativo']??''));return$relative===''?null:$relative;
    }

    private function identifier(array $record): string
    {
        $driver=$this->recordDriver($record);$identifier=$driver==='google_drive'?trim((string)($record['storage_file_id']??'')):trim((string)($record['caminho_relativo']??''));
        if($identifier==='')throw new StorageException('O registro não possui identificador de armazenamento.');
        return$identifier;
    }
}
