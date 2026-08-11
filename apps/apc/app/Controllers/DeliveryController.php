<?php declare(strict_types=1);

namespace Apc\Controllers;

use Apc\Services\DeliveryService;
use PreConselho\Support\Csrf;
use Shared\Http\{Request,Response};
use Shared\Support\View;

final class DeliveryController
{
    public function __construct(private readonly DeliveryService $service,private readonly View $view) {}

    public function index(Request $request,array $params): Response
    {
        $data=$this->service->students((int)$params['id'],$_SESSION['user']);return new Response($this->view->render('deliveries',$data+['title'=>'Entregas dos alunos']));
    }

    public function save(Request $request,array $params): Response
    {
        Csrf::verify($request->body['_csrf']??null);$planId=(int)$params['id'];$this->service->save($planId,(int)$params['aluno'],$request->body,$_SESSION['user'],$request->ip(),$request->header('User-Agent')??'');$_SESSION['flash']='Entrega do aluno atualizada.';return Response::redirect('/apc/planos/'.$planId.'/entregas#aluno-'.(int)$params['aluno']);
    }
}
