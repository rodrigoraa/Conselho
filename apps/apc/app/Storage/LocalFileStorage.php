<?php declare(strict_types=1);

namespace Apc\Storage;

final class LocalFileStorage implements FileStorage
{
    public function __construct(private readonly string $rootPath) {}

    public function driver(): string{return'local';}

    public function store(StagedUpload $upload,StorageContext $context): StoredFile
    {
        if(!is_file($upload->path))throw new StorageException('Arquivo temporário indisponível.');
        $target=$this->resolve($upload->relativePath,false);$this->ensureDirectory(dirname($target));
        if(is_file($target))throw new StorageException('Já existe um arquivo com o identificador local gerado.');
        $temporary=$target.'.writing-'.bin2hex(random_bytes(8));
        try{
            $source=fopen($upload->path,'rb');$destination=fopen($temporary,'xb');
            if($source===false||$destination===false)throw new StorageException('Não foi possível abrir o armazenamento local.');
            try{if(stream_copy_to_stream($source,$destination)!==$upload->size)throw new StorageException('A cópia local ficou incompleta.');}
            finally{fclose($source);fclose($destination);}
            if(!rename($temporary,$target))throw new StorageException('Não foi possível concluir o armazenamento local.');
        }catch(\Throwable $exception){if(is_file($temporary))@unlink($temporary);if($exception instanceof StorageException)throw$exception;throw new StorageException('Falha ao armazenar o arquivo localmente.',false,$exception);}
        return new StoredFile('local',$upload->relativePath,$upload->relativePath);
    }

    public function contents(string $identifier): string
    {
        $path=$this->resolve($identifier);$contents=file_get_contents($path);
        if($contents===false)throw new StorageException('Não foi possível ler o arquivo local.');
        return$contents;
    }

    public function delete(string $identifier): void
    {
        $path=$this->resolve($identifier,false);
        if(is_file($path)&&!unlink($path))throw new StorageException('Não foi possível excluir o arquivo local.');
    }

    public function exists(string $identifier): bool
    {
        try{return is_file($this->resolve($identifier,false));}catch(StorageException){return false;}
    }

    public function beginDeletion(string $identifier): PendingDeletion
    {
        $path=$this->resolve($identifier,false);
        if(!is_file($path))return new CallbackPendingDeletion(static function():void{},static function():void{});
        $quarantine=$path.'.deleting-'.bin2hex(random_bytes(8));
        if(!rename($path,$quarantine))throw new StorageException('Não foi possível preparar a exclusão do arquivo local.');
        return new CallbackPendingDeletion(
            static function()use($quarantine):void{if(is_file($quarantine)&&!unlink($quarantine))throw new StorageException('Não foi possível concluir a exclusão local.');},
            static function()use($quarantine,$path):void{if(is_file($quarantine)&&!rename($quarantine,$path))throw new StorageException('Não foi possível restaurar o arquivo local.');},
        );
    }

    public function healthCheck(): array
    {
        $root=$this->root();$probe=$root.DIRECTORY_SEPARATOR.'.storage-check-'.bin2hex(random_bytes(6));
        if(file_put_contents($probe,'ok',LOCK_EX)!==2)throw new StorageException('O armazenamento local não permite escrita.');
        $read=file_get_contents($probe);@unlink($probe);
        if($read!=='ok')throw new StorageException('O armazenamento local não permite leitura.');
        return['Driver'=>'local','Diretório'=>'OK','Permissão de leitura'=>'OK','Permissão de escrita'=>'OK'];
    }

    public function absolutePath(string $identifier,bool $mustExist=true): string{return$this->resolve($identifier,$mustExist);}

    private function resolve(string $relative,bool $mustExist=true): string
    {
        $relative=str_replace('\\','/',$relative);
        if($relative===''||str_starts_with($relative,'/')||preg_match('/^[a-zA-Z]:/',$relative)||str_contains($relative,"\0"))throw new StorageException('Identificador local inválido.');
        $segments=explode('/',$relative);
        foreach($segments as$segment)if($segment===''||$segment==='.'||$segment==='..')throw new StorageException('Identificador local inválido.');
        $root=$this->root();$path=$root.DIRECTORY_SEPARATOR.implode(DIRECTORY_SEPARATOR,$segments);
        $parent=dirname($path);if(is_dir($parent)){$realParent=realpath($parent);if($realParent===false||!$this->insideRoot($root,$realParent))throw new StorageException('Caminho local fora do diretório privado.');}
        if($mustExist&&!is_file($path))throw new StorageException('Arquivo local não encontrado.');
        return$path;
    }

    private function root(): string
    {
        if(trim($this->rootPath)==='')throw new StorageException('Armazenamento local não configurado.');
        $this->ensureDirectory($this->rootPath);$root=realpath($this->rootPath);
        if($root===false)throw new StorageException('Armazenamento local indisponível.');
        return rtrim($root,'/\\');
    }

    private function insideRoot(string $root,string $path): bool
    {
        $root=rtrim(str_replace('\\','/',$root),'/').'/';$path=rtrim(str_replace('\\','/',$path),'/').'/';
        return str_starts_with(mb_strtolower($path),mb_strtolower($root));
    }

    private function ensureDirectory(string $directory): void
    {
        if(!is_dir($directory)&&!mkdir($directory,0770,true)&&!is_dir($directory))throw new StorageException('Diretório privado do APC indisponível.');
    }
}
