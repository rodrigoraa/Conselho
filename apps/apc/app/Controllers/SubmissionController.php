<?php declare(strict_types=1);

namespace Apc\Controllers;

use Apc\Repositories\{AccessRepository,SubmissionRepository};
use Apc\Services\SubmissionService;
use Apc\Support\NavigationContext;
use PreConselho\Support\Csrf;
use Shared\Exceptions\HttpException;
use Shared\Http\{Request,Response};
use Shared\Support\View;

final class SubmissionController
{
    private const PREVIEWABLE_MIMES=['application/pdf','image/jpeg','image/png','image/webp','application/vnd.openxmlformats-officedocument.wordprocessingml.document'];

    public function __construct(private readonly SubmissionService$service,private readonly SubmissionRepository$submissions,private readonly AccessRepository$access,private readonly View$view) {}

    public function index(Request$request):Response
    {
        $user=$_SESSION['user'];$userId=(int)$user['id'];$role=(string)$user['perfil'];$isTeacher=$role==='PROFESSOR';$isManagement=in_array($role,['COORDENADOR','ADMIN'],true);$canSubmit=$this->access->isActiveTeacher($userId);
        // Always calculate obligations from the complete own history, independently of display filters/pages.
        $teacherSubmissions=$canSubmit?$this->submissions->list($userId,'PROFESSOR'):[];$series=[];$events=[];$submittedClasses=[];$eligibleEvents=[];$teacherDashboard=['available'=>[],'future'=>[]];$tracking=['events'=>[],'without_series'=>[]];
        $context=NavigationContext::from($request->query);$filters=$context->filters;$activeView=$isManagement?($filters['aba']??(isset($filters['evento'])?'files':($canSubmit?'mine':'tracking'))):'mine';
        if($activeView==='mine'&&!$canSubmit)$activeView='tracking';$context=NavigationContext::from(array_replace($filters,['aba'=>$activeView]));$filters=$context->filters;
        $libraryPage=$this->submissions->page($userId,$role,$filters,(int)($filters['pagina']??1));$submissions=$libraryPage['rows'];$libraryOptions=$this->submissions->filterOptions($userId,$role);
        if($canSubmit){
            $series=$this->access->seriesFor((int)$user['id'],'PROFESSOR');
            $teacherDashboard=$this->service->teacherDashboard($series,$teacherSubmissions,$userId);$submittedClasses=$teacherDashboard['submitted_classes'];$eligibleEvents=$teacherDashboard['eligible_events']??[];
            $events=array_values(array_filter($teacherDashboard['available'],static fn(array$event):bool=>in_array($event['status'],['PENDENTE','PARCIAL'],true)));
        }
        $mineFilters=NavigationContext::from(array_replace($filters,['aba'=>'mine']))->filters;
        $minePage=$this->submissions->page($userId,'PROFESSOR',$mineFilters,(int)($filters['pagina']??1));$mineOptions=$this->submissions->filterOptions($userId,'PROFESSOR');
        $selectedEventId=(int)($filters['evento']??0);$selectionUnavailable=$canSubmit&&$selectedEventId>0&&!in_array($selectedEventId,array_map('intval',array_column($events,'id')),true);if($selectionUnavailable)$selectedEventId=0;
        $trackingOptions=['events'=>[],'professors'=>[],'years'=>[]];$trackingPage=['page'=>1,'pages'=>1,'total'=>0,'per_page'=>10];
        if($isManagement){
            $tracking=$this->service->tracking();$trackingOptions=$this->trackingOptions($tracking['events']);$tracking=$this->filterTracking($tracking,$filters);
            $total=count($tracking['events']);$pages=max(1,(int)ceil($total/10));$page=max(1,min($pages,(int)($filters['pagina']??1)));$trackingPage=['page'=>$page,'pages'=>$pages,'total'=>$total,'per_page'=>10];$tracking['events']=array_slice($tracking['events'],($page-1)*10,10);
        }
        $context=NavigationContext::from(array_replace($filters,['pagina'=>(string)($activeView==='tracking'?$trackingPage['page']:($activeView==='mine'?$minePage['page']:$libraryPage['page']))]));
        $term=$this->service->currentTerm();return new Response($this->view->render('submissions',compact('series','events','submittedClasses','eligibleEvents','teacherDashboard','tracking','term','submissions','teacherSubmissions','isTeacher','isManagement','canSubmit','context','filters','activeView','libraryPage','libraryOptions','trackingPage','trackingOptions','selectedEventId','selectionUnavailable','minePage','mineOptions')+['title'=>'Envio de APCs']));
    }

    public function upload(Request$request):Response
    {
        Csrf::verify($request->body['_csrf']??null);$file=$_FILES['arquivo']??[];if(!is_array($file))throw new HttpException(422,'APC_UPLOAD_INVALID','Selecione um arquivo válido.');$this->service->submit($request->body,$file,$_SESSION['user'],$request->ip(),$request->header('User-Agent')??'');$_SESSION['flash']='Arquivo da APC anexado com sucesso.';return Response::redirect(NavigationContext::from($request->body)->url(['aba'=>'mine','evento'=>'','pagina'=>'']));
    }

