<?php
/**
 * Instalador web de Joyería Onix (Bloque 1.2).
 *
 *  Paso 1 · Base de datos : conecta, crea la base SOLO si falta y está permitido, carga schema.sql,
 *                           intenta los triggers y (opcional) crea el usuario restringido onix_app.
 *  Paso 2 · Cuenta Dueña  : crea la cuenta real de la Dueña y su código de recuperación (se muestra UNA vez).
 *
 * Seguridad: solo desde 127.0.0.1 o con el token de config/install.token (hosting). Al terminar crea
 * config/instalado.lock y este archivo queda inerte (redirige al login).
 */
ini_set('display_errors', '0');
ini_set('display_startup_errors', '0');
ini_set('log_errors', '1');
error_reporting(E_ALL);

require_once __DIR__ . '/config/config.php';
require_once __DIR__ . '/app/core/helpers.php';
require_once __DIR__ . '/app/core/Recuperacion.php';
require_once __DIR__ . '/app/core/Instalador.php';

header('X-Content-Type-Options: nosniff');
header('X-Frame-Options: DENY');
header('Referrer-Policy: same-origin');
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');   // la hoja con el código no debe quedar en caché
header('Pragma: no-cache');

$https = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')
    || (($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https');
ini_set('session.use_strict_mode', '1');
ini_set('session.use_only_cookies', '1');
session_name('ONIXINST');   // sesión propia del instalador (no se mezcla con la de la app)
session_set_cookie_params(['lifetime' => 0, 'secure' => $https, 'httponly' => true, 'samesite' => 'Strict']);
session_start();

function inst_csrf(): string
{
    if (empty($_SESSION['inst_csrf'])) $_SESSION['inst_csrf'] = bin2hex(random_bytes(32));
    return $_SESSION['inst_csrf'];
}
function inst_csrf_ok(): bool
{
    $t = (string)($_POST['csrf'] ?? '');
    $e = (string)($_SESSION['inst_csrf'] ?? '');
    return $e !== '' && $t !== '' && hash_equals($e, $t);
}
function inst_ir(string $destino): void
{
    header('Location: ' . $destino);
    exit;
}

// Ya instalado (o BD con usuarios): este archivo no hace nada más.
if (Instalador::estado() === 'listo') inst_ir(url('auth', 'login'));

$local      = Instalador::esLocal();
$autorizado = $local || !empty($_SESSION['inst_ok']);
$vista      = '';
$error      = '';
$datos      = [];
$res        = null;   // resultado del paso 1 cuando hay que mostrar el config manual
$hoja       = null;   // datos de la hoja final

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $accion = (string)($_POST['accion'] ?? '');

    if (!inst_csrf_ok()) {
        $error = 'La sesión expiró. Vuelve a intentarlo.';
    } elseif ($accion === 'token') {
        if ($local || Instalador::tokenValido((string)($_POST['token'] ?? ''))) {
            $_SESSION['inst_ok'] = true;
            $autorizado = true;
        } else {
            usleep(700000);   // frena adivinar el token
            $error = 'Token incorrecto.';
        }
    } elseif (!$autorizado) {
        $error = 'Acceso no autorizado.';
    } elseif ($accion === 'bd') {
        $datos = $_POST;
        try {
            $r = Instalador::instalarBd($_POST);
            $_SESSION['inst_flash'] = ['pasos' => $r['pasos'], 'avisos' => $r['avisos']];
            $_SESSION['inst_cred']  = $r['credenciales'];
            if ($r['config_escrita']) inst_ir(base_url() . '/install.php');   // PRG: al paso 2
            $res = $r;
            $vista = 'bd';
        } catch (RuntimeException $ex) {
            $error = $ex->getMessage();
        } catch (Throwable $ex) {
            error_log($ex);
            $error = 'Error inesperado durante la instalación. Revisa el log de errores de PHP.';
        }
    } elseif ($accion === 'cuenta') {
        $datos = $_POST;
        if (Instalador::estado() !== 'cuenta') {
            $error = 'Primero completa el paso de la base de datos.';
        } else {
            try {
                $d = Instalador::crearDuena($_POST);
                $cfg = Instalador::leerConfig();
                $hoja = [
                    'codigo' => $d['codigo'], 'usuario' => $d['usuario'], 'nombre' => $d['nombre'],
                    'host' => $cfg['host'] ?? '', 'port' => $cfg['port'] ?? '', 'bd' => $cfg['name'] ?? '',
                    'bd_usuario' => $cfg['user'] ?? '',
                    'bd_clave' => is_array($_SESSION['inst_cred'] ?? null) ? ($_SESSION['inst_cred']['clave'] ?? null) : null,
                    'avisos' => $_SESSION['inst_flash']['avisos'] ?? [],
                    'fecha' => date('d/m/Y H:i'),
                ];
                unset($_SESSION['inst_cred'], $_SESSION['inst_flash']);
                $vista = 'hoja';
            } catch (RuntimeException $ex) {
                $error = $ex->getMessage();
            } catch (Throwable $ex) {
                error_log($ex);
                $error = 'Error inesperado al crear la cuenta. Revisa el log de errores de PHP.';
            }
        }
    }
}

// Qué pantalla mostrar
if ($vista === '') {
    if (!$autorizado) {
        $vista = 'token';
    } else {
        $estado = Instalador::estado();
        if ($estado === 'listo') inst_ir(url('auth', 'login'));
        $vista = ($estado === 'cuenta' && ($_GET['paso'] ?? '') !== 'bd') ? 'cuenta' : 'bd';
    }
}

if ($vista === 'token') {
    $tokenListo = Instalador::token() !== null;   // lo crea en config/install.token si no existe
}

if ($vista === 'bd' && $datos === []) {
    $cfg = Instalador::leerConfig();
    $datos = [
        'entorno' => ($cfg['entorno'] ?? '') === 'hosting' ? 'hosting' : 'xampp',
        'host' => $cfg['host'] ?? '127.0.0.1', 'port' => $cfg['port'] ?? '3306',
        'name' => $cfg['name'] ?? 'joyeria_onix', 'user' => $cfg['user'] ?? 'root', 'pass' => '',
        'crear_bd' => '1', 'crear_usuario' => '1',
    ];
}

$flash = $_SESSION['inst_flash'] ?? ['pasos' => [], 'avisos' => []];
$csrf  = inst_csrf();
require __DIR__ . '/app/views/auth/setup.php';
