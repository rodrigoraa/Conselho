<?php declare(strict_types=1);
namespace PreConselho\Middlewares;use Shared\Exceptions\HttpException;use Shared\Http\{Request,Response};
final class AuthMiddleware{public function __invoke(Request$r,callable$n):mixed{if(empty($_SESSION['user']))return Response::redirect('/login');return$n($r);}}
