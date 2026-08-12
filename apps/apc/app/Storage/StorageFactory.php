<?php declare(strict_types=1);

namespace Apc\Storage;

use Google\Client;
use Google\Service\Drive;
use GuzzleHttp\Client as HttpClient;
use Shared\Env;

final class StorageFactory
{
    public static function fromEnvironment(string $root): StorageManager
    {
        $localPath=Env::get('APC_UPLOADS_PATH',$root.'/storage/apc-uploads')??'';$driver=mb_strtolower(trim(Env::get('APC_STORAGE_DRIVER','local')??'local'));
        $credentials=trim(Env::get('GOOGLE_DRIVE_CREDENTIALS_PATH','')??'');$sharedDriveId=trim(Env::get('GOOGLE_DRIVE_SHARED_DRIVE_ID','')??'');$rootFolderId=trim(Env::get('GOOGLE_DRIVE_ROOT_FOLDER_ID','')??'');$timeout=max(5,Env::int('GOOGLE_DRIVE_TIMEOUT',30));$chunk=self::chunkSize(Env::int('GOOGLE_DRIVE_UPLOAD_CHUNK_BYTES',1048576));
        return new StorageManager($driver,[
            'local'=>new LocalFileStorage($localPath),
            'google_drive'=>static function()use($credentials,$sharedDriveId,$rootFolderId,$timeout,$chunk):FileStorage{return self::googleDrive($credentials,$sharedDriveId,$rootFolderId,$timeout,$chunk);},
        ]);
    }

    private static function googleDrive(string $credentials,string $sharedDriveId,string $rootFolderId,int $timeout,int $chunk): GoogleDriveFileStorage
    {
        if($credentials===''||$sharedDriveId===''||$rootFolderId==='')throw new StorageException('A configuração privada do Google Drive está incompleta.');
        if(!is_file($credentials)||!is_readable($credentials))throw new StorageException('A credencial privada do Google Drive não está disponível para o PHP.');
        $json=file_get_contents($credentials);$decoded=is_string($json)?json_decode($json,true):null;
        if(!is_array($decoded)||($decoded['type']??'')!=='service_account'||empty($decoded['client_email'])||empty($decoded['private_key']))throw new StorageException('O arquivo de credencial do Google Drive é inválido.');
        try{$client=new Client();$client->setApplicationName('Conselho Escolar - APC');$client->setAuthConfig($decoded);$client->setScopes([Drive::DRIVE]);$client->setHttpClient(new HttpClient(['timeout'=>$timeout,'connect_timeout'=>min(10,$timeout)]));$drive=new Drive($client);return new GoogleDriveFileStorage($client,$drive,$sharedDriveId,$rootFolderId,$chunk);}
        catch(\Throwable $exception){throw new StorageException('Não foi possível iniciar o cliente privado do Google Drive.',false,$exception);}
    }

    private static function chunkSize(int $bytes): int
    {
        $unit=256*1024;$bytes=max($unit,$bytes);return(int)(floor($bytes/$unit)*$unit);
    }
}
