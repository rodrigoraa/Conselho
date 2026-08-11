<?php declare(strict_types=1);

namespace Tests;

use Apc\Controllers\AttachmentController;
use Apc\Repositories\{AccessRepository,AuditRepository,DeliveryRepository,PlanRepository};
use Apc\Services\{AttachmentService,AuthorizationService};
use Shared\Exceptions\HttpException;
use Shared\Http\Request;

final class ApcAttachmentServiceTest extends ApcTestCase
{
    private string $directory;

    protected function setUp(): void
    {
        $this->directory=sys_get_temp_dir().DIRECTORY_SEPARATOR.'apc-test-'.bin2hex(random_bytes(8));mkdir($this->directory,0770,true);
    }

    protected function tearDown(): void
    {
        unset($_SESSION['user']);$this->removeDirectory($this->directory);
    }

    private function service(int $maxBytes=10485760): array
    {
        $main=$this->mainDatabase();$this->seedMain($main);$db=$this->apcDatabase();$this->seedEvent($db);$db->exec("INSERT INTO apc_planos(id,evento_id,professor_usuario_id,professor_nome_snapshot,turma_id_externo,turma_nome_snapshot,componente_curricular)VALUES(1,1,3,'Professor Um',10,'7º A','Matemática');INSERT INTO apc_entregas(id,plano_id,aluno_id_externo,aluno_nome_snapshot,entregue)VALUES(1,1,100,'Ana',1)");$deliveries=new DeliveryRepository($db);$authorization=new AuthorizationService(new PlanRepository($db),new AccessRepository($main));$service=new AttachmentService($deliveries,new AuditRepository($db),$authorization,$this->directory,$maxBytes,static fn(string$path):bool=>is_file($path),static fn(string$from,string$to):bool=>rename($from,$to));return[$db,$service];
    }

    public function testStoresPdfJpegAndPngWithRandomNamesHashAndAuthorizedPrivateDownload(): void
    {
        [$db,$service]=$this->service();$files=[
            $this->file('atividade.pdf',"%PDF-1.4\n1 0 obj\n<<>>\nendobj\n%%EOF"),
            $this->file('foto.jpg',(string)hex2bin('ffd8ffe000104a46494600010100000100010000ffd9')),
            $this->file('folha.png',(string)base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mNk+A8AAQUBAScY42YAAAAASUVORK5CYII=')),
        ];$teacher=['id'=>3,'nome'=>'Professor Um','perfil'=>'PROFESSOR'];$ids=$service->storeMany(1,$files,$teacher,'127.0.0.1','phpunit');self::assertCount(3,$ids);self::assertSame(3,(int)$db->query('SELECT COUNT(*) FROM apc_anexos')->fetchColumn());
        $attachment=$service->file($ids[0],$teacher);self::assertFileExists($attachment['caminho_absoluto']);self::assertFalse(str_starts_with((string)realpath($attachment['caminho_absoluto']),(string)realpath(dirname(__DIR__).'/apps/preconselho-web/public')));self::assertMatchesRegularExpression('/^[a-f0-9]{64}$/',$attachment['sha256']);self::assertNotSame($attachment['nome_original'],$attachment['nome_armazenado']);$_SESSION['user']=$teacher;$response=(new AttachmentController($service))->download(new Request('GET','/apc/anexos/'.$ids[0],[],[],[]),['id'=>(string)$ids[0]]);self::assertSame('application/pdf',$response->headers['Content-Type']);self::assertStringContainsString('attachment;',$response->headers['Content-Disposition']);self::assertStringStartsWith('%PDF-1.4',$response->body);
        $this->expectException(HttpException::class);$service->file($ids[0],['id'=>4,'nome'=>'Professor Dois','perfil'=>'PROFESSOR']);
    }

    public function testRejectsFalseMimeAndOversizedFile(): void
    {
        [, $service]=$this->service();$teacher=['id'=>3,'nome'=>'Professor Um','perfil'=>'PROFESSOR'];
        try{$service->storeMany(1,[$this->file('disfarce.png','<?php echo 1;')],$teacher,'127.0.0.1','phpunit');self::fail('MIME falso deveria ser recusado.');}catch(HttpException $exception){self::assertSame('APC_UPLOAD_TYPE',$exception->errorCode);}
        [, $smallService]=$this->service(4);$this->expectException(HttpException::class);$smallService->storeMany(1,[$this->file('grande.pdf',"%PDF-1.4\nconteudo")],$teacher,'127.0.0.1','phpunit');
    }

    public function testMultipleAttachmentsAndPathTraversalAreControlledByDatabaseMetadataValidation(): void
    {
        [$db,$service]=$this->service();$teacher=['id'=>3,'nome'=>'Professor Um','perfil'=>'PROFESSOR'];$ids=$service->storeMany(1,[$this->file('a.png',(string)base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mNk+A8AAQUBAScY42YAAAAASUVORK5CYII=')),$this->file('b.png',(string)base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mNk+A8AAQUBAScY42YAAAAASUVORK5CYII='))],$teacher,'127.0.0.1','phpunit');self::assertCount(2,$ids);
        $db->exec("INSERT INTO apc_anexos(entrega_id,nome_original,nome_armazenado,mime_type,tamanho_bytes,sha256,caminho_relativo,enviado_por)VALUES(1,'malicioso.pdf','malicioso.pdf','application/pdf',10,'abc','../../segredo.pdf',3)");$id=(int)$db->lastInsertId();$this->expectException(HttpException::class);$service->file($id,$teacher);
    }

    public function testFinalizedPlanPreventsAttachmentRemoval(): void
    {
        [$db,$service]=$this->service();$teacher=['id'=>3,'nome'=>'Professor Um','perfil'=>'PROFESSOR'];$id=$service->storeMany(1,[$this->file('a.png',(string)base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mNk+A8AAQUBAScY42YAAAAASUVORK5CYII='))],$teacher,'127.0.0.1','phpunit')[0];$path=$service->file($id,$teacher)['caminho_absoluto'];$db->exec("UPDATE apc_planos SET status='FINALIZADO'");
        try{$service->delete($id,$teacher,'127.0.0.1','phpunit');self::fail('Remoção deveria ser bloqueada.');}catch(HttpException $exception){self::assertSame('APC_PLAN_LOCKED',$exception->errorCode);}self::assertFileExists($path);
    }

    private function file(string $name,string $contents): array
    {
        $path=$this->directory.DIRECTORY_SEPARATOR.bin2hex(random_bytes(6)).'.upload';file_put_contents($path,$contents);return['name'=>$name,'tmp_name'=>$path,'error'=>UPLOAD_ERR_OK,'size'=>strlen($contents),'type'=>'application/octet-stream'];
    }

    private function removeDirectory(string $directory): void
    {
        if(!is_dir($directory))return;foreach(scandir($directory)?:[]as$item){if($item==='.'||$item==='..')continue;$path=$directory.DIRECTORY_SEPARATOR.$item;if(is_dir($path))$this->removeDirectory($path);else@unlink($path);}@rmdir($directory);
    }
}
