<?php declare(strict_types=1);

namespace Tests;

use Apc\Controllers\SubmissionController;
use Apc\Repositories\{AccessRepository,AuditRepository,EventRepository,SubmissionRepository,TermRepository};
use Apc\Services\SubmissionService;
use Shared\Http\Request;
use Shared\Support\View;

final class ApcSubmissionDashboardTest extends ApcTestCase
{
    protected function tearDown():void{unset($_SESSION['user'],$_SESSION['_csrf']);$_SERVER['REQUEST_URI']='/';}

    public function testTeacherSeesOnlySimpleUploadFieldsFromCouncilBindings():void
    {
        $main=$this->mainDatabase();$this->seedMain($main);$apc=$this->apcDatabase();$this->seedEvent($apc);$repository=new SubmissionRepository($apc);$access=new AccessRepository($main);$controller=new SubmissionController(new SubmissionService($repository,new EventRepository($apc),new TermRepository($apc),$access,new AuditRepository($apc),sys_get_temp_dir(),1048576,null,null,'2026-08-11'),$repository,$access,new View(dirname(__DIR__).'/apps/apc/resources/views'));$_SESSION['user']=['id'=>3,'nome'=>'Professor Um','perfil'=>'PROFESSOR'];$_SERVER['REQUEST_URI']='/apc';$response=$controller->index(new Request('GET','/apc',[],[],[]));self::assertStringContainsString('Anexar o modelo pronto',$response->body);self::assertStringContainsString('name="evento_id"',$response->body);self::assertStringContainsString('name="etapa"',$response->body);self::assertStringContainsString('name="ano_serie"',$response->body);self::assertStringContainsString('name="arquivo"',$response->body);self::assertStringContainsString('7º ano',$response->body);self::assertStringNotContainsString('Componentes curriculares',$response->body);self::assertStringNotContainsString('Entregas dos alunos',$response->body);
    }

    public function testAttachedAndLateStatusesRemainVisibleAfterUpload():void
    {
        $main=$this->mainDatabase();$this->seedMain($main);$apc=$this->apcDatabase();$this->seedEvent($apc);$apc->exec("INSERT INTO apc_envios(id,evento_id,bimestre_id,professor_usuario_id,professor_nome_snapshot,etapa,ano_serie,nome_original,nome_armazenado,mime_type,tamanho_bytes,sha256,caminho_relativo,atrasado,dias_atraso,enviado_em)VALUES(1,1,3,3,'Professor Um','EF_AF','EF7','modelo-apc.pdf','aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa.pdf','application/pdf',1200,'".str_repeat('b',64)."','envios/2026/08/aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa.pdf',1,5,'2026-08-20 10:00:00');INSERT INTO apc_envio_turmas(envio_id,turma_id_externo,turma_nome_snapshot)VALUES(1,10,'7º A')");$repository=new SubmissionRepository($apc);$access=new AccessRepository($main);$controller=new SubmissionController(new SubmissionService($repository,new EventRepository($apc),new TermRepository($apc),$access,new AuditRepository($apc),sys_get_temp_dir(),1048576,null,null,'2026-08-20'),$repository,$access,new View(dirname(__DIR__).'/apps/apc/resources/views'));$_SESSION['user']=['id'=>3,'nome'=>'Professor Um','perfil'=>'PROFESSOR'];$_SERVER['REQUEST_URI']='/apc';$response=$controller->index(new Request('GET','/apc',[],[],[]));self::assertStringContainsString('Arquivo anexado',$response->body);self::assertStringContainsString('Entregue com atraso · 5 dia(s)',$response->body);self::assertStringContainsString('modelo-apc.pdf',$response->body);self::assertStringContainsString('7º A',$response->body);
    }
}
