<?php declare(strict_types=1);

namespace Tests;

use Apc\Controllers\CalendarController;
use Apc\Repositories\EventRepository;
use Apc\Repositories\PlanRepository;
use Apc\Services\EventWindow;
use Shared\Exceptions\HttpException;
use Shared\Http\Request;
use Shared\Support\View;

final class ApcCalendarTest extends ApcTestCase
{
    protected function tearDown():void{unset($_SESSION['user']);$_SERVER['REQUEST_URI']='/';}
    public function testUpcomingExcludesPastOrdersFutureAndLimitsResults():void
    {
        $db=$this->apcDatabase();for($day=1;$day<=8;$day++)$db->exec("INSERT INTO apc_eventos(ano_letivo,data,titulo,tipo,origem,descricao,status,criado_por)VALUES(2026,'2026-09-".sprintf('%02d',$day)."','Evento $day','OUTRO','ESCOLA','','ATIVO',1)");$events=(new EventRepository($db))->upcoming(5,'2026-09-03');self::assertCount(5,$events);self::assertSame('2026-09-03',$events[0]['data']);self::assertSame('2026-09-07',$events[4]['data']);
    }
    public function testMonthlyCalendarUsesActiveEventsAndListsExistingYears():void
    {
        $db=$this->apcDatabase();$this->seedEvent($db);$db->exec("INSERT INTO apc_eventos(ano_letivo,data,titulo,tipo,origem,descricao,status,criado_por)VALUES(2027,'2027-01-10','Cancelado','OUTRO','ESCOLA','','CANCELADO',1)");$repository=new EventRepository($db);self::assertCount(1,$repository->month(2026,8));self::assertCount(0,$repository->month(2026,7));self::assertSame([2027,2026],$repository->years());
    }
    public function testCalendarControllerRendersMonthlyNavigationAndRejectsInvalidMonth():void
    {
        $db=$this->apcDatabase();$this->seedEvent($db);$_SESSION['user']=['id'=>3,'nome'=>'Professor','perfil'=>'PROFESSOR'];$_SERVER['REQUEST_URI']='/apc/calendario';$controller=new CalendarController(new EventRepository($db),new PlanRepository($db),new View(dirname(__DIR__).'/apps/apc/resources/views'),new EventWindow('2026-08-11'));$response=$controller->index(new Request('GET','/apc/calendario',['ano'=>'2026','mes'=>'8'],[],[]));self::assertStringContainsString('Agosto de 2026',$response->body);self::assertStringContainsString('APC de agosto',$response->body);self::assertStringContainsString('Aberta',$response->body);self::assertStringContainsString('Mês anterior',$response->body);$this->expectException(HttpException::class);$controller->index(new Request('GET','/apc/calendario',['ano'=>'2026','mes'=>'13'],[],[]));
    }
}
