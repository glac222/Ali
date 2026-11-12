<?php
declare(strict_types=1);

/**
 * Puerta de Ali: la app y su API son privadas (plan de la oficina 6.4.2, 12-nov-2026).
 *
 * Dos formas de entrar:
 *  - Gus, en el navegador: usuario y contraseña (pedido de Gus, 12-nov-2026: nada de enlaces ni claves raras). Al
 *    entrar queda una cookie firmada por un año en ese dispositivo (se renueva sola al usar la app).
 *    La primera vez, mientras no hay contraseña, la pantalla le pide crearla; eso solo funciona hasta ALI_ALTA_HASTA
 *    (marca de tiempo en el .env.local; después se cierra aunque nadie la haya creado). La contraseña queda en
 *    APP_ROOT/.acceso.json, solo su hash (password_hash). Para cambiarla u olvidarla: borrar ese archivo y abrir de
 *    nuevo la ventana con ALI_ALTA_HASTA.
 *  - La oficina (Hermes y ali-jefe): cabecera «X-Ali-Llave: <ALI_LLAVE_OFICINA>» en cada llamada a /api.
 *
 * ALI_CLAVE_WEB ya no la usa Gus: es el secreto con que el servidor firma las cookies. Cambiarla cierra todas las
 * sesiones. Sin ella, o sin la llave de la oficina, no entra nadie (falla cerrada). /api/health y /api/admin (que
 * tiene su propio token) quedan como estaban.
 */

const ALI_COOKIE = 'ali_acceso';
const ALI_COOKIE_DIAS = 365;
const ALI_INTENTOS = 5;          // contraseñas malas permitidas…
const ALI_VENTANA = 15 * 60;     // …cada 15 minutos por IP

function ali_secreto(): string
{
    $clave = env('ALI_CLAVE_WEB', '') ?? '';
    return strlen($clave) >= 20 ? $clave : '';
}

function ali_usuario(): string
{
    return strtolower(trim(env('ALI_USUARIO', 'gus') ?? 'gus'));
}

function ali_archivo_cuenta(): string
{
    return APP_ROOT . '/.acceso.json';
}

/** La cuenta guardada: ['usuario' => …, 'hash' => …] o null si todavía no hay contraseña. */
function ali_cuenta(): ?array
{
    $raw = @file_get_contents(ali_archivo_cuenta());
    $c = $raw ? json_decode($raw, true) : null;
    return is_array($c) && !empty($c['hash']) && !empty($c['usuario']) ? $c : null;
}

/** ¿Se puede crear la contraseña ahora? Solo si no hay ninguna y la ventana del .env.local sigue abierta. */
function ali_alta_abierta(): bool
{
    return ali_cuenta() === null && time() < (int) (env('ALI_ALTA_HASTA', '0') ?? '0');
}

/** Huella corta de la contraseña vigente: si cambia, las cookies viejas dejan de servir. */
function ali_huella(): string
{
    $c = ali_cuenta();
    return $c ? substr(hash('sha256', $c['hash']), 0, 16) : '';
}

function ali_firma(string $exp, string $clave): string
{
    return hash_hmac('sha256', 'ali-acceso.' . $exp . '.' . ali_huella(), $clave);
}

/** Llamada de la oficina con su llave en la cabecera. */
function ali_acceso_oficina(): bool
{
    $llave = env('ALI_LLAVE_OFICINA', '') ?? '';
    $dada = (string) ($_SERVER['HTTP_X_ALI_LLAVE'] ?? '');
    return strlen($llave) >= 20 && $dada !== '' && hash_equals($llave, $dada);
}