    public function delete(Request$request,array$params):Response
    {
        Csrf::verify($request->body['_csrf']??null);$this->service->delete((int)$params['id'],$_SESSION['user'],$request->ip(),$request->header('User-Agent')??'');$_SESSION['flash']='Envio da APC excluído. A turma voltou a ficar pendente para o professor.';return Response::redirect(NavigationContext::from($request->body)->url());
    }

    public function download(Request$request,array$params):Response
    {
        $stored=$this->service->contents((int)$params['id'],$_SESSION['user']);$file=$stored['file'];$contents=$stored['contents'];$fallback='apc.'.pathinfo((string)$file['nome_armazenado'],PATHINFO_EXTENSION);$disposition='attachment; filename="'.$fallback.'"; filename*=UTF-8\'\''.rawurlencode((string)$file['nome_original']);return new Response($contents,200,['Content-Type'=>(string)$file['mime_type'],'Content-Length'=>(string)strlen($contents),'Content-Disposition'=>$disposition,'Cache-Control'=>'private, no-store','X-Content-Type-Options'=>'nosniff']);
    }

    public function preview(Request$request,array$params):Response
    {
        $submission=$this->service->file((int)$params['id'],$_SESSION['user']);$previewable=in_array((string)$submission['mime_type'],self::PREVIEWABLE_MIMES,true);$isImage=str_starts_with((string)$submission['mime_type'],'image/');$isDocx=(string)$submission['mime_type']==='application/vnd.openxmlformats-officedocument.wordprocessingml.document';$context=NavigationContext::from($request->query);if(!isset($context->filters['aba']))$context=NavigationContext::from($context->filters+['aba'=>$_SESSION['user']['perfil']==='PROFESSOR'?'mine':'files']);return new Response($this->view->render('submission_preview',compact('submission','previewable','isImage','isDocx','context')+['title'=>'Visualizar APC']));
    }

    private function trackingOptions(array $events):array
    {
        $professors=[];$years=[];foreach($events as$event){$years[]=(int)$event['ano_letivo'];foreach($event['professors']as$professor)$professors[(int)$professor['professor_usuario_id']]=['id'=>(int)$professor['professor_usuario_id'],'nome'=>$professor['professor_nome']];}
        $years=array_values(array_unique($years));rsort($years);uasort($professors,static fn(array$a,array$b):int=>strnatcasecmp($a['nome'],$b['nome']));return['events'=>$events,'professors'=>array_values($professors),'years'=>$years];
    }

    private function filterTracking(array $tracking,array $filters):array
    {
        $events=[];$term=mb_strtolower((string)($filters['q']??''));
        foreach($tracking['events']as$event){
            if(($filters['ano']??'')!==''&&(int)$filters['ano']!==(int)$event['ano_letivo'])continue;
            if(($filters['evento']??'')!==''&&(int)$filters['evento']!==(int)$event['id'])continue;
            $event['overall_professor_count']=count($event['professors']);$event['overall_complete_count']=$event['complete_count'];$event['overall_incomplete_count']=$event['incomplete_count'];
            $originalCount=count($event['professors']);$eventMatch=$term===''||str_contains(mb_strtolower($event['titulo'].' '.$event['data']),$term);
            $event['professors']=array_values(array_filter($event['professors'],static function(array$professor)use($filters,$term,$eventMatch):bool{
                if(($filters['professor']??'')!==''&&(int)$filters['professor']!==(int)$professor['professor_usuario_id'])return false;
                return$eventMatch||str_contains(mb_strtolower($professor['professor_nome'].' '.implode(' ',array_column($professor['requirements'],'turma_nome'))),$term);
            }));
            if(!$event['professors']&&(($filters['professor']??'')!==''||!$eventMatch))continue;
            $event['professor_count']=count($event['professors']);$event['complete_count']=count(array_filter($event['professors'],static fn(array$p):bool=>$p['status']==='COMPLETO'));$event['incomplete_count']=$event['professor_count']-$event['complete_count'];$event['filtered']=$originalCount!==$event['professor_count'];$events[]=$event;
        }
        $tracking['events']=$events;$tracking['without_series']=array_values(array_filter($tracking['without_series'],static fn(array$p):bool=>(($filters['professor']??'')===''||(int)$filters['professor']===(int)$p['professor_usuario_id'])&&($term===''||str_contains(mb_strtolower($p['professor_nome']),$term))));return$tracking;
    }

    public function content(Request$request,array$params):Response
    {
        $file=$this->service->file((int)$params['id'],$_SESSION['user']);if(!in_array((string)$file['mime_type'],self::PREVIEWABLE_MIMES,true))throw new HttpException(415,'APC_PREVIEW_UNSUPPORTED','Este formato não possui visualização direta no navegador.');$stored=$this->service->contents((int)$params['id'],$_SESSION['user']);$contents=$stored['contents'];$fallback='apc.'.pathinfo((string)$file['nome_armazenado'],PATHINFO_EXTENSION);$disposition='inline; filename="'.$fallback.'"; filename*=UTF-8\'\''.rawurlencode((string)$file['nome_original']);return new Response($contents,200,['Content-Type'=>(string)$file['mime_type'],'Content-Length'=>(string)strlen($contents),'Content-Disposition'=>$disposition,'Cache-Control'=>'private, no-store','X-Content-Type-Options'=>'nosniff','X-Frame-Options'=>'SAMEORIGIN','Content-Security-Policy'=>"default-src 'none'; img-src 'self' data:; frame-ancestors 'self'"]);
    }
}
