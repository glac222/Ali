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
require $src . '/acceso.php';

$uri  = $_SERVER['REQUEST_URI'] ?? '/';
$path = parse_url($uri, PHP_URL_PATH) ?: '/';
$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';

// Con `php -S` (solo dev): dejar que el servidor sirva archivos estaticos reales.
// En produccion Apache/LiteSpeed nunca llega aqui para estaticos (ver .htaccess).
if (PHP_SAPI === 'cli-server' && $path !== '/' && is_file(__DIR__ . $path)) {
    return false;
}

if (preg_match('#^/api(?:/(.*))?$#', $path, $m)) {
    // La API es privada (src/acceso.php). Libres: el preflight, health y admin (que pide su propio token).
    $ruta = explode('/', trim($m[1] ?? '', '/'))[0];
    if ($method !== 'OPTIONS' && !in_array($ruta, ['health', 'admin'], true) && !ali_autorizado()) {
        fail('Acceso privado: abre tu enlace de Mi Cocina.', 401);
    }
    require $src . '/routes.php';
    handle_api($method, $m[1] ?? '');
    exit;
}

// --- Entrar con el enlace privado (?llave=...) ---------------------------
if (isset($_GET['llave'])) {
    if (!ali_clave_web_valida((string) $_GET['llave'])) {
        ali_pagina_acceso('Esa clave no sirve.');
    }
    ali_dar_cookie();
    header('Cache-Control: no-store');
    header('Location: ' . $path, true, 303);   // sin la clave en la barra ni en el historial
    exit;
}
if (!ali_acceso_navegador()) {
    ali_pagina_acceso();
}
ali_dar_cookie();   // renueva el año

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
