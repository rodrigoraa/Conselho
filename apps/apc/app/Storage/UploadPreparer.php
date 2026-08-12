<?php declare(strict_types=1);

namespace Apc\Storage;

use Closure;
use Shared\Exceptions\HttpException;

final class UploadPreparer
{
    private readonly Closure $isUploaded;
    private readonly Closure $moveUploaded;

    /** @param array<string, string> $mimeExtensions */
    public function __construct(private readonly string $stagingPath,private readonly int $maxBytes,private readonly array $mimeExtensions,?Closure $isUploaded=null,?Closure $moveUploaded=null)
    {
        $this->isUploaded=$isUploaded??static fn(string $path):bool=>is_uploaded_file($path);
        $this->moveUploaded=$moveUploaded??static fn(string $from,string $to):bool=>move_uploaded_file($from,$to);
    }

    public function prepare(array $file,string $relativeDirectory,string $typeMessage): StagedUpload
    {
        if(($file['error']??UPLOAD_ERR_NO_FILE)!==UPLOAD_ERR_OK)throw new HttpException(422,'APC_UPLOAD_INVALID','Selecione um arquivo válido.');
        $temporary=(string)($file['tmp_name']??'');
        if($temporary===''||!($this->isUploaded)($temporary)||!is_file($temporary))throw new HttpException(422,'APC_UPLOAD_INVALID','O arquivo enviado é inválido.');
        $size=filesize($temporary);
        if($size===false||$size<1)throw new HttpException(422,'APC_UPLOAD_EMPTY','O arquivo enviado está vazio.');
        if($size>$this->maxBytes)throw new HttpException(413,'APC_UPLOAD_TOO_LARGE','O arquivo ultrapassa o tamanho máximo permitido.');
        $mime=MimeDetector::detect($temporary);
        if(!is_string($mime)||!isset($this->mimeExtensions[$mime]))throw new HttpException(422,'APC_UPLOAD_TYPE',$typeMessage);
        $this->ensureDirectory($this->stagingPath);$stored=bin2hex(random_bytes(16)).'.'.$this->mimeExtensions[$mime];$staging=rtrim($this->stagingPath,'/\\').DIRECTORY_SEPARATOR.bin2hex(random_bytes(16)).'.upload';
        if(!($this->moveUploaded)($temporary,$staging))throw new HttpException(500,'APC_UPLOAD_FAILED','Não foi possível preparar o arquivo para armazenamento.');
        try{$hash=hash_file('sha256',$staging);if($hash===false)throw new \RuntimeException('hash');}
        catch(\Throwable $exception){@unlink($staging);throw new HttpException(500,'APC_UPLOAD_FAILED','Não foi possível verificar a integridade do arquivo.');}
        $relative=trim(str_replace('\\','/',$relativeDirectory),'/').'/'.$stored;
        return new StagedUpload($staging,$this->originalName((string)($file['name']??'arquivo')),$stored,$mime,(int)$size,$hash,$relative,bin2hex(random_bytes(16)));
    }

    public function cleanup(StagedUpload $upload): void{if(is_file($upload->path))@unlink($upload->path);}

    private function originalName(string $name): string
    {
        $name=basename(str_replace('\\','/',$name));$name=preg_replace('/[\x00-\x1F\x7F]+/u','',$name)??'';$name=trim($name);return mb_substr($name===''?'arquivo':$name,0,180);
    }

    private function ensureDirectory(string $directory): void
    {
        if(trim($directory)===''||(!is_dir($directory)&&!mkdir($directory,0770,true)&&!is_dir($directory)))throw new HttpException(500,'APC_UPLOAD_FAILED','O diretório temporário privado do APC está indisponível.');
    }
}
