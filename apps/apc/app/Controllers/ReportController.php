<?php declare(strict_types=1);

namespace Apc\Controllers;

use Apc\Repositories\{AccessRepository,EventRepository,PlanRepository};
use Shared\Http\{Request,Response};
use Shared\Support\View;

final class ReportController
{
    public function __construct(private readonly PlanRepository $plans,private readonly EventRepository $events,private readonly AccessRepository $access,private readonly View $view) {}

    public function index(Request $request): Response
    {
        $filters=[];foreach(['ano','evento','turma','professor','status']as$key)$filters[$key]=trim((string)($request->query[$key]??''));$rows=$this->plans->list((int)$_SESSION['user']['id'],(string)$_SESSION['user']['perfil'],$filters);
        if(($request->query['formato']??'')==='csv')return$this->csv($rows);
        $events=$this->events->all(null,true);$classes=$this->access->classesFor((int)$_SESSION['user']['id'],(string)$_SESSION['user']['perfil']);$professors=[];foreach($this->plans->list((int)$_SESSION['user']['id'],(string)$_SESSION['user']['perfil'],[])as$plan)$professors[(int)$plan['professor_usuario_id']]=$plan['professor_nome_snapshot'];asort($professors,SORT_NATURAL|SORT_FLAG_CASE);return new Response($this->view->render('report',compact('rows','filters','events','classes','professors')+['title'=>'Relatório consolidado APC']));
    }

    private function csv(array $rows): Response
    {
        $stream=fopen('php://temp','r+');fputcsv($stream,['APC','Data','Professor','Turma','Componente','Alunos da turma','Entregaram','Não entregaram','Percentual','Plano finalizado?']);
        foreach($rows as$row){$total=$row['total_alunos_snapshot']===null?'':(int)$row['total_alunos_snapshot'];$delivered=(int)$row['entregues'];$notDelivered=$total===''?'':max(0,$total-$delivered);$percentage=$total===''||$total===0?'':number_format($delivered/$total*100,1,',','.').'%';fputcsv($stream,[$row['evento_titulo'],$row['evento_data'],$row['professor_nome_snapshot'],$row['turma_nome_snapshot'],$row['componente_curricular'],$total,$delivered,$notDelivered,$percentage,$row['status']==='FINALIZADO'?'Sim':'Não']);}
        rewind($stream);return new Response((string)stream_get_contents($stream),200,['Content-Type'=>'text/csv; charset=utf-8','Content-Disposition'=>'attachment; filename="apc-consolidado.csv"']);
    }
}
