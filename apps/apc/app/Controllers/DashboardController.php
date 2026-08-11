<?php declare(strict_types=1);

namespace Apc\Controllers;

use Apc\Repositories\{AccessRepository,EventRepository,PlanRepository};
use Shared\Http\{Request,Response};
use Shared\Support\View;

final class DashboardController
{
    public function __construct(private readonly PlanRepository $plans,private readonly EventRepository $events,private readonly AccessRepository $access,private readonly View $view) {}

    public function index(Request $request): Response
    {
        $user=$_SESSION['user'];$filters=$this->filters($request);$plans=$this->plans->list((int)$user['id'],(string)$user['perfil'],$filters);$filterOptions=$this->plans->list((int)$user['id'],(string)$user['perfil'],[]);$metrics=$this->plans->dashboardMetrics((int)$user['id'],(string)$user['perfil']);$events=$this->events->active();$upcomingEvents=$this->events->upcoming(5);$classes=$this->access->classesFor((int)$user['id'],(string)$user['perfil']);
        return new Response($this->view->render('dashboard',compact('plans','filterOptions','metrics','events','upcomingEvents','classes','filters')+['title'=>'APCs']));
    }

    private function filters(Request $request): array
    {
        $filters=[];foreach(['ano','evento','data','turma','professor','componente','status']as$key)$filters[$key]=trim((string)($request->query[$key]??''));return$filters;
    }
}
