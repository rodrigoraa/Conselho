<?php declare(strict_types=1);
namespace PreConselho\Middlewares;use Shared\Exceptions\HttpException;use Shared\Http\Request;
final class RoleMiddleware{public function __construct(private readonly array$roles){}public function __invoke(Request$r,callable$n):mixed{if(!in_array($_SESSION['user']['perfil']??'', $this->roles,true))throw new HttpException(403,'FORBIDDEN','Acesso não permitido.');return$n($r);}}