/** Navegador que ya entró con usuario y contraseña (cookie firmada y vigente). */
function ali_acceso_navegador(): bool
{
    $clave = ali_secreto();
    $cookie = (string) ($_COOKIE[ALI_COOKIE] ?? '');
    if ($clave === '' || ali_cuenta() === null || !preg_match('/^(\d{10})\.([0-9a-f]{64})$/', $cookie, $m)) {
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
    $clave = ali_secreto();
    if ($clave === '') {
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

// --- Límite de intentos (archivo fuera de la carpeta pública) ------------------------------------

function ali_ip(): string
{
    return (string) ($_SERVER['REMOTE_ADDR'] ?? '?');
}

/** @return array<string, int[]> */
function ali_leer_intentos(): array
{
    $raw = @file_get_contents(APP_ROOT . '/.intentos.json');
    $d = $raw ? json_decode($raw, true) : [];
    return is_array($d) ? $d : [];
}

function ali_bloqueado(): bool
{
    $lista = array_filter(ali_leer_intentos()[ali_ip()] ?? [], fn ($t) => $t > time() - ALI_VENTANA);
    return count($lista) >= ALI_INTENTOS;
}

function ali_anotar_fallo(): void
{
    $todos = ali_leer_intentos();
    $ahora = time();
    foreach ($todos as $ip => $lista) {
        $todos[$ip] = array_values(array_filter($lista, fn ($t) => $t > $ahora - ALI_VENTANA));
        if (!$todos[$ip]) {
            unset($todos[$ip]);
        }
    }
    $todos[ali_ip()][] = $ahora;
    @file_put_contents(APP_ROOT . '/.intentos.json', json_encode($todos), LOCK_EX);
}

// --- Entrar y crear la contraseña ------------------------------------------------------------------

/** Usuario y contraseña. Devuelve el aviso para mostrar, o null si entró (ya con su cookie). */
function ali_entrar(string $usuario, string $clave): ?string
{
    if (ali_bloqueado()) {
        return 'Demasiados intentos. Prueba otra vez en 15 minutos.';
    }
    $c = ali_cuenta();
    $ok = $c !== null && hash_equals((string) $c['usuario'], strtolower(trim($usuario)))
        && password_verify($clave, (string) $c['hash']);
    if (!$ok) {
        ali_anotar_fallo();
        return 'Usuario o contraseña incorrectos.';
    }
    ali_dar_cookie();
    return null;
}

/** La primera vez: crea la contraseña. Devuelve el aviso para mostrar, o null si quedó creada (y ya entró). */
function ali_crear(string $usuario, string $clave, string $repetida): ?string
{
    if (!ali_alta_abierta()) {
        return 'Ya no se puede crear la contraseña desde aquí.';
    }
    if (strtolower(trim($usuario)) !== ali_usuario()) {
        ali_anotar_fallo();
        return 'Ese usuario no es el de Mi Cocina.';
    }
    if (mb_strlen($clave) < 8) {
        return 'La contraseña necesita al menos 8 caracteres.';
    }
    if ($clave !== $repetida) {
        return 'Las dos contraseñas no son iguales.';
    }
    $datos = json_encode([
        'usuario' => ali_usuario(),
        'hash' => password_hash($clave, PASSWORD_DEFAULT),
        'creada' => date('c'),
    ]);
    // «x»: si dos llegan a la vez, solo el primero la crea.
    $f = @fopen(ali_archivo_cuenta(), 'x');
    if ($f === false) {
        return 'Ya existe una contraseña. Entra con ella.';
    }
    fwrite($f, (string) $datos);
    fclose($f);
    @chmod(ali_archivo_cuenta(), 0600);
    ali_dar_cookie();
    return null;
}

/** Pantalla de entrada (o de crear la contraseña, la primera vez). */
function ali_pagina_acceso(string $aviso = ''): never
{
    http_response_code($aviso === '' ? 401 : 403);
    header('Content-Type: text/html; charset=utf-8');
    header('Cache-Control: no-store');
    header('X-Frame-Options: DENY');
    $aviso = htmlspecialchars($aviso, ENT_QUOTES, 'UTF-8');
    $usuario = htmlspecialchars(ali_usuario(), ENT_QUOTES, 'UTF-8');
    if (ali_alta_abierta()) {
        $titulo = 'Crea tu contraseña';
        $texto = 'Es la primera vez: elige la contraseña con la que vas a entrar a Mi Cocina (mínimo 8 caracteres).';
        $campos = <<<HTML
    <label>Usuario<input name="usuario" value="{$usuario}" autocomplete="username" required></label>
    <label>Contraseña<input name="clave" type="password" autocomplete="new-password" minlength="8" required></label>
    <label>Repite la contraseña<input name="clave2" type="password" autocomplete="new-password" minlength="8" required></label>
    <input type="hidden" name="accion" value="crear">
    <button type="submit">Crear y entrar</button>
HTML;
    } else {
        $titulo = 'Mi Cocina';
        $texto = 'Entra con tu usuario y contraseña. En este dispositivo queda recordado por un año.';
        $campos = <<<HTML
    <label>Usuario<input name="usuario" autocomplete="username" autocapitalize="none" required></label>
    <label>Contraseña<input name="clave" type="password" autocomplete="current-password" required></label>
    <input type="hidden" name="accion" value="entrar">
    <button type="submit">Entrar</button>
HTML;
    }
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
  main { width: 100%; max-width: 360px; padding: 24px 16px; box-sizing: border-box; }
  h1 { font-size: 1.3rem; margin: 0 0 8px; }
  p { margin: 0 0 16px; color: #555; }
  .aviso { color: #a33; min-height: 1.5em; }
  label { display: block; margin-bottom: 12px; font-size: .9rem; color: #444; }
  input { display: block; width: 100%; box-sizing: border-box; margin-top: 4px; padding: 12px; font-size: 1rem;
          border: 1px solid #bbb; border-radius: 8px; background: #fff; color: #222; }
  button { margin-top: 4px; width: 100%; padding: 12px; font-size: 1rem; border: 0; border-radius: 8px;
           background: #2f6b4f; color: #fff; }
</style>
</head>
<body>
<main>
  <h1>{$titulo}</h1>
  <p>{$texto}</p>
  <p class="aviso" role="alert">{$aviso}</p>
  <form method="post">
{$campos}
  </form>
</main>
</body>
</html>
HTML;
    exit;
}
