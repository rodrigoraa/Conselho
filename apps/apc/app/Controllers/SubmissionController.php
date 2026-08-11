<?php declare(strict_types=1);

namespace Apc\Controllers;

use Apc\Repositories\{AccessRepository,SubmissionRepository};
use Apc\Services\SubmissionService;
use PreConselho\Support\Csrf;
use Shared\Exceptions\HttpException;
use Shared\Http\{Request,Response};
use Shared\Support\View;

final class SubmissionController
{
    private const PREVIEWABLE_MIMES=['application/pdf','image/jpeg','image/png','image/webp'];

    public function __construct(private readonly SubmissionService$service,private readonly SubmissionRepository$submissions,private readonly AccessRepository$access,private readonly View$view) {}

    public function index(Request$request):Response
    {
        $user=$_SESSION['user'];$isTeacher=$user['perfil']==='PROFESSOR';$submissions=$this->submissions->list((int)$user['id'],(string)$user['perfil']);$series=[];$events=[];$submittedSeries=[];$hadAvailableEvents=false;$teacherDashboard=['available'=>[],'future'=>[]];$tracking=['events'=>[],'without_series'=>[]];
        if($isTeacher){
            $series=$this->access->seriesFor((int)$user['id'],'PROFESSOR');
            $teacherDashboard=$this->service->teacherDashboard($series,$submissions);$submittedSeries=$teacherDashboard['submitted_series'];$hadAvailableEvents=(bool)$teacherDashboard['available'];
            $events=array_values(array_filter($teacherDashboard['available'],static fn(array$event):bool=>$event['status']!=='COMPLETO'));
        }else{$tracking=$this->service->tracking();}
        $term=$this->service->currentTerm();return new Response($this->view->render('submissions',compact('series','events','submittedSeries','hadAvailableEvents','teacherDashboard','tracking','term','submissions','isTeacher')+['title'=>'Envio de APCs']));
    }

    public function upload(Request$request):Response
    {
        Csrf::verify($request->body['_csrf']??null);$file=$_FILES['arquivo']??[];if(!is_array($file))throw new HttpException(422,'APC_UPLOAD_INVALID','Selecione um arquivo válido.');$this->service->submit($request->body,$file,$_SESSION['user'],$request->ip(),$request->header('User-Agent')??'');$_SESSION['flash']='Arquivo da APC anexado com sucesso.';return Response::redirect('/apc');
    }

    public function download(Request$request,array$params):Response
    {
        $file=$this->service->file((int)$params['id'],$_SESSION['user']);$contents=file_get_contents($file['caminho_absoluto']);if($contents===false)throw new HttpException(404,'APC_SUBMISSION_FILE_MISSING','O arquivo da APC não está disponível.');$fallback='apc.'.pathinfo((string)$file['nome_armazenado'],PATHINFO_EXTENSION);$disposition='attachment; filename="'.$fallback.'"; filename*=UTF-8\'\''.rawurlencode((string)$file['nome_original']);return new Response($contents,200,['Content-Type'=>(string)$file['mime_type'],'Content-Length'=>(string)strlen($contents),'Content-Disposition'=>$disposition,'Cache-Control'=>'private, no-store','X-Content-Type-Options'=>'nosniff']);
    }

    public function preview(Request$request,array$params):Response
    {
        $submission=$this->service->file((int)$params['id'],$_SESSION['user']);$previewable=in_array((string)$submission['mime_type'],self::PREVIEWABLE_MIMES,true);$isImage=str_starts_with((string)$submission['mime_type'],'image/');return new Response($this->view->render('submission_preview',compact('submission','previewable','isImage')+['title'=>'Visualizar APC']));
    }

    public function content(Request$request,array$params):Response
    {
        $file=$this->service->file((int)$params['id'],$_SESSION['user']);if(!in_array((string)$file['mime_type'],self::PREVIEWABLE_MIMES,true))throw new HttpException(415,'APC_PREVIEW_UNSUPPORTED','Este formato não possui visualização direta no navegador.');$contents=file_get_contents($file['caminho_absoluto']);if($contents===false)throw new HttpException(404,'APC_SUBMISSION_FILE_MISSING','O arquivo da APC não está disponível.');$fallback='apc.'.pathinfo((string)$file['nome_armazenado'],PATHINFO_EXTENSION);$disposition='inline; filename="'.$fallback.'"; filename*=UTF-8\'\''.rawurlencode((string)$file['nome_original']);return new Response($contents,200,['Content-Type'=>(string)$file['mime_type'],'Content-Length'=>(string)strlen($contents),'Content-Disposition'=>$disposition,'Cache-Control'=>'private, no-store','X-Content-Type-Options'=>'nosniff','X-Frame-Options'=>'SAMEORIGIN','Content-Security-Policy'=>"default-src 'none'; img-src 'self' data:; frame-ancestors 'self'"]);
    }
}
