<?php declare(strict_types=1);

namespace Tests;

use Apc\Controllers\{AttachmentController,SubmissionController};
use Apc\Repositories\{AccessRepository,AuditRepository,DeliveryRepository,EventRepository,PlanRepository,SubmissionRepository,TermRepository};
use Apc\Services\{AttachmentService,AuthorizationService,EventWindow,SubmissionService};
use Apc\Storage\{LocalFileStorage,StorageManager};
use Shared\Exceptions\HttpException;
use Shared\Http\Request;
use Shared\Support\View;
use Tests\Support\InMemoryFileStorage;

final class ApcStorageIntegrationTest extends ApcTestCase
{
    private string$directory;

    protected function setUp():void{$this->directory=sys_get_temp_dir().DIRECTORY_SEPARATOR.'apc-storage-'.bin2hex(random_bytes(6));mkdir($this->directory,0770,true);}
    protected function tearDown():void{unset($_SESSION['user']);$this->remove($this->directory);}

    public function testGoogleDriverStoresDownloadsPreviewsAuthorizesAndDeletesSubmission():void
    {
        [$db,$repository,$service,$fake,$access]=$this->submissionService();$teacher=['id'=>3,'nome'=>'Professor Um','perfil'=>'PROFESSOR'];$contents=$this->png();$id=$service->submit(['evento_id'=>1,'etapa'=>'EF_AF','ano_serie'=>'EF7','turma_id'=>10],$this->file('modelo.png',$contents),$teacher,'127.0.0.1','phpunit');$record=$repository->find($id);
        self::assertSame('google_drive',$record['storage_driver']);self::assertNotEmpty($record['storage_file_id']);self::assertNull($record['caminho_relativo']);self::assertSame(hash('sha256',$contents),$record['sha256']);self::assertSame($contents,$service->contents($id,$teacher)['contents']);
        try{$service->file($id,['id'=>4,'perfil'=>'PROFESSOR']);self::fail('Outro professor não deveria acessar o envio.');}catch(HttpException$exception){self::assertSame(403,$exception->status);}self::assertSame($id,(int)$service->file($id,['id'=>2,'perfil'=>'COORDENADOR'])['id']);
        $_SESSION['user']=$teacher;$controller=new SubmissionController($service,$repository,$access,new View(dirname(__DIR__).'/apps/apc/resources/views'));$inline=$controller->content(new Request('GET','/',[],[],[]),['id'=>$id]);self::assertSame('image/png',$inline->headers['Content-Type']);self::assertSame($contents,$inline->body);
        $service->delete($id,['id'=>1,'perfil'=>'ADMIN'],'127.0.0.1','phpunit');self::assertNull($repository->find($id));self::assertSame([],$fake->files);self::assertSame(1,$fake->deleteCount);
    }

    public function testGoogleFailureDoesNotCreateRecordAndDatabaseFailureCompensatesUpload():void
    {
        [$db,$repository,$service,$fake]=$this->submissionService();$teacher=['id'=>3,'nome'=>'Professor Um','perfil'=>'PROFESSOR'];$fake->failStore=true;
        try{$service->submit(['evento_id'=>1,'etapa'=>'EF_AF','ano_serie'=>'EF7','turma_id'=>10],$this->file('falha.png',$this->png()),$teacher,'127.0.0.1','phpunit');self::fail('A falha remota deveria impedir o envio.');}catch(HttpException$exception){self::assertSame(503,$exception->status);self::assertSame('APC_STORAGE_UNAVAILABLE',$exception->errorCode);}self::assertSame(0,(int)$db->query('SELECT COUNT(*) FROM apc_envios')->fetchColumn());
        $fake->failStore=false;$db->exec("CREATE TRIGGER fail_submission BEFORE INSERT ON apc_envios BEGIN SELECT RAISE(ABORT,'db failure'); END");
        try{$service->submit(['evento_id'=>1,'etapa'=>'EF_AF','ano_serie'=>'EF7','turma_id'=>10],$this->file('banco.png',$this->png()),$teacher,'127.0.0.1','phpunit');self::fail('A falha do banco deveria impedir o envio.');}catch(HttpException$exception){self::assertSame(500,$exception->status);}self::assertSame([],$fake->files);self::assertSame(1,$fake->deleteCount);self::assertSame(0,(int)$db->query('SELECT COUNT(*) FROM apc_envios')->fetchColumn());
    }

