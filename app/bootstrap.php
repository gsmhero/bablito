<?php
declare(strict_types=1);

define('ROOT', dirname(__DIR__));
mb_internal_encoding('UTF-8');

function load_env(string $file): array
{
    $env = [];
    if (!is_file($file)) {
        return $env;
    }
    foreach (file($file, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) as $line) {
        $line = rtrim($line, "\r");
        if (!preg_match('/^\s*(?:export\s+)?([A-Za-z0-9_]+)\s*=\s*(.*?)\s*$/', $line, $m)) {
            continue;
        }
        $v = $m[2];
        if (strlen($v) >= 2 && $v[0] === '"' && substr($v, -1) === '"') {
            $v = strtr(substr($v, 1, -1), ['\\\\' => '\\', '\\"' => '"']);
        } elseif (strlen($v) >= 2 && $v[0] === "'" && substr($v, -1) === "'") {
            $v = substr($v, 1, -1);
        }
        $env[$m[1]] = $v;
    }
    return $env;
}

$ENV = load_env(ROOT . '/.env');
function env(string $k, string $default = ''): string
{
    global $ENV;
    return $ENV[$k] ?? $default;
}

function ensure_dirs(): void
{
    foreach (['uploads', 'storage'] as $d) {
        $p = ROOT . '/' . $d;
        if (!is_dir($p)) {
            @mkdir($p, 0755, true);
        }
    }
    $ht = ROOT . '/uploads/.htaccess';
    $rules = '<FilesMatch "\.(php|phtml|phar)$">' . "\n  Require all denied\n</FilesMatch>\n";
    if (!is_file($ht) || file_get_contents($ht) !== $rules) {
        @file_put_contents($ht, $rules);
    }
    $ht = ROOT . '/storage/.htaccess';
    if (!is_file($ht)) {
        @file_put_contents($ht, "Require all denied\n");
    }
}

function db(): PDO
{
    static $pdo = null;
    if ($pdo === null) {
        $dsn = 'mysql:host=' . env('DB_HOST', 'localhost') . ';dbname=' . env('DB_NAME') . ';charset=utf8mb4';
        $pdo = new PDO($dsn, env('DB_USER'), env('DB_PASS'), [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES => true,
        ]);
        $pdo->exec("SET time_zone = '+00:00'");
    }
    return $pdo;
}

function q(string $sql, array $params = []): PDOStatement
{
    $st = db()->prepare($sql);
    $st->execute($params);
    return $st;
}
function row(string $sql, array $params = []): ?array
{
    $r = q($sql, $params)->fetch();
    return $r === false ? null : $r;
}
function rows(string $sql, array $params = []): array
{
    return q($sql, $params)->fetchAll();
}
function val(string $sql, array $params = [])
{
    $r = q($sql, $params)->fetchColumn();
    return $r === false ? null : $r;
}

set_exception_handler(function (Throwable $e) {
    error_log((string)$e);
    $line = '[' . date('c') . '] ' . $e . PHP_EOL . PHP_EOL;
    if (!@file_put_contents(ROOT . '/storage/error.log', $line, FILE_APPEND)) {
        @file_put_contents(ROOT . '/error.log', $line, FILE_APPEND);
    }
    // Short hint without quoted values (no user names, hosts or paths).
    $hint = get_class($e) . ': ' . preg_replace("/'[^']*'/", "'...'", $e->getMessage());
    http_response_code(500);
    echo '<!doctype html><meta charset="utf-8"><title>Error</title><p style="font-family:sans-serif;padding:2rem">Something went wrong. Please try again later.</p><!-- ' . htmlspecialchars($hint, ENT_NOQUOTES) . ' -->';
});

ensure_dirs();

session_set_cookie_params([
    'lifetime' => 0,
    'path' => '/',
    'secure' => (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off'),
    'httponly' => true,
    'samesite' => 'Lax',
]);
session_start();

require ROOT . '/app/i18n.php';
if (current_lang() === 'ru') {
    ob_start('i18n_filter');
}

require ROOT . '/app/migrate.php';
migrate_if_needed();
require ROOT . '/app/helpers.php';
require ROOT . '/app/extras.php';
require ROOT . '/app/profile_fields.php';
require ROOT . '/app/invoices.php';
require_once ROOT . '/app/types.php';
require_once ROOT . '/app/invoice_templates.php';

try {
    archive_stale_contracts();
} catch (Throwable $e) {
    error_log((string)$e);
}
