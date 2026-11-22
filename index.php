<?php
// N11: en producción jamás se muestran errores al usuario; solo se registran en el log de PHP/Apache.
ini_set('display_errors', '0');
ini_set('display_startup_errors', '0');
ini_set('log_errors', '1');
error_reporting(E_ALL);

/**
 * Front controller / enrutador MVC.
 * URL: index.php?c=controlador&a=accion
 */
require_once __DIR__ . '/config/config.php';   // zona horaria + constantes (antes de la sesión)

// Cabeceras defensivas básicas (offline-safe, no cargan nada externo).
header('X-Content-Type-Options: nosniff');
header('X-Frame-Options: SAMEORIGIN');
header('Referrer-Policy: same-origin');

// N11: sesión endurecida (modo estricto, solo cookies, SameSite=Strict, Secure si hay HTTPS).
$__https = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')
    || (($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https');
ini_set('session.use_strict_mode', '1');
ini_set('session.use_only_cookies', '1');
ini_set('session.gc_maxlifetime', (string)(SESION_INACTIVIDAD * 2));
session_name('ONIXSID');   // nombre propio: no se mezcla con otras apps de localhost
session_set_cookie_params(['lifetime' => 0, 'secure' => $__https, 'httponly' => true, 'samesite' => 'Strict']);
session_start();
unset($__https);

require_once __DIR__ . '/app/core/helpers.php';
require_once __DIR__ . '/app/core/Database.php';
require_once __DIR__ . '/app/core/Permisos.php';
require_once __DIR__ . '/app/core/Controller.php';

// Bloque 1.2: sin config/instalado.lock el sistema aún no está instalado -> instalador web.
// (install.php crea el candado solo; si ya existe una BD con usuarios, lo crea en el primer acceso.)
if (!is_file(__DIR__ . '/config/instalado.lock')) {
    header('Location: ' . base_url() . '/install.php');
    exit;
}

// Bloque 1.3: si la base viene de la versión 1.2 y aún no se migró, se avisa con claridad (en vez de errores sueltos).
if (empty($_SESSION['_esquema_ok'])) {
    try {
        if (Database::esquemaAlDia()) {
            $_SESSION['_esquema_ok'] = true;
        } else {
            http_response_code(503);
            header('Cache-Control: no-store');
            if (str_contains($_SERVER['HTTP_ACCEPT'] ?? '', 'json')) {
                header('Content-Type: application/json');
                echo json_encode(['ok' => false, 'msg' => 'Falta actualizar la base de datos (migración 1.3).']);
            } else {
                echo '<!DOCTYPE html><meta charset="UTF-8"><title>Actualización pendiente</title>'
                   . '<body style="font-family:sans-serif;max-width:640px;margin:3rem auto;padding:0 1rem">'
                   . '<h2>Falta actualizar la base de datos</h2>'
                   . '<p>Esta versión (1.3) necesita columnas y tablas nuevas. Quien administra el sistema debe ejecutar '
                   . '<code>php tools/migrar_1_3.php</code> (con la cuenta <code>root</code> de MariaDB) o seguir los pasos de '
                   . '<code>docs/INSTALACION.md</code> → «Actualizar desde la 1.2». Después recarga esta página.</p></body>';
            }
            exit;
        }
    } catch (Throwable $ex) {
        http_response_code(500);
        error_log($ex);
        echo 'Error interno del servidor. Si persiste, verifica que MariaDB esté activo y los datos de config/config.local.php.';
        exit;
    }
}

$c = strtolower($_GET['c'] ?? 'dashboard');
$a = $_GET['a'] ?? 'index';

$permitidos = ['auth', 'dashboard', 'usuario', 'producto', 'venta', 'compra', 'proveedor', 'categoria', 'caja', 'kardex', 'configuracion'];
if (!in_array($c, $permitidos, true) || !preg_match('/^[a-zA-Z]+$/', $a)) {
    http_response_code(404);
    exit('Página no encontrada');
}

$clase = ucfirst($c) . 'Controller';
require_once __DIR__ . "/app/controllers/$clase.php";

$ctrl = new $clase();
// Solo son rutas los métodos públicos declarados en el controlador concreto (no los heredados de Controller).
$rm = method_exists($ctrl, $a) ? new ReflectionMethod($ctrl, $a) : null;
if (!$rm || !$rm->isPublic() || $rm->isStatic() || $rm->getDeclaringClass()->getName() === 'Controller') {
    http_response_code(404);
    exit('Acción no encontrada');
}

try {
    $ctrl->$a();
} catch (Throwable $ex) {
    http_response_code(500);
    error_log($ex);
    if (($_SERVER['HTTP_ACCEPT'] ?? '') && str_contains($_SERVER['HTTP_ACCEPT'], 'json')) {
        header('Content-Type: application/json');
        echo json_encode(['ok' => false, 'msg' => 'Error interno del servidor.']);
    } else {
        echo 'Error interno del servidor. Si persiste, verifica que MariaDB esté activo y los datos de config/config.local.php.';
    }
}
