<?php declare(strict_types=1);
namespace SecretariaApi\Middlewares;
use Shared\Env; use Shared\Exceptions\HttpException; use Shared\Http\Request;
final class ApiKeyMiddleware
{
    public function __invoke(Request $r, callable $next): mixed { $expected=Env::get('SECRETARIA_API_KEY','');$given=$r->header('X-API-Key')??'';if($expected===''||$given===''||!hash_equals($expected,$given))throw new HttpException(401,'UNAUTHORIZED','Chave de API inválida.');return$next($r); }
}
