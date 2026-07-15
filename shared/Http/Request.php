<?php declare(strict_types=1);
namespace Shared\Http;

final class Request
{
    public function __construct(public readonly string $method, public readonly string $path, public readonly array $query, public readonly array $body, public readonly array $server) {}
    public static function capture(): self
    {
        $method = strtoupper($_SERVER['REQUEST_METHOD'] ?? 'GET');
        $path = '/' . trim((string)(parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH) ?: '/'), '/');
        $type = strtolower((string)($_SERVER['CONTENT_TYPE'] ?? ''));
        $body = $_POST;
        if (str_contains($type, 'application/json')) {
            $decoded = json_decode((string)file_get_contents('php://input'), true);
            if (is_array($decoded)) $body = $decoded;
        }
        return new self($method, $path === '//' ? '/' : $path, $_GET, $body, $_SERVER);
    }
    public function header(string $name): ?string
    {
        $key = 'HTTP_' . strtoupper(str_replace('-', '_', $name));
        return isset($this->server[$key]) ? trim((string)$this->server[$key]) : null;
    }
    public function ip(): string { return (string)($this->server['REMOTE_ADDR'] ?? 'unknown'); }
}
