<?php declare(strict_types=1);

namespace Apc\Controllers;

use Apc\Repositories\{AccessRepository,CurriculumRepository,EventRepository,PlanRepository};
use Apc\Services\{AuthorizationService,EventWindow,PlanService,ScheduleService};
use PreConselho\Support\Csrf;
use Shared\Exceptions\HttpException;
use Shared\Http\{Request,Response};
use Shared\Support\View;

final class PlanController
{
    public function __construct(private readonly PlanService $service,private readonly AuthorizationService $authorization,private readonly EventRepository $events,private readonly AccessRepository $access,private readonly View $view,private readonly ?CurriculumRepository $curriculum=null,private readonly ?EventWindow $eventWindow=null,private readonly ?ScheduleService $schedules=null,private readonly ?PlanRepository $plans=null) {}

    public function index(Request $request): Response
    {
        $user=$_SESSION['user'];$filters=[];foreach(['ano','evento','turma','status']as$key)$filters[$key]=mb_substr(is_scalar($request->query[$key]??'')?trim((string)($request->query[$key]??'')):'',0,160);
        $total=$this->plans?->count((int)$user['id'],(string)$user['perfil'],$filters)??0;$pages=max(1,(int)ceil($total/25));$page=min($pages,max(1,(int)($request->query['pagina']??1)));$rows=$this->plans?->list((int)$user['id'],(string)$user['perfil'],$filters,25,($page-1)*25)??[];
        $classes=$this->access->classesFor((int)$user['id'],(string)$user['perfil']);$linkedClasses=$classes;$events=$this->events->all();
        if($user['perfil']==='PROFESSOR'){
            $historicalOptions=$this->plans?->filterOptionsForTeacher((int)$user['id'])??[];$historicalEventIds=array_map('intval',array_column($historicalOptions,'evento_id'));
            $events=array_values(array_filter($events,fn(array $event):bool=>in_array((int)$event['id'],$historicalEventIds,true)||$this->visibleEvent($event,$user)));
            $knownClassIds=array_fill_keys(array_map('intval',array_column($classes,'id')),true);
            foreach($historicalOptions as$option){$classId=(int)$option['turma_id_externo'];if(isset($knownClassIds[$classId]))continue;$classes[]=['id'=>$classId,'nome'=>$option['turma_nome_snapshot']];$knownClassIds[$classId]=true;}
        }
        $canCreate=$user['perfil']==='PROFESSOR'&&$linkedClasses&&array_filter($this->events->active(),fn(array $event):bool=>$this->window()->describe($event)['is_open']&&$this->visibleEvent($event,$user)&&$this->eligibleClasses($event,$linkedClasses,$user)!==[]);
        // Match AuthorizationService::plan: historical rows do not grant a missing class binding.
        $linkedClassIds=array_fill_keys(array_map('intval',array_column($linkedClasses,'id')),true);
        $rows=array_map(static fn(array $row):array=>$row+['can_access'=>$user['perfil']!=='PROFESSOR'||isset($linkedClassIds[(int)$row['turma_id_externo']])],$rows);
        return new Response($this->view->render('plans',compact('filters','total','pages','page','rows','classes','events','canCreate')+['title'=>'Planos de ação APC']));
    }

    public function createForm(Request $request): Response
    {
        $user=$_SESSION['user'];$eventId=(int)($request->query['evento']??0);$selectedEvent=$eventId?$this->events->find($eventId):null;
        if($eventId&&!$selectedEvent)throw new HttpException(404,'APC_EVENT_NOT_FOUND','Evento APC não encontrado.');if($selectedEvent&&!$this->visibleEvent($selectedEvent,$user))throw new HttpException(403,'APC_SCHEDULE_FORBIDDEN','Você não possui aula prevista para a data e o turno deste evento.');if($selectedEvent)$this->window()->assertOpen($selectedEvent);
        $events=array_values(array_filter($this->events->active(),fn(array$event):bool=>$this->visibleEvent($event,$user)&&$this->window()->describe($event)['is_open']));$classes=$this->access->classesFor((int)$user['id'],(string)$user['perfil']);
        if($selectedEvent)$classes=$this->eligibleClasses($selectedEvent,$classes,$user);
        $plan=null;$components=$this->curriculum?->components()??[];$planCurriculum=['componentes'=>[],'habilidades'=>[]];$window=null;
        return new Response($this->view->render('plan_form',compact('events','classes','selectedEvent','plan','components','planCurriculum','window')+['title'=>'Novo Plano de Ação']));
    }

    public function create(Request $request): Response
    {
        Csrf::verify($request->body['_csrf']??null);$id=$this->service->create($request->body,$_SESSION['user'],$request->ip(),$request->header('User-Agent')??'');$_SESSION['flash']='Plano APC criado como rascunho.';return Response::redirect('/apc/planos/'.$id);
    }

    public function show(Request $request,array $params): Response
    {
        $user=$_SESSION['user'];$plan=$this->authorization->plan((int)$params['id'],(int)$user['id'],(string)$user['perfil']);$components=$this->curriculum?->components(null,true)??[];$planCurriculum=$this->curriculum?->planCurriculum((int)$plan['id'])??['componentes'=>[],'habilidades'=>[]];$window=$this->window()->describe($plan);return new Response($this->view->render('plan_form',compact('plan','components','planCurriculum','window')+['events'=>[],'classes'=>[],'selectedEvent'=>null,'title'=>'Plano de Ação APC']));
    }

    public function update(Request $request,array $params): Response
    {
        Csrf::verify($request->body['_csrf']??null);$id=(int)$params['id'];$this->service->update($id,$request->body,$_SESSION['user'],$request->ip(),$request->header('User-Agent')??'');$_SESSION['flash']='Rascunho do plano atualizado.';return Response::redirect('/apc/planos/'.$id);
    }

    public function finalize(Request $request,array $params): Response
    {
        Csrf::verify($request->body['_csrf']??null);$id=(int)$params['id'];$input=isset($request->body['_save_before_finalize'])||array_key_exists('conteudos',$request->body)?$request->body:null;$this->service->finalize($id,$_SESSION['user'],$request->ip(),$request->header('User-Agent')??'',$input);$_SESSION['flash']='Plano APC finalizado. Alterações agora dependem de reabertura.';return Response::redirect('/apc/planos/'.$id);
    }

    public function reopen(Request $request,array $params): Response
    {
        Csrf::verify($request->body['_csrf']??null);$id=(int)$params['id'];$this->service->reopen($id,(string)($request->body['motivo']??''),$_SESSION['user'],$request->ip(),$request->header('User-Agent')??'');$_SESSION['flash']='Plano APC reaberto com auditoria.';return Response::redirect('/apc/planos/'.$id);
    }

    private function eligibleClasses(array $event,array $classes,array $user): array
    {
        if(($event['turno']??null)===null||$user['perfil']!=='PROFESSOR')return$classes;
        $requirements=$this->schedules?->ensureEvent($event)['requirements']??[];
        $allowed=array_map('intval',array_column(array_filter($requirements,static fn(array $row):bool=>(int)$row['professor_usuario_id']===(int)$user['id']),'turma_id_externo'));
        return array_values(array_filter($classes,static fn(array $class):bool=>in_array((int)$class['id'],$allowed,true)));
    }

    private function visibleEvent(array$event,array$user):bool{return$user['perfil']!=='PROFESSOR'||($event['turno']??null)===null||($this->schedules?->isVisibleToTeacher($event,(int)$user['id'])??false);}
    private function window(): EventWindow{return$this->eventWindow??new EventWindow();}
}
