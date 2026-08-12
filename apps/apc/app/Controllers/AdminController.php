<?php declare(strict_types=1);

namespace Apc\Controllers;

use Apc\Repositories\{AuditRepository,EventRepository,SettingsRepository};
use Apc\Services\{CalendarImporter,CalendarPdfExtractor,EventService,SettingsService};
use PreConselho\Support\Csrf;
use Shared\Exceptions\HttpException;
use Shared\Http\{Request,Response};
use Shared\Support\View;

final class AdminController
{
    private const CALENDAR_ANALYSIS_SESSION='apc_calendar_analysis';

    public function __construct(private readonly EventRepository $events,private readonly SettingsRepository $settings,private readonly AuditRepository $audit,private readonly EventService $eventService,private readonly SettingsService $settingsService,private readonly CalendarImporter $calendarImporter,private readonly CalendarPdfExtractor $calendarPdf,private readonly View $view) {}

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

    public function reactivateEvent(Request $request,array $params): Response
    {
        Csrf::verify($request->body['_csrf']??null);$this->eventService->reactivate((int)$params['id'],(int)$_SESSION['user']['id'],$request->ip(),$request->header('User-Agent')??'');$_SESSION['flash']='Evento APC reativado e disponível novamente aos professores.';return Response::redirect('/apc/admin#calendario');
    }

    public function importSchoolCalendar(Request $request): Response
    {
        Csrf::verify($request->body['_csrf']??null);$summary=$this->calendarImporter->import((int)$_SESSION['user']['id'],$request->ip(),$request->header('User-Agent')??'');$_SESSION['flash']="Calendário oficial importado: {$summary['total']} eventos; {$summary['criados']} criados, {$summary['atualizados']} atualizados, {$summary['conciliados']} conciliados e {$summary['inalterados']} inalterados.";return Response::redirect('/apc/admin#calendario');
    }

    public function analyzeSchoolCalendar(Request$request):Response
    {
        Csrf::verify($request->body['_csrf']??null);$file=$_FILES['calendario']??[];if(!is_array($file))throw new HttpException(422,'APC_CALENDAR_UPLOAD_INVALID','Selecione o calendário anual em PDF.');$analysis=$this->calendarPdf->analyze($file);$token=bin2hex(random_bytes(18));$_SESSION[self::CALENDAR_ANALYSIS_SESSION]=[$token=>['user_id'=>(int)$_SESSION['user']['id'],'expires_at'=>time()+1800,'analysis'=>$analysis]];return Response::redirect('/apc/admin/calendario/revisar?token='.$token);
    }

    public function reviewSchoolCalendar(Request$request):Response
    {
        $token=(string)($request->query['token']??'');$analysis=$this->calendarAnalysis($token);return new Response($this->view->render('calendar_review',compact('analysis','token')+['title'=>'Conferir calendário APC']));
    }

    public function confirmSchoolCalendar(Request$request):Response
    {
        Csrf::verify($request->body['_csrf']??null);$token=(string)($request->body['token']??'');$analysis=$this->calendarAnalysis($token);$rows=$this->calendarPdf->confirmedRows($analysis,$request->body);
        try{$summary=$this->calendarImporter->importRows($rows,(int)$_SESSION['user']['id'],$request->ip(),$request->header('User-Agent')??'',(string)$analysis['file_name']);}catch(\RuntimeException$exception){throw new HttpException(422,'APC_CALENDAR_IMPORT_FAILED',$exception->getMessage());}
        unset($_SESSION[self::CALENDAR_ANALYSIS_SESSION][$token]);$_SESSION['flash']="Calendário de {$analysis['year']} importado: {$summary['total']} APC(s); {$summary['criados']} criada(s), {$summary['atualizados']} atualizada(s), {$summary['conciliados']} conciliada(s) e {$summary['inalterados']} inalterada(s).";return Response::redirect('/apc/admin?ano='.(int)$analysis['year'].'#calendario');
    }

    public function updateSettings(Request $request): Response
    {
        Csrf::verify($request->body['_csrf']??null);$this->settingsService->update($request->body,(int)$_SESSION['user']['id'],$request->ip(),$request->header('User-Agent')??'');$_SESSION['flash']='Parâmetros APC atualizados.';return Response::redirect('/apc/admin#parametros');
    }

    /** @return array<string,mixed> */
    private function calendarAnalysis(string$token):array
    {
        if(!preg_match('/^[a-f0-9]{36}$/D',$token))throw new HttpException(404,'APC_CALENDAR_ANALYSIS_NOT_FOUND','A análise do calendário não foi encontrada. Envie o PDF novamente.');$entry=$_SESSION[self::CALENDAR_ANALYSIS_SESSION][$token]??null;
        if(!is_array($entry)||(int)($entry['user_id']??0)!==(int)$_SESSION['user']['id']||(int)($entry['expires_at']??0)<time()||!is_array($entry['analysis']??null)){unset($_SESSION[self::CALENDAR_ANALYSIS_SESSION][$token]);throw new HttpException(410,'APC_CALENDAR_ANALYSIS_EXPIRED','A análise do calendário expirou. Envie o PDF novamente.');}return$entry['analysis'];
    }

}
