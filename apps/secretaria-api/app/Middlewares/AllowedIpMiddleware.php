<?php declare(strict_types=1);
namespace SecretariaApi\Middlewares;
use Shared\Env;use Shared\Exceptions\HttpException;use Shared\Http\Request;
final class AllowedIpMiddleware{public function __invoke(Request$r,callable$next):mixed{$allowed=array_filter(array_map('trim',explode(',',Env::get('SECRETARIA_API_ALLOWED_IPS','127.0.0.1,::1')??'')));if($allowed&&!in_array($r->ip(),$allowed,true))throw new HttpException(403,'FORBIDDEN','Acesso não permitido.');return$next($r);}}
