<?php
declare(strict_types=1);

/**
 * Arranque comun: carga .env, abre PDO, define helpers de respuesta JSON.
 * Sin Composer, sin frameworks. PHP 8.x + MariaDB via PDO.
 */

define('APP_ROOT', dirname(__DIR__));

// --- .env minimalista -------------------------------------------------------
function load_env(string $path): void
{
    if (!is_file($path)) {
        return;
    }
    foreach (file($path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) as $line) {
        $line = trim($line);
        if ($line === '' || $line[0] === '#') {
            continue;
        }
        $eq = strpos($line, '=');
        if ($eq === false) {
            continue;
        }
        $key = trim(substr($line, 0, $eq));
        $val = trim(substr($line, $eq + 1));
        if (strlen($val) >= 2 && ($val[0] === '"' || $val[0] === "'") && $val[-1] === $val[0]) {
            $val = substr($val, 1, -1);
        }
        if ($key !== '' && getenv($key) === false) {
            putenv("$key=$val");
            $_ENV[$key] = $val;
        }
    }
}

function env(string $key, ?string $default = null): ?string
{
    $v = getenv($key);
    if ($v === false || $v === '') {
        return $default;
    }
    return $v;
}

load_env(APP_ROOT . '/.env');

// --- Errores como JSON, nunca HTML de PHP ----------------------------------
error_reporting(E_ALL);
ini_set('display_errors', '0');

set_exception_handler(function (Throwable $e): void {
    error_log('[micocina] ' . $e->getMessage() . ' @ ' . $e->getFile() . ':' . $e->getLine());
    json_out(['error' => 'Error interno del servidor'], 500);
});

set_error_handler(function (int $no, string $str, string $file, int $line): bool {
    if (!(error_reporting() & $no)) {
        return false;
    }
    throw new ErrorException($str, 0, $no, $file, $line);
});

// --- PDO singleton ---------------------------------------------------------
function db(): PDO
{
    static $pdo = null;
    if ($pdo instanceof PDO) {
        return $pdo;
    }
    $host = env('DB_HOST', 'localhost');
    $port = env('DB_PORT', '3306');
    $name = env('DB_NAME', '');
    $user = env('DB_USER', '');
    $pass = env('DB_PASS', '');
    $dsn = "mysql:host={$host};port={$port};dbname={$name};charset=utf8mb4";
    $pdo = new PDO($dsn, $user, $pass, [
        PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        PDO::ATTR_EMULATE_PREPARES   => false,
        PDO::ATTR_STRINGIFY_FETCHES  => false,
    ]);
    return $pdo;
}

/** SELECT que devuelve todas las filas. */
function q_all(string $sql, array $params = []): array
{
    $st = db()->prepare($sql);
    $st->execute($params);
    return $st->fetchAll();
}

/** SELECT que devuelve una fila o null. */
function q_one(string $sql, array $params = []): ?array
{
    $st = db()->prepare($sql);
    $st->execute($params);
    $row = $st->fetch();
    return $row === false ? null : $row;
}

/** INSERT/UPDATE/DELETE. Devuelve el numero de filas afectadas. */
function q_exec(string $sql, array $params = []): int
{
    $st = db()->prepare($sql);
    $st->execute($params);
    return $st->rowCount();
}

function last_id(): int
{
    return (int) db()->lastInsertId();
}

// --- Helpers HTTP --------------------------------------------------------
function json_out($data, int $status = 200): never
{
    http_response_code($status);
    header('Content-Type: application/json; charset=utf-8');
    $origin = env('CORS_ORIGIN', '*');
    header('Access-Control-Allow-Origin: ' . $origin);
    header('Vary: Origin');
    if ($status === 204) {
        exit;
    }
    echo json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

function no_content(): never
{
    json_out(null, 204);
}

function fail(string $msg, int $status = 400): never
{
    json_out(['error' => $msg], $status);
}

/** Cuerpo JSON de la peticion como array asociativo. */
function body(): array
{
    static $cache = null;
    if ($cache !== null) {
        return $cache;
    }
    $raw = file_get_contents('php://input') ?: '';
    if ($raw === '') {
        return $cache = [];
    }
    $data = json_decode($raw, true);
    return $cache = is_array($data) ? $data : [];
}
