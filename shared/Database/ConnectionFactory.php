<?php declare(strict_types=1);
namespace Shared\Database;
use PDO; use PDOException; use RuntimeException;

final class ConnectionFactory
{
    public static function preconselho(string $path): PDO
    {
        self::ensureDirectory($path);
        $pdo = new PDO('sqlite:' . $path, null, null, self::options());
        self::pragmas($pdo, false); return $pdo;
    }
    public static function secretariaReadOnly(string $path): PDO
    {
        if (!is_file($path)) throw new RuntimeException('Banco da secretaria indisponível.');
        $uri = 'sqlite:file:' . str_replace('\\', '/', $path) . '?mode=ro';
        try { $pdo = new PDO($uri, null, null, self::options()); self::pragmas($pdo, true); return $pdo; }
        catch (PDOException $e) { throw new RuntimeException('Banco da secretaria indisponível.', 0, $e); }
    }
    private static function options(): array { return [PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE=>PDO::FETCH_ASSOC, PDO::ATTR_EMULATE_PREPARES=>false]; }
    private static function pragmas(PDO $pdo, bool $readonly): void
    { $pdo->exec('PRAGMA foreign_keys = ON'); $pdo->exec('PRAGMA busy_timeout = 5000'); if ($readonly) $pdo->exec('PRAGMA query_only = ON'); else $pdo->exec('PRAGMA journal_mode = WAL'); }
    private static function ensureDirectory(string $path): void
    { $dir=dirname($path); if (!is_dir($dir) && !mkdir($dir, 0770, true) && !is_dir($dir)) throw new RuntimeException('Não foi possível criar o diretório de dados.'); }
}
