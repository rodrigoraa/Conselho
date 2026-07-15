<?php declare(strict_types=1);
namespace Shared\Support;
use RuntimeException;
final class View
{
    public function __construct(private readonly string $base) {}
    public function render(string $name, array $data=[]): string
    { $file=$this->base.'/'.$name.'.php'; if(!is_file($file)) throw new RuntimeException('View não encontrada.'); extract($data, EXTR_SKIP); ob_start(); require $file; return (string)ob_get_clean(); }
}
