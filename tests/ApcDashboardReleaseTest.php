<?php declare(strict_types=1);

namespace Tests;

use Apc\Controllers\DashboardController;
use Apc\Repositories\{AccessRepository,EventRepository,PlanRepository};
use Apc\Services\EventWindow;
use Shared\Http\Request;
use Shared\Support\View;

final class ApcDashboardReleaseTest extends ApcTestCase
{
    protected function tearDown():void{unset($_SESSION['user'],$_SESSION['_csrf']);$_SERVER['REQUEST_URI']='/';}

    public function testCoordinationDashboardProvidesReleaseActionAndTeacherRemainsBlocked():void
    {
        $main=$this->mainDatabase();$this->seedMain($main);$apc=$this->apcDatabase();$this->seedEvent($apc);$apc->exec('UPDATE apc_eventos SET disponibilizado_em=NULL,disponibilizado_por=NULL');$controller=new DashboardController(new PlanRepository($apc),new EventRepository($apc),new AccessRepository($main),new View(dirname(__DIR__).'/apps/apc/resources/views'),new EventWindow('2026-08-11'));$_SERVER['REQUEST_URI']='/apc';$_SESSION['user']=['id'=>2,'nome'=>'Coordenação','perfil'=>'COORDENADOR'];$coordination=$controller->index(new Request('GET','/apc',[],[],[]));self::assertStringContainsString('Liberação para os professores',$coordination->body);self::assertStringContainsString('Disponibilizar aos professores',$coordination->body);self::assertStringContainsString('/apc/eventos/1/disponibilizar',$coordination->body);$_SESSION['user']=['id'=>3,'nome'=>'Professor Um','perfil'=>'PROFESSOR'];$teacher=$controller->index(new Request('GET','/apc',[],[],[]));self::assertStringContainsString('Aguardando liberação da coordenação',$teacher->body);self::assertStringNotContainsString('Criar Plano de Ação',$teacher->body);self::assertStringNotContainsString('/apc/eventos/1/disponibilizar',$teacher->body);
    }
}
