<?php declare(strict_types=1);
namespace Shared\Http;
use Shared\Exceptions\HttpException;

final class Router
{
    private array $routes = [];
    public function add(array|string $methods, string $path, callable $handler, array $middlewares = []): void
    { foreach ((array)$methods as $method) $this->routes[] = [strtoupper($method), $path, $handler, $middlewares]; }
    public function dispatch(Request $request): Response
    {
        $pathMatched = false;
        foreach ($this->routes as [$method,$pattern,$handler,$middlewares]) {
            $regex = '#^' . preg_replace('#\{([A-Za-z_][A-Za-z0-9_]*)\}#', '(?P<$1>[0-9]+)', $pattern) . '$#';
            if (!preg_match($regex, $request->path, $matches)) continue;
            $pathMatched = true; if ($method !== $request->method) continue;
            $params = array_filter($matches, 'is_string', ARRAY_FILTER_USE_KEY);
            $next = fn(Request $r) => $handler($r, $params);
            foreach (array_reverse($middlewares) as $middleware) { $prior=$next; $next=fn(Request $r)=>$middleware($r,$prior); }
            return $next($request);
        }
        throw new HttpException($pathMatched ? 405 : 404, $pathMatched ? 'METHOD_NOT_ALLOWED' : 'NOT_FOUND', $pathMatched ? 'Método não permitido.' : 'Recurso não encontrado.');
    }
}
