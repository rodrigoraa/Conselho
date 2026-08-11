<?php declare(strict_types=1);

namespace Apc\Controllers;

use Apc\Repositories\{AuditRepository,EventRepository,SettingsRepository};
use Apc\Services\{CalendarImporter,EventService,SettingsService};
use PreConselho\Support\Csrf;
use Shared\Http\{Request,Response};
use Shared\Support\View;

final class AdminController
{
    public function __construct(private readonly EventRepository $events,private readonly SettingsRepository $settings,private readonly AuditRepository $audit,private readonly EventService $eventService,private readonly SettingsService $settingsService,private readonly CalendarImporter $calendarImporter,private readonly View $view) {}

    public function index(Request $request): Response
    {
        $year=filter_var($request->query['ano']??null,FILTER_VALIDATE_INT,['options'=>['min_range'=>2000,'max_range'=>2100]]);$events=$this->events->all($year===false?null:(int)$year,true);$settings=$this->settings->all();$audit=$this->audit->recent();return new Response($this->view->render('admin',compact('events','settings','audit','year')+['title'=>'Administração APC']));
    }

    public function createEvent(Request $request): Response
    {
        Csrf::verify($request->body['_csrf']??null);$this->eventService->save(null,$request->body,(int)$_SESSION['user']['id'],$request->ip(),$request->header('User-Agent')??'');$_SESSION['flash']='Evento APC criado.';return Response::redirect('/apc/admin#calendario');
    }

    public function updateEvent(Request $request,array $params): Response
    {
        Csrf::verify($request->body['_csrf']??null);$this->eventService->save((int)$params['id'],$request->body,(int)$_SESSION['user']['id'],$request->ip(),$request->header('User-Agent')??'');$_SESSION['flash']='Evento APC atualizado.';return Response::redirect('/apc/admin#calendario');
    }

    public function cancelEvent(Request $request,array $params): Response
    {
        Csrf::verify($request->body['_csrf']??null);$this->eventService->cancel((int)$params['id'],(int)$_SESSION['user']['id'],$request->ip(),$request->header('User-Agent')??'');$_SESSION['flash']='Evento APC cancelado sem excluir o histórico.';return Response::redirect('/apc/admin#calendario');
    }

    public function releaseEvent(Request $request,array $params): Response
    {
        Csrf::verify($request->body['_csrf']??null);$this->eventService->setAvailability((int)$params['id'],true,$_SESSION['user'],$request->ip(),$request->header('User-Agent')??'');$_SESSION['flash']='APC disponibilizada aos professores.';return Response::redirect($this->eventReturn($request,(int)$params['id']));
    }

    public function suspendEvent(Request $request,array $params): Response
    {
        Csrf::verify($request->body['_csrf']??null);$this->eventService->setAvailability((int)$params['id'],false,$_SESSION['user'],$request->ip(),$request->header('User-Agent')??'');$_SESSION['flash']='Disponibilização da APC suspensa.';return Response::redirect($this->eventReturn($request,(int)$params['id']));
    }

    public function importSchoolCalendar(Request $request): Response
    {
        Csrf::verify($request->body['_csrf']??null);$summary=$this->calendarImporter->import((int)$_SESSION['user']['id'],$request->ip(),$request->header('User-Agent')??'');$_SESSION['flash']="Calendário oficial importado: {$summary['total']} eventos; {$summary['criados']} criados, {$summary['atualizados']} atualizados, {$summary['conciliados']} conciliados e {$summary['inalterados']} inalterados.";return Response::redirect('/apc/admin#calendario');
    }

    public function updateSettings(Request $request): Response
    {
        Csrf::verify($request->body['_csrf']??null);$this->settingsService->update($request->body,(int)$_SESSION['user']['id'],$request->ip(),$request->header('User-Agent')??'');$_SESSION['flash']='Parâmetros APC atualizados.';return Response::redirect('/apc/admin#parametros');
    }

    private function eventReturn(Request$request,int$id):string
    {
        return($request->body['retorno']??'')==='evento'?'/apc/eventos/'.$id:'/apc';
    }
}
