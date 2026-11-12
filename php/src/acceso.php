<?php
declare(strict_types=1);

/**
 * Puerta de Ali: la app y su API son privadas (plan de la oficina 6.4.2, 12-nov-2026).
 *
 * Dos formas de entrar:
 *  - Navegador de Gus: abre UNA vez por dispositivo el enlace privado https://ali.calimundo.com/?llave=<ALI_CLAVE_WEB>.
 *    Queda una cookie firmada por un año (se renueva sola al usar la app). Cambiar ALI_CLAVE_WEB cierra todas las
 *    sesiones.
 *  - La oficina (Hermes y ali-jefe): cabecera «X-Ali-Llave: <ALI_LLAVE_OFICINA>» en cada llamada a /api.
 *
 * Sin la clave puesta en .env no entra nadie (falla cerrada). /api/health y /api/admin (que tiene su propio token)
 * quedan como estaban.
 */

const ALI_COOKIE = 'ali_acceso';
const ALI_COOKIE_DIAS = 365;

function ali_firma(string $exp, string $clave): string
{
    return hash_hmac('sha256', 'ali-acceso.' . $exp, $clave);
}

/** ¿Es la clave del enlace privado del navegador? */
function ali_clave_web_valida(string $dada): bool
{
    $clave = env('ALI_CLAVE_WEB', '') ?? '';
    return strlen($clave) >= 20 && hash_equals($clave, $dada);
}

/** Llamada de la oficina con su llave en la cabecera. */
function ali_acceso_oficina(): bool
{
    $llave = env('ALI_LLAVE_OFICINA', '') ?? '';
    $dada = (string) ($_SERVER['HTTP_X_ALI_LLAVE'] ?? '');
    return strlen($llave) >= 20 && $dada !== '' && hash_equals($llave, $dada);
}

/** Navegador que ya entró con el enlace privado (cookie firmada y vigente). */
function ali_acceso_navegador(): bool
{
    $clave = env('ALI_CLAVE_WEB', '') ?? '';
    $cookie = (string) ($_COOKIE[ALI_COOKIE] ?? '');
    if (strlen($clave) < 20 || !preg_match('/^(\d{10})\.([0-9a-f]{64})$/', $cookie, $m)) {
        return false;
    }
    return (int) $m[1] > time() && hash_equals(ali_firma($m[1], $clave), $m[2]);
}

function ali_autorizado(): bool
{
    return ali_acceso_oficina() || ali_acceso_navegador();
}

/** Da (o renueva) la cookie del navegador por un año. */
function ali_dar_cookie(): void
{
    $clave = env('ALI_CLAVE_WEB', '') ?? '';
    if (strlen($clave) < 20) {
        return;
    }
    $exp = (string) (time() + ALI_COOKIE_DIAS * 86400);
    $https = ($_SERVER['HTTPS'] ?? '') !== '' && ($_SERVER['HTTPS'] ?? '') !== 'off'
        || ($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https';
    setcookie(ALI_COOKIE, $exp . '.' . ali_firma($exp, $clave), [
        'expires' => (int) $exp,
        'path' => '/',
        'secure' => $https,
        'httponly' => true,
        'samesite' => 'Lax',
    ]);
}

/** Página para quien llega sin haber entrado: la app es privada. */
function ali_pagina_acceso(string $aviso = ''): never
{
    http_response_code($aviso === '' ? 401 : 403);
    header('Content-Type: text/html; charset=utf-8');
    header('Cache-Control: no-store');
    $aviso = htmlspecialchars($aviso, ENT_QUOTES, 'UTF-8');
    echo <<<HTML
<!doctype html>
<html lang="es">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<meta name="robots" content="noindex">
<title>Mi Cocina</title>
<style>
  body { margin: 0; min-height: 100vh; display: grid; place-items: center; background: #f6f3ee; color: #222;
         font: 16px/1.5 system-ui, -apple-system, "Segoe UI", sans-serif; }
  main { max-width: 360px; padding: 24px 16px; }
  h1 { font-size: 1.3rem; margin: 0 0 8px; }
  p { margin: 0 0 16px; color: #555; }
  .aviso { color: #a33; }
  input { width: 100%; box-sizing: border-box; padding: 12px; font-size: 1rem; border: 1px solid #bbb;
          border-radius: 8px; background: #fff; color: #222; }
  button { margin-top: 12px; width: 100%; padding: 12px; font-size: 1rem; border: 0; border-radius: 8px;
           background: #2f6b4f; color: #fff; }
</style>
</head>
<body>
<main>
  <h1>Mi Cocina es privada</h1>
  <p>Abre el enlace de acceso que tienes guardado. Solo hace falta una vez en cada teléfono o computadora.</p>
  <p class="aviso">{$aviso}</p>
  <form method="get" action="/">
    <input name="llave" type="password" placeholder="O pega aquí tu clave de acceso" autocomplete="current-password" required>
    <button type="submit">Entrar</button>
  </form>
</main>
</body>
</html>
HTML;
    exit;
}
