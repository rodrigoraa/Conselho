<?php declare(strict_types=1);

namespace Apc\Controllers;

use Apc\Repositories\{EventRepository,PlanRepository};
use Apc\Services\EventWindow;
use Shared\Exceptions\HttpException;
use Shared\Http\{Request,Response};
use Shared\Support\View;

final class CalendarController
{
    public function __construct(private readonly EventRepository $events,private readonly PlanRepository $plans,private readonly View $view,private readonly ?EventWindow $eventWindow=null) {}
    public function index(Request$request):Response
    {
        $now=new \DateTimeImmutable('today');$year=$this->integer($request->query['ano']??$now->format('Y'),2000,2100,'ano');$month=$this->integer($request->query['mes']??$now->format('n'),1,12,'mês');$events=array_map(fn(array$event):array=>$event+['window'=>$this->window()->describe($event)],$this->events->month($year,$month));$years=$this->events->years();if(!in_array($year,$years,true))$years[]=$year;rsort($years);return new Response($this->view->render('calendar',compact('year','month','events','years','now')+['title'=>'Calendário de APCs']));
    }
    public function show(Request$request,array$params):Response
    {
        $event=$this->events->find((int)$params['id'])??throw new HttpException(404,'APC_EVENT_NOT_FOUND','Evento APC não encontrado.');$user=$_SESSION['user'];$summary=$this->plans->eventSummary((int)$event['id'],(int)$user['id'],(string)$user['perfil']);$teacherPlans=$user['perfil']==='PROFESSOR'?$this->plans->eventPlansForTeacher((int)$event['id'],(int)$user['id']):[];$window=$this->window()->describe($event);return new Response($this->view->render('event',compact('event','summary','teacherPlans','window')+['title'=>'Evento APC']));
    }
    private function integer(mixed$value,int$min,int$max,string$label):int{$valid=filter_var($value,FILTER_VALIDATE_INT,['options'=>['min_range'=>$min,'max_range'=>$max]]);if($valid===false)throw new HttpException(422,'APC_INVALID_CALENDAR',ucfirst($label).' inválido.');return(int)$valid;}
    private function window():EventWindow{return$this->eventWindow??new EventWindow();}
}
