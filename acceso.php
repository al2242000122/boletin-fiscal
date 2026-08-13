<?php
/* ============================================================================
   acceso.php — sesión y verificación de contraseña.

   Este archivo NO imprime nada: solo define funciones. Se incluye desde
   index.php, boletin.php y salir.php.

   Por qué en PHP y no en JavaScript: en JavaScript el navegador ya tiene los
   archivos antes de pintar la pantalla de acceso, así que basta con ver el
   código fuente para saltársela. Aquí la comprobación ocurre en el servidor y
   la aplicación vive fuera del alcance del navegador (carpeta privado/), así
   que sin sesión no hay nada que descargar.
   ============================================================================ */

/* Hash de la contraseña. Se genera UNA vez con _configurar.php y se pega aquí.
   Nunca se guarda la contraseña en claro, ni aquí ni en ningún otro sitio. */
const ACCESO_HASH = '$2y$10$VWazyWlY5g7UVn3YrbVq0.9RN/upnp5PHwFbm4iuEHSB74gYg3kny';

const ACCESO_USUARIO      = 'Insuser@2026';
const ACCESO_MAX_INTENTOS = 90;
const ACCESO_ESPERA       = 300;   // segundos de bloqueo tras agotar intentos

function acceso_iniciar(): void
{
    if (session_status() === PHP_SESSION_ACTIVE) {
        return;
    }
    session_name('ISSSESION');
    session_set_cookie_params([
        'lifetime' => 0,
        'path'     => '/',
        'secure'   => !empty($_SERVER['HTTPS']),
        'httponly' => true,          // fuera del alcance de JavaScript
        'samesite' => 'Lax',
    ]);
    session_start();
}

function acceso_configurado(): bool
{
    return ACCESO_HASH !== 'PENDIENTE' && strlen(ACCESO_HASH) > 20;
}

function acceso_activo(): bool
{
    acceso_iniciar();
    return !empty($_SESSION['entrado']);
}

/* Segundos que faltan para poder volver a intentar. 0 = no está bloqueado. */
function acceso_bloqueado(): int
{
    acceso_iniciar();
    if ((int)($_SESSION['fallos'] ?? 0) < ACCESO_MAX_INTENTOS) {
        return 0;
    }
    $resta = ACCESO_ESPERA - (time() - (int)($_SESSION['ultimo_fallo'] ?? 0));
    return $resta > 0 ? $resta : 0;
}

function acceso_token(): string
{
    acceso_iniciar();
    if (empty($_SESSION['token'])) {
        $_SESSION['token'] = bin2hex(random_bytes(16));
    }
    return $_SESSION['token'];
}

function acceso_entrar(string $usuario, string $clave, string $token): bool
{
    acceso_iniciar();

    if (!acceso_configurado())                        return false;
    if (!hash_equals((string)($_SESSION['token'] ?? ''), $token)) return false;
    if (acceso_bloqueado() > 0)                       return false;

    $ok = hash_equals(ACCESO_USUARIO, $usuario)
       && password_verify($clave, ACCESO_HASH);

    if ($ok) {
        session_regenerate_id(true);   // evita fijación de sesión
        $_SESSION['entrado'] = true;
        $_SESSION['fallos']  = 0;
        $_SESSION['token']   = bin2hex(random_bytes(16));
        return true;
    }

    $_SESSION['fallos']       = (int)($_SESSION['fallos'] ?? 0) + 1;
    $_SESSION['ultimo_fallo'] = time();
    return false;
}

function acceso_salir(): void
{
    acceso_iniciar();
    $_SESSION = [];
    if (ini_get('session.use_cookies')) {
        $p = session_get_cookie_params();
        setcookie(session_name(), '', time() - 42000,
                  $p['path'], $p['domain'], $p['secure'], $p['httponly']);
    }
    session_destroy();
}

/* Dirección del portal (la pantalla de acceso), se llame desde donde se llame.
   No basta con 'index.php': desde listas/ eso apunta a listas/index.php, que a
   su vez exige sesión y vuelve a redirigir — el navegador acaba en
   ERR_TOO_MANY_REDIRECTS en lugar de enseñar el acceso. Se cuentan los niveles
   que hay desde el script en curso hasta la carpeta donde vive este archivo,
   que es la raíz del sitio. */
function acceso_url_portal(): string
{
    $raiz   = rtrim(str_replace('\\', '/', __DIR__), '/');
    $actual = rtrim(str_replace('\\', '/', dirname((string)($_SERVER['SCRIPT_FILENAME'] ?? ''))), '/');

    if ($actual !== '' && $actual !== $raiz && strpos($actual, $raiz . '/') === 0) {
        $resto   = substr($actual, strlen($raiz) + 1);
        $niveles = substr_count($resto, '/') + 1;
        return str_repeat('../', $niveles) . 'index.php';
    }
    return 'index.php';
}

/* Puerta para las páginas que no deben verse sin sesión. */
function acceso_exigir(): void
{
    if (!acceso_activo()) {
        header('Location: ' . acceso_url_portal());
        exit;
    }
}

function esc(?string $s): string
{
    return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8');
}
