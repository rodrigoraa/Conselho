<?php declare(strict_types=1);

namespace Tests;

use Apc\Support\Module as ApcModule;
use PreConselho\Controllers\{PortalController,WebController};
use PreConselho\Integration\SecretariaApiClient;
use PreConselho\Middlewares\AuthMiddleware;
use PreConselho\Repositories\AppRepository;
use PreConselho\Services\PersistentLoginService;
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
        $router=new Router();$auth=[new AuthMiddleware()];$router->add('GET','/',fn()=>new Response('portal'),$auth);$router->add('GET','/apc',fn()=>new Response('apc'),$auth);$router->add('GET','/apc/calendario',fn()=>new Response('calendario'),$auth);$router->add('GET','/apc/habilidades',fn()=>new Response('habilidades'),$auth);$router->add('GET','/apc/admin/curriculo',fn()=>new Response('curriculo'),$auth);$router->add('GET','/apc/anexos/{id}',fn()=>new Response('arquivo'),$auth);$router->add('GET','/apc/envios/{id}/arquivo',fn()=>new Response('envio'),$auth);
        foreach(['/','/apc','/apc/calendario','/apc/habilidades','/apc/admin/curriculo','/apc/anexos/1','/apc/envios/1/arquivo']as$path){$response=$router->dispatch(new Request('GET',$path,[],[],[]));self::assertSame(302,$response->status);self::assertSame('/login',$response->headers['Location']);}
    }

    public function testPortalContainsBothSystemCardsAndCouncilAliasOpensOldHandler(): void
    {
        $_SESSION['user']=['id'=>1,'nome'=>'Rodrigo Silva','perfil'=>'ADMIN'];$_SERVER['REQUEST_URI']='/';$view=new View(dirname(__DIR__).'/apps/preconselho-web/resources/views');$portal=(new PortalController($view))->dashboard();self::assertStringContainsString('Conselho de Classe',$portal->body);self::assertStringContainsString('Acessar Conselho',$portal->body);self::assertStringContainsString('APCs',$portal->body);self::assertStringContainsString('Acessar APCs',$portal->body);self::assertStringContainsString('/assets/logo_escola.png',$portal->body);self::assertStringContainsString('EE São José',$portal->body);self::assertStringContainsString('Sistemas',$portal->body);
        $db=$this->mainDatabase();$this->seedMain($db);$_SERVER['REQUEST_URI']='/conselho';$web=new WebController(new AppRepository($db),$view,new SecretariaApiClient());$router=new Router();$router->add('GET','/conselho',fn()=>$web->dashboard(),[new AuthMiddleware()]);$council=$router->dispatch(new Request('GET','/conselho',[],[],[]));self::assertStringContainsString('Conselhos em andamento',$council->body);self::assertStringContainsString('Nesta área',$council->body);self::assertStringContainsString('Painel do Conselho',$council->body);self::assertStringContainsString('Administração',$council->body);
        $_SERVER['REQUEST_URI']='/apc';$apc=(new ApcModule($this->apcDatabase(),$db,new SecretariaApiClient(),dirname(__DIR__)))->submissions->index(new Request('GET','/apc',[],[],[]));self::assertStringContainsString('Envios',$apc->body);self::assertStringContainsString('Calendário',$apc->body);self::assertStringContainsString('Eventos',$apc->body);self::assertStringNotContainsString('Currículo',$apc->body);self::assertStringNotContainsString('Relatórios',$apc->body);
    }

    public function testCpfLoginStillRedirectsToPortalRoot(): void
    {
        if(session_status()!==PHP_SESSION_ACTIVE)session_start();$db=$this->mainDatabase();$this->seedMain($db);$view=new View(dirname(__DIR__).'/apps/preconselho-web/resources/views');$cookie=null;$persistent=new PersistentLoginService($db,15,false,'Lax',static function(string$name,string$value,array$options)use(&$cookie):bool{$cookie=['name'=>$name,'value'=>$value,'options'=>$options];return true;});$_SESSION['_csrf']='token';$request=new Request('POST','/login',[],['_csrf'=>'token','cpf'=>'123.456.789-09'],['REMOTE_ADDR'=>'127.0.0.1','HTTP_USER_AGENT'=>'phpunit']);$response=(new WebController(new AppRepository($db),$view,new SecretariaApiClient(),$persistent))->login($request);self::assertSame(302,$response->status);self::assertSame('/',$response->headers['Location']);self::assertSame(3,$_SESSION['user']['id']);self::assertGreaterThan(time()+14*86400,$_SESSION['user']['persistent_expires_at']);self::assertSame(PersistentLoginService::COOKIE_NAME,$cookie['name']);
    }
}
