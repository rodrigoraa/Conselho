<?php declare(strict_types=1);

namespace Tests;

use Apc\Repositories\{AccessRepository,AuditRepository,DeliveryRepository,EventRepository,SubmissionRepository,TermRepository};
use Apc\Services\{StorageMigrationService,SubmissionService};
use Apc\Storage\{LocalFileStorage,StorageManager};
use Tests\Support\InMemoryFileStorage;

final class ApcStorageMigrationServiceTest extends ApcTestCase
{
    private string$directory;

    protected function setUp():void{$this->directory=sys_get_temp_dir().DIRECTORY_SEPARATOR.'apc-migrate-'.bin2hex(random_bytes(6));mkdir($this->directory,0770,true);}
    protected function tearDown():void{$this->remove($this->directory);}

    public function testDryRunAndMigrationKeepLocalCopyByDefaultAndSkipMigratedRecord():void
    {
        $main=$this->mainDatabase();$this->seedMain($main);$db=$this->apcDatabase();$this->seedEvent($db);$repository=new SubmissionRepository($db);$fake=new InMemoryFileStorage();$manager=new StorageManager('local',['local'=>new LocalFileStorage($this->directory),'google_drive'=>$fake]);$service=new SubmissionService($repository,new EventRepository($db),new TermRepository($db),new AccessRepository($main),new AuditRepository($db),$this->directory,1048576,static fn(string$path):bool=>is_file($path),static fn(string$from,string$to):bool=>rename($from,$to),'2026-08-11',$manager);$contents=(string)base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mNk+A8AAQUBAScY42YAAAAASUVORK5CYII=');$upload=$this->directory.DIRECTORY_SEPARATOR.'source.png';file_put_contents($upload,$contents);$id=$service->submit(['evento_id'=>1,'etapa'=>'EF_AF','ano_serie'=>'EF7','turma_id'=>10],['name'=>'modelo.png','tmp_name'=>$upload,'error'=>UPLOAD_ERR_OK],['id'=>3,'nome'=>'Professor Um','perfil'=>'PROFESSOR'],'127.0.0.1','phpunit');$local=$repository->find($id);$localPath=$manager->localAbsolutePath($local);self::assertFileExists($localPath);
        $migration=new StorageMigrationService($repository,new DeliveryRepository($db),new AuditRepository($db),$manager);$dry=$migration->migrate('google_drive',['dry_run'=>true,'limit'=>10,'type'=>'submission']);self::assertSame(['selected'=>1,'migrated'=>0,'failed'=>0,'local_deleted'=>0],$dry);self::assertSame('local',$repository->find($id)['storage_driver']);
        $result=$migration->migrate('google_drive',['limit'=>10,'type'=>'submission']);self::assertSame(1,$result['migrated']);$remote=$repository->find($id);self::assertSame('google_drive',$remote['storage_driver']);self::assertSame($local['caminho_relativo'],$remote['caminho_relativo']);self::assertSame($contents,$fake->contents((string)$remote['storage_file_id']));self::assertFileExists($localPath);self::assertSame(0,$migration->migrate('google_drive',['limit'=>10,'type'=>'submission'])['selected']);self::assertSame(1,(int)$db->query("SELECT COUNT(*) FROM apc_auditoria WHERE acao='MIGRAR_STORAGE'")->fetchColumn());$service->delete($id,['id'=>1,'perfil'=>'ADMIN'],'127.0.0.1','phpunit');self::assertNull($repository->find($id));self::assertFileDoesNotExist($localPath);self::assertSame([],$fake->files);
    }

    private function remove(string$path):void{if(!is_dir($path))return;foreach(scandir($path)?:[]as$item){if($item==='.'||$item==='..')continue;$target=$path.DIRECTORY_SEPARATOR.$item;if(is_dir($target))$this->remove($target);else@unlink($target);}@rmdir($path);}
}
