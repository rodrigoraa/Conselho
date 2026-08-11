<?php declare(strict_types=1);

namespace Apc\Controllers;

use Apc\Repositories\{AccessRepository,CurriculumRepository,EventRepository};
use Apc\Services\{AuthorizationService,PlanService};
use PreConselho\Support\Csrf;
use Shared\Exceptions\HttpException;
use Shared\Http\{Request,Response};
use Shared\Support\View;

final class PlanController
{
    public function __construct(private readonly PlanService $service,private readonly AuthorizationService $authorization,private readonly EventRepository $events,private readonly AccessRepository $access,private readonly View $view,private readonly ?CurriculumRepository $curriculum=null) {}

    public function createForm(Request $request): Response
    {
        $user=$_SESSION['user'];$eventId=(int)($request->query['evento']??0);$selectedEvent=$eventId?$this->events->find($eventId):null;
        if($eventId&&!$selectedEvent)throw new HttpException(404,'APC_EVENT_NOT_FOUND','Evento APC não encontrado.');
        $events=$this->events->active();$classes=$this->access->classesFor((int)$user['id'],(string)$user['perfil']);$plan=null;$components=$this->curriculum?->components()??[];$planCurriculum=['componentes'=>[],'habilidades'=>[]];
        return new Response($this->view->render('plan_form',compact('events','classes','selectedEvent','plan','components','planCurriculum')+['title'=>'Novo Plano de Ação']));
    }

    public function create(Request $request): Response
    {
        Csrf::verify($request->body['_csrf']??null);$id=$this->service->create($request->body,$_SESSION['user'],$request->ip(),$request->header('User-Agent')??'');$_SESSION['flash']='Plano APC criado como rascunho.';return Response::redirect('/apc/planos/'.$id);
    }

    public function show(Request $request,array $params): Response
    {
        $user=$_SESSION['user'];$plan=$this->authorization->plan((int)$params['id'],(int)$user['id'],(string)$user['perfil']);$components=$this->curriculum?->components(null,true)??[];$planCurriculum=$this->curriculum?->planCurriculum((int)$plan['id'])??['componentes'=>[],'habilidades'=>[]];return new Response($this->view->render('plan_form',compact('plan','components','planCurriculum')+['events'=>[],'classes'=>[],'selectedEvent'=>null,'title'=>'Plano de Ação APC']));
    }

    public function update(Request $request,array $params): Response
    {
        Csrf::verify($request->body['_csrf']??null);$id=(int)$params['id'];$this->service->update($id,$request->body,$_SESSION['user'],$request->ip(),$request->header('User-Agent')??'');$_SESSION['flash']='Rascunho do plano atualizado.';return Response::redirect('/apc/planos/'.$id);
    }

    public function finalize(Request $request,array $params): Response
    {
        Csrf::verify($request->body['_csrf']??null);$id=(int)$params['id'];$this->service->finalize($id,$_SESSION['user'],$request->ip(),$request->header('User-Agent')??'');$_SESSION['flash']='Plano APC finalizado. Alterações agora dependem de reabertura.';return Response::redirect('/apc/planos/'.$id);
    }

    public function reopen(Request $request,array $params): Response
    {
        Csrf::verify($request->body['_csrf']??null);$id=(int)$params['id'];$this->service->reopen($id,(string)($request->body['motivo']??''),$_SESSION['user'],$request->ip(),$request->header('User-Agent')??'');$_SESSION['flash']='Plano APC reaberto com auditoria.';return Response::redirect('/apc/planos/'.$id);
    }
}
