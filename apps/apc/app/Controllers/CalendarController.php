<?php declare(strict_types=1);

namespace Apc\Controllers;

use Apc\Repositories\{AccessRepository,EventRepository,SubmissionRepository};
use Apc\Services\{ScheduleService,SubmissionService,SubmissionWindow};
use Shared\Exceptions\HttpException;
use Shared\Http\{Request,Response};
use Shared\Support\View;

final class CalendarController
{
    public function __construct(private readonly EventRepository$events,private readonly SubmissionRepository$submissions,private readonly View$view,private readonly SubmissionWindow$window,private readonly?ScheduleService$schedules=null,private readonly?SubmissionService$submissionService=null,private readonly?AccessRepository$access=null) {}

    public function index(Request$request):Response
    {
        $now=new \DateTimeImmutable('today');$year=$this->integer($request->query['ano']??$now->format('Y'),2000,2100,'ano');$month=$this->integer($request->query['mes']??$now->format('n'),1,12,'mês');$visible=array_values(array_filter($this->events->month($year,$month),fn(array$event):bool=>$this->isVisible($event,$_SESSION['user'])));$personal=$this->personalEvents($_SESSION['user']);$events=array_map(fn(array$event):array=>$event+['window'=>$this->window->describe($event),'personal'=>$personal[(int)$event['id']]??null],$visible);$years=$this->events->years();if(!in_array($year,$years,true))$years[]=$year;rsort($years);return new Response($this->view->render('calendar',compact('year','month','events','years','now')+['title'=>'Calendário de APCs']));
    }

    public function show(Request$request,array$params):Response
    {
        $event=$this->events->find((int)$params['id'])??throw new HttpException(404,'APC_EVENT_NOT_FOUND','Evento APC não encontrado.');$user=$_SESSION['user'];if(!$this->isVisible($event,$user))throw new HttpException(403,'APC_EVENT_FORBIDDEN','Você não possui aula no dia e turno deste evento APC.');$submissions=$this->submissions->forEvent((int)$event['id'],(int)$user['id'],(string)$user['perfil']);$window=$this->window->describe($event);$personal=$this->personalEvents($user)[(int)$event['id']]??null;$canUpload=$personal!==null&&in_array($personal['status'],['PENDENTE','PARCIAL'],true);$late=count(array_filter($submissions,static fn(array$item):bool=>(bool)$item['atrasado']));return new Response($this->view->render('event',compact('event','submissions','window','late','personal','canUpload')+['title'=>'Evento APC']));
    }

    private function personalEvents(array $user):array
    {
        $userId=(int)$user['id'];if($this->submissionService===null||$this->access===null||!$this->access->isActiveTeacher($userId))return[];
        $dashboard=$this->submissionService->teacherDashboard($this->access->seriesFor($userId,'PROFESSOR'),$this->submissions->list($userId,'PROFESSOR'),$userId);
        $indexed=[];foreach($dashboard['available']as$event)$indexed[(int)$event['id']]=$event;return$indexed;
    }

    private function isVisible(array$event,array$user):bool{return$user['perfil']!=='PROFESSOR'||($event['turno']??null)===null||($this->schedules?->isVisibleToTeacher($event,(int)$user['id'])??false);}

    private function integer(mixed$value,int$min,int$max,string$label):int{$valid=filter_var($value,FILTER_VALIDATE_INT,['options'=>['min_range'=>$min,'max_range'=>$max]]);if($valid===false)throw new HttpException(422,'APC_INVALID_CALENDAR',ucfirst($label).' inválido.');return(int)$valid;}
}
