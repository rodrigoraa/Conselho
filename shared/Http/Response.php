<?php declare(strict_types=1);
namespace Shared\Http;

final class Response
{
    public function __construct(public readonly string $body = '', public readonly int $status = 200, public readonly array $headers = []) {}
    public static function json(mixed $data, int $status = 200, array $headers = []): self
    { return new self((string)json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR), $status, ['Content-Type'=>'application/json; charset=utf-8'] + $headers); }
    public static function redirect(string $path, int $status = 302): self { return new self('', $status, ['Location'=>$path]); }
    public function send(): never
    { http_response_code($this->status); foreach ($this->headers as $k=>$v) header("$k: $v"); echo $this->body; exit; }
}
