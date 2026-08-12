<?php declare(strict_types=1);

namespace Tests;

use Apc\Controllers\SubmissionController;
use Apc\Repositories\{AccessRepository,AuditRepository,EventRepository,SubmissionRepository,TermRepository};
use Apc\Services\SubmissionService;
use Shared\Exceptions\HttpException;
use Shared\Http\Request;
use Shared\Support\View;

final class ApcSubmissionPreviewTest extends ApcTestCase
{
    private string$directory;

    protected function setUp():void{$this->directory=sys_get_temp_dir().DIRECTORY_SEPARATOR.'apc-preview-'.bin2hex(random_bytes(6));mkdir($this->directory.DIRECTORY_SEPARATOR.'envios'.DIRECTORY_SEPARATOR.'2026'.DIRECTORY_SEPARATOR.'08',0770,true);}
    protected function tearDown():void{unset($_SESSION['user']);$_SERVER['REQUEST_URI']='/';$this->remove($this->directory);}

    public function testTeacherAndCoordinationCanPreviewAuthorizedPdfInline():void
    {
        $controller=$this->controller('application/pdf','pdf','%PDF-1.4 preview');$_SESSION['user']=['id'=>3,'nome'=>'Professor Um','perfil'=>'PROFESSOR'];$_SERVER['REQUEST_URI']='/apc/envios/1/visualizar';$preview=$controller->preview(new Request('GET','/apc/envios/1/visualizar',[],[],[]),['id'=>1]);self::assertStringContainsString('Conteúdo da APC',$preview->body);self::assertStringContainsString('/apc/envios/1/conteudo',$preview->body);self::assertStringContainsString('Visualizar envio',$preview->body);self::assertStringNotContainsString('/apc/envios/1/excluir',$preview->body);$content=$controller->content(new Request('GET','/apc/envios/1/conteudo',[],[],[]),['id'=>1]);self::assertSame('%PDF-1.4 preview',$content->body);self::assertStringStartsWith('inline;',$content->headers['Content-Disposition']);self::assertSame('SAMEORIGIN',$content->headers['X-Frame-Options']);$_SESSION['user']=['id'=>2,'nome'=>'Coordenação','perfil'=>'COORDENADOR'];$coordination=$controller->preview(new Request('GET','/apc/envios/1/visualizar',[],[],[]),['id'=>1]);self::assertStringContainsString('Professor Um',$coordination->body);self::assertStringContainsString('/apc/envios/1/excluir',$coordination->body);
    }

    public function testAnotherTeacherCannotPreviewAndUnsupportedDocumentUsesSafeFallback():void
    {
        $controller=$this->controller('application/vnd.openxmlformats-officedocument.wordprocessingml.document','docx','documento');$_SESSION['user']=['id'=>4,'nome'=>'Professor Dois','perfil'=>'PROFESSOR'];try{$controller->preview(new Request('GET','/apc/envios/1/visualizar',[],[],[]),['id'=>1]);self::fail('Outro professor não deveria visualizar este arquivo.');}catch(HttpException$exception){self::assertSame(403,$exception->status);}$_SESSION['user']=['id'=>3,'nome'=>'Professor Um','perfil'=>'PROFESSOR'];$preview=$controller->preview(new Request('GET','/apc/envios/1/visualizar',[],[],[]),['id'=>1]);self::assertStringContainsString('Este formato não abre diretamente no navegador',$preview->body);self::assertStringContainsString('Enviado por você para',$preview->body);try{$controller->content(new Request('GET','/apc/envios/1/conteudo',[],[],[]),['id'=>1]);self::fail('DOCX não deveria ser servido como conteúdo inline.');}catch(HttpException$exception){self::assertSame(415,$exception->status);self::assertSame('APC_PREVIEW_UNSUPPORTED',$exception->errorCode);}
    }

    private function controller(string$mime,string$extension,string$contents):SubmissionController
    {
        $main=$this->mainDatabase();$this->seedMain($main);$apc=$this->apcDatabase();$this->seedEvent($apc);$stored=str_repeat('a',32).'.'.$extension;$relative='envios/2026/08/'.$stored;file_put_contents($this->directory.DIRECTORY_SEPARATOR.str_replace('/',DIRECTORY_SEPARATOR,$relative),$contents);$statement=$apc->prepare("INSERT INTO apc_envios(id,evento_id,bimestre_id,professor_usuario_id,professor_nome_snapshot,etapa,ano_serie,nome_original,nome_armazenado,mime_type,tamanho_bytes,sha256,caminho_relativo,atrasado,dias_atraso,enviado_em)VALUES(1,1,3,3,'Professor Um','EF_AF','EF7',:nome,:armazenado,:mime,:tamanho,:sha,:caminho,0,0,'2026-08-11 10:00:00')");$statement->execute([':nome'=>'modelo-apc.'.$extension,':armazenado'=>$stored,':mime'=>$mime,':tamanho'=>strlen($contents),':sha'=>hash('sha256',$contents),':caminho'=>$relative]);$apc->exec("INSERT INTO apc_envio_turmas(envio_id,turma_id_externo,turma_nome_snapshot)VALUES(1,10,'7º A')");$repository=new SubmissionRepository($apc);$access=new AccessRepository($main);$service=new SubmissionService($repository,new EventRepository($apc),new TermRepository($apc),$access,new AuditRepository($apc),$this->directory,1048576,null,null,'2026-08-11');return new SubmissionController($service,$repository,$access,new View(dirname(__DIR__).'/apps/apc/resources/views'));
    }

    private function remove(string$path):void{if(!is_dir($path))return;foreach(scandir($path)?:[]as$item){if($item==='.'||$item==='..')continue;$target=$path.DIRECTORY_SEPARATOR.$item;if(is_dir($target))$this->remove($target);else unlink($target);}rmdir($path);}
}
