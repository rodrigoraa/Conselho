<?php declare(strict_types=1);
namespace PreConselho\Middlewares;use Shared\Exceptions\HttpException;use Shared\Http\{Request,Response};
final class AuthMiddleware{public function __invoke(Request$r,callable$n):mixed{if(empty($_SESSION['user']))return Response::redirect('/login');if(!empty($_SESSION['user']['alterar_senha'])&&!in_array($r->path,['/minha-senha','/logout'],true))return Response::redirect('/minha-senha');return$n($r);}}