    public function testAttachmentServiceUsesSameGoogleStorageForDownloadAndDeletion():void
    {
        $main=$this->mainDatabase();$this->seedMain($main);$db=$this->apcDatabase();$this->seedEvent($db);$db->exec("INSERT INTO apc_planos(id,evento_id,professor_usuario_id,professor_nome_snapshot,turma_id_externo,turma_nome_snapshot,componente_curricular)VALUES(1,1,3,'Professor Um',10,'7º A','Matemática');INSERT INTO apc_entregas(id,plano_id,aluno_id_externo,aluno_nome_snapshot,entregue)VALUES(1,1,100,'Ana',1)");$fake=new InMemoryFileStorage();$manager=new StorageManager('google_drive',['local'=>new LocalFileStorage($this->directory),'google_drive'=>$fake]);$deliveries=new DeliveryRepository($db);$service=new AttachmentService($deliveries,new AuditRepository($db),new AuthorizationService(new PlanRepository($db),new AccessRepository($main)),$this->directory,1048576,static fn(string$path):bool=>is_file($path),static fn(string$from,string$to):bool=>rename($from,$to),new EventWindow('2026-08-11'),$manager);$teacher=['id'=>3,'nome'=>'Professor Um','perfil'=>'PROFESSOR'];$pdf="%PDF-1.4\n%%EOF";$id=$service->storeMany(1,[$this->file('atividade.pdf',$pdf)],$teacher,'127.0.0.1','phpunit')[0];$record=$deliveries->attachment($id);self::assertSame('google_drive',$record['storage_driver']);self::assertNull($record['caminho_relativo']);$_SESSION['user']=$teacher;$response=(new AttachmentController($service))->download(new Request('GET','/',[],[],[]),['id'=>$id]);self::assertSame($pdf,$response->body);$service->delete($id,$teacher,'127.0.0.1','phpunit');self::assertNull($deliveries->attachment($id));self::assertSame([],$fake->files);
    }

    private function submissionService():array
    {
        $main=$this->mainDatabase();$this->seedMain($main);$db=$this->apcDatabase();$this->seedEvent($db);$repository=new SubmissionRepository($db);$fake=new InMemoryFileStorage();$manager=new StorageManager('google_drive',['local'=>new LocalFileStorage($this->directory),'google_drive'=>$fake]);$access=new AccessRepository($main);$service=new SubmissionService($repository,new EventRepository($db),new TermRepository($db),$access,new AuditRepository($db),$this->directory,1048576,static fn(string$path):bool=>is_file($path),static fn(string$from,string$to):bool=>rename($from,$to),'2026-08-11',$manager);return[$db,$repository,$service,$fake,$access];
    }

    private function file(string$name,string$contents):array{$path=$this->directory.DIRECTORY_SEPARATOR.bin2hex(random_bytes(6)).'.upload';file_put_contents($path,$contents);return['name'=>$name,'tmp_name'=>$path,'error'=>UPLOAD_ERR_OK,'size'=>strlen($contents)];}
    private function png():string{return(string)base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mNk+A8AAQUBAScY42YAAAAASUVORK5CYII=');}
    private function remove(string$path):void{if(!is_dir($path))return;foreach(scandir($path)?:[]as$item){if($item==='.'||$item==='..')continue;$target=$path.DIRECTORY_SEPARATOR.$item;if(is_dir($target))$this->remove($target);else@unlink($target);}@rmdir($path);}
}
