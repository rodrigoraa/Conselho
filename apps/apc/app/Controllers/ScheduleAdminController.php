<?php declare(strict_types=1);

namespace Apc\Controllers;

use Apc\Repositories\ScheduleRepository;
use Apc\Services\ScheduleService;
use PreConselho\Support\Csrf;
use Shared\Exceptions\HttpException;
use Shared\Http\{Request,Response};
use Shared\Support\View;

final class ScheduleAdminController
{
    private const SESSION_KEY='apc_schedule_analyses';
    public function __construct(private readonly ScheduleService$service,private readonly ScheduleRepository$schedules,private readonly View$view){}

    public function index(Request$request):Response{$year=filter_var($request->query['ano']??null,FILTER_VALIDATE_INT);$imports=$this->schedules->imports($year===false?null:($year?:null));return new Response($this->view->render('schedule_admin',compact('imports','year')+['title'=>'Grades de horários APC']));}
    public function analyze(Request$request):Response
    {
        Csrf::verify($request->body['_csrf']??null);$file=$_FILES['horario']??[];if(!is_array($file))throw new HttpException(422,'APC_SCHEDULE_UPLOAD_INVALID','Selecione a grade em XLSX.');$analysis=$this->service->analyze($file,$request->body);$token=bin2hex(random_bytes(18));$stored=$_SESSION[self::SESSION_KEY]??[];foreach($stored as$key=>$item)if((int)($item['expires_at']??0)<time())unset($stored[$key]);$stored[$token]=['user_id'=>(int)$_SESSION['user']['id'],'expires_at'=>time()+1800,'analysis'=>$analysis];$_SESSION[self::SESSION_KEY]=$stored;return Response::redirect('/apc/admin/horarios/revisar?token='.$token);
    }
    public function review(Request$request):Response{$token=$this->token($request->query['token']??'');$analysis=$this->analysis($token);return new Response($this->view->render('schedule_review',compact('analysis','token')+['title'=>'Conferir grade APC']));}
    public function confirm(Request$request):Response
    {
        Csrf::verify($request->body['_csrf']??null);$token=$this->token($request->body['token']??'');$analysis=$this->analysis($token);$this->service->confirm($analysis,$request->body,(int)$_SESSION['user']['id'],$request->ip(),$request->header('User-Agent')??'');unset($_SESSION[self::SESSION_KEY][$token]);$_SESSION['flash']='Grade de horários confirmada e versionada com sucesso.';return Response::redirect('/apc/admin/horarios?ano='.(int)$analysis['ano_letivo']);
    }
    public function deactivate(Request$request,array$params):Response{Csrf::verify($request->body['_csrf']??null);$this->service->deactivate((int)$params['id'],(int)$_SESSION['user']['id'],$request->ip(),$request->header('User-Agent')??'');$_SESSION['flash']='Versão da grade desativada sem alterar obrigações históricas.';return Response::redirect('/apc/admin/horarios');}
    private function analysis(string$token):array{$item=$_SESSION[self::SESSION_KEY][$token]??null;if(!$item||(int)($item['user_id']??0)!==(int)$_SESSION['user']['id']||(int)($item['expires_at']??0)<time()){unset($_SESSION[self::SESSION_KEY][$token]);throw new HttpException(404,'APC_SCHEDULE_REVIEW_EXPIRED','A análise expirou. Envie a planilha novamente.');}return$item['analysis'];}
    private function token(mixed$value):string{$token=trim((string)$value);if(!preg_match('/^[a-f0-9]{36}$/',$token))throw new HttpException(404,'APC_SCHEDULE_REVIEW_NOT_FOUND','Análise de grade não encontrada.');return$token;}
}
