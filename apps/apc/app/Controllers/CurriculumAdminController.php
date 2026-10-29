<?php declare(strict_types=1);

namespace Apc\Controllers;

use Apc\Repositories\CurriculumRepository;
use Apc\Services\{CurriculumImporter,CurriculumService};
use PreConselho\Support\Csrf;
use Shared\Exceptions\HttpException;
use Shared\Http\{Request,Response};
use Shared\Support\View;

final class CurriculumAdminController
{
    public function __construct(private readonly CurriculumRepository $curriculum,private readonly CurriculumService $service,private readonly CurriculumImporter $importer,private readonly View $view) {}
    public function index(Request $request): Response
    {
        $filters=$this->filters($request->query);$total=$this->curriculum->countAbilities($filters);$pages=max(1,(int)ceil($total/25));$page=min($pages,max(1,(int)($request->query['pagina']??1)));$components=$this->curriculum->components(null,true);$abilities=$this->curriculum->abilities($filters,25,($page-1)*25);$editingAbility=null;if(($request->query['editar_habilidade']??'')!=='')$editingAbility=$this->curriculum->ability((int)$request->query['editar_habilidade']);return new Response($this->view->render('curriculum_admin',compact('components','abilities','filters','editingAbility','total','page','pages')+['title'=>'Catálogo curricular APC']));
    }
    public function createComponent(Request$request):Response{return$this->saveComponent(null,$request);}
    public function updateComponent(Request$request,array$params):Response{return$this->saveComponent((int)$params['id'],$request);}
    public function toggleComponent(Request$request,array$params):Response{Csrf::verify($request->body['_csrf']??null);$this->service->toggleComponent((int)$params['id'],$_SESSION['user'],$request->ip(),$request->header('User-Agent')??'');$_SESSION['flash']='Situação do componente atualizada.';return Response::redirect($this->returnUrl($request,'componentes'));}
    public function createAbility(Request$request):Response{return$this->saveAbility(null,$request);}
    public function updateAbility(Request$request,array$params):Response{return$this->saveAbility((int)$params['id'],$request);}
    public function toggleAbility(Request$request,array$params):Response{Csrf::verify($request->body['_csrf']??null);$this->service->toggleAbility((int)$params['id'],$_SESSION['user'],$request->ip(),$request->header('User-Agent')??'');$_SESSION['flash']='Situação da habilidade atualizada.';return Response::redirect($this->returnUrl($request,'habilidades'));}
    public function import(Request$request):Response{Csrf::verify($request->body['_csrf']??null);$user=$_SESSION['user'];if(($user['perfil']??'')!=='ADMIN')throw new HttpException(403,'APC_FORBIDDEN','Apenas a administração pode importar o catálogo curricular.');$summary=$this->importer->import(null,(int)$user['id'],$request->ip(),$request->header('User-Agent')??'');$_SESSION['flash']="Catálogo importado: {$summary['componentes']} componentes e {$summary['habilidades']} habilidades.";return Response::redirect($this->returnUrl($request,'habilidades'));}
    private function filters(array $input): array
    {
        $filters=[];foreach(['etapa','ano_serie','componente','origem','escopo','ativo','q']as$key)$filters[$key]=mb_substr(trim(is_scalar($input[$key]??'')?(string)($input[$key]??''):''),0,160);return$filters;
    }
    private function returnUrl(Request $request,string $anchor): string
    {
        $context=is_array($request->body['contexto']??null)?$request->body['contexto']:[];$filters=$this->filters($context);$filters['pagina']=max(1,(int)($context['pagina']??1));return'/apc/admin/curriculo?'.http_build_query($filters).'#'.$anchor;
    }
    private function saveComponent(?int$id,Request$request):Response{Csrf::verify($request->body['_csrf']??null);$this->service->saveComponent($id,$request->body,$_SESSION['user'],$request->ip(),$request->header('User-Agent')??'');$_SESSION['flash']=$id?'Componente atualizado.':'Componente criado.';return Response::redirect($this->returnUrl($request,'componentes'));}
    private function saveAbility(?int$id,Request$request):Response{Csrf::verify($request->body['_csrf']??null);$this->service->saveAbility($id,$request->body,$_SESSION['user'],$request->ip(),$request->header('User-Agent')??'');$_SESSION['flash']=$id?'Habilidade atualizada.':'Habilidade criada.';return Response::redirect($this->returnUrl($request,'habilidades'));}
}
