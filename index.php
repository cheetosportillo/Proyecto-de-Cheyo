<?php
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);

/**
 * Front controller / enrutador MVC.
 * URL: index.php?c=controlador&a=accion
 */
session_set_cookie_params(['httponly' => true, 'samesite' => 'Lax']);
session_start();

require_once __DIR__ . '/config/config.php';
require_once __DIR__ . '/app/core/helpers.php';
require_once __DIR__ . '/app/core/Database.php';
require_once __DIR__ . '/app/core/Controller.php';

$c = strtolower($_GET['c'] ?? 'dashboard');
$a = $_GET['a'] ?? 'index';

$permitidos = ['auth', 'dashboard', 'usuario', 'producto', 'venta', 'compra', 'proveedor'];
if (!in_array($c, $permitidos, true) || !preg_match('/^[a-zA-Z]+$/', $a)) {
    http_response_code(404);
    exit('Página no encontrada');
}

$clase = ucfirst($c) . 'Controller';
require_once __DIR__ . "/app/controllers/$clase.php";

$ctrl = new $clase();
if (!method_exists($ctrl, $a) || !(new ReflectionMethod($ctrl, $a))->isPublic()) {
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
        echo 'Error interno del servidor. Revisa la conexión a la base de datos en config/config.php';
    }
}
