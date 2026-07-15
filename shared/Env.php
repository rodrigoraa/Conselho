<?php declare(strict_types=1);
namespace Shared;

final class Env
{
    private static array $values = [];
    public static function load(string $file): void
    {
        if (!is_file($file)) return;
        foreach (file($file, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: [] as $line) {
            $line = trim($line);
            if ($line === '' || str_starts_with($line, '#') || !str_contains($line, '=')) continue;
            [$key, $value] = explode('=', $line, 2);
            $key = trim($key); $value = trim($value, " \t\n\r\0\x0B\"'");
            if ($key !== '' && getenv($key) === false) self::$values[$key] = $value;
        }
    }
    public static function get(string $key, ?string $default = null): ?string
    { $value = getenv($key); return $value === false ? (self::$values[$key] ?? $default) : $value; }
    public static function bool(string $key, bool $default = false): bool
    { return filter_var(self::get($key, $default ? 'true' : 'false'), FILTER_VALIDATE_BOOL); }
    public static function int(string $key, int $default): int { return (int) self::get($key, (string)$default); }
}
