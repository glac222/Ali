<?php
declare(strict_types=1);

/**
 * Front controller unico de ali.calimundo.com
 *  - /api/*  -> API JSON (PHP + MariaDB)
 *  - resto   -> SPA React compilada (index.html junto a este archivo)
 *
 * Funciona con dos layouts:
 *   A) docroot = php/public/  -> src/ y db/ estan en dirname(__DIR__)
 *   B) todo plano en la carpeta del subdominio -> src/ y db/ estan en __DIR__
 */

$src = null;
foreach ([dirname(__DIR__), __DIR__] as $cand) {
    if (is_file($cand . '/src/bootstrap.php')) {
        $src = $cand . '/src';
        break;
    }
}
if ($src === null) {
    http_response_code(500);
    header('Content-Type: text/plain; charset=utf-8');
    echo "No se encuentra src/. Revisa la estructura de archivos (ver DEPLOY.md).";
    exit;
}

require $src . '/bootstrap.php';

$uri  = $_SERVER['REQUEST_URI'] ?? '/';
$path = parse_url($uri, PHP_URL_PATH) ?: '/';
$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';

// Con `php -S` (solo dev): dejar que el servidor sirva archivos estaticos reales.
// En produccion Apache/LiteSpeed nunca llega aqui para estaticos (ver .htaccess).
if (PHP_SAPI === 'cli-server' && $path !== '/' && is_file(__DIR__ . $path)) {
    return false;
}

if (preg_match('#^/api(?:/(.*))?$#', $path, $m)) {
    require $src . '/routes.php';
    handle_api($method, $m[1] ?? '');
    exit;
}

// --- SPA fallback --------------------------------------------------------
$index = __DIR__ . '/index.html';
if (is_file($index)) {
    header('Content-Type: text/html; charset=utf-8');
    readfile($index);
    exit;
}

http_response_code(503);
header('Content-Type: text/plain; charset=utf-8');
echo "Falta compilar el frontend: sube el contenido de client/dist a esta carpeta.";
