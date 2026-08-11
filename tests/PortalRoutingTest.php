<?php declare(strict_types=1);

namespace Tests;

use PreConselho\Controllers\{PortalController,WebController};
use PreConselho\Integration\SecretariaApiClient;
use PreConselho\Middlewares\AuthMiddleware;
use PreConselho\Repositories\AppRepository;
use PreConselho\Support\Csrf;
use Shared\Http\{Request,Response,Router};
use Shared\Support\View;

final class PortalRoutingTest extends ApcTestCase
{
    protected function tearDown(): void
    {
        unset($_SESSION['user'],$_SESSION['_csrf']);$_SERVER['REQUEST_URI']='/';
    }

    public function testPortalAndApcRoutesRemainProtectedWithoutAuthentication(): void
    {
        unset($_SESSION['user']);
        $router=new Router();$auth=[new AuthMiddleware()];$router->add('GET','/',fn()=>new Response('portal'),$auth);$router->add('GET','/apc',fn()=>new Response('apc'),$auth);$router->add('GET','/apc/anexos/{id}',fn()=>new Response('arquivo'),$auth);
        foreach(['/','/apc','/apc/anexos/1']as$path){$response=$router->dispatch(new Request('GET',$path,[],[],[]));self::assertSame(302,$response->status);self::assertSame('/login',$response->headers['Location']);}
    }

    public function testPortalContainsBothSystemCardsAndCouncilAliasOpensOldHandler(): void
    {
        $_SESSION['user']=['id'=>1,'nome'=>'Rodrigo Silva','perfil'=>'ADMIN'];$_SERVER['REQUEST_URI']='/';$view=new View(dirname(__DIR__).'/apps/preconselho-web/resources/views');$portal=(new PortalController($view))->dashboard();self::assertStringContainsString('Conselho de Classe',$portal->body);self::assertStringContainsString('Acessar Conselho',$portal->body);self::assertStringContainsString('APCs',$portal->body);self::assertStringContainsString('Acessar APCs',$portal->body);
        $db=$this->mainDatabase();$this->seedMain($db);$_SERVER['REQUEST_URI']='/conselho';$web=new WebController(new AppRepository($db),$view,new SecretariaApiClient());$router=new Router();$router->add('GET','/conselho',fn()=>$web->dashboard(),[new AuthMiddleware()]);self::assertStringContainsString('Conselhos em andamento',$router->dispatch(new Request('GET','/conselho',[],[],[]))->body);
    }

    public function testCpfLoginStillRedirectsToPortalRoot(): void
    {
        if(session_status()!==PHP_SESSION_ACTIVE)session_start();$db=$this->mainDatabase();$this->seedMain($db);$view=new View(dirname(__DIR__).'/apps/preconselho-web/resources/views');$_SESSION['_csrf']='token';$request=new Request('POST','/login',[],['_csrf'=>'token','cpf'=>'123.456.789-09'],['REMOTE_ADDR'=>'127.0.0.1','HTTP_USER_AGENT'=>'phpunit']);$response=(new WebController(new AppRepository($db),$view,new SecretariaApiClient()))->login($request);self::assertSame(302,$response->status);self::assertSame('/',$response->headers['Location']);self::assertSame(3,$_SESSION['user']['id']);
    }
}
