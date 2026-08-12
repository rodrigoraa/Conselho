<?php declare(strict_types=1);

namespace Tests;

use Apc\Repositories\AccessRepository;
use PreConselho\Controllers\ManagementController;
use PreConselho\Integration\SecretariaApiClient;
use PreConselho\Repositories\AppRepository;
use Shared\Http\Request;
use Shared\Support\View;

final class DualRoleManagementTest extends ApcTestCase
{
    protected function tearDown():void{unset($_SESSION['user'],$_SESSION['_csrf'],$_SESSION['flash']);}

    public function testTeacherCanReceiveCoordinationProfileWithoutLosingTeachingBindings():void
    {
        $db=$this->mainDatabase();$this->seedMain($db);$_SESSION['user']=['id'=>1,'nome'=>'Admin','perfil'=>'ADMIN'];$_SESSION['_csrf']='token';
        $controller=new ManagementController(new AppRepository($db),new SecretariaApiClient(),new View(dirname(__DIR__).'/apps/preconselho-web/resources/views'));
        $request=new Request('POST','/admin/usuarios/3/editar',[],['_csrf'=>'token','nome'=>'Professor Um','cpf'=>'123.456.789-09','perfil'=>'COORDENADOR'],['REMOTE_ADDR'=>'127.0.0.1','HTTP_USER_AGENT'=>'phpunit']);$response=$controller->editUser($request,['id'=>'3']);
        self::assertSame(302,$response->status);self::assertSame('COORDENADOR',$db->query('SELECT perfil FROM usuarios WHERE id=3')->fetchColumn());self::assertSame(1,(int)$db->query('SELECT ativo FROM professores WHERE usuario_id=3')->fetchColumn());self::assertTrue((new AccessRepository($db))->isActiveTeacher(3));self::assertCount(1,(new AccessRepository($db))->classesFor(3,'PROFESSOR'));
    }
}
