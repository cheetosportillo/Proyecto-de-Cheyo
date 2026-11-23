<?php
/**
 * Configuración base. Las credenciales NO viven aquí: se leen de config/config.local.php
 * (ignorado por git; lo genera el instalador o se copia de config.local.php.example).
 */

// ERR-07: toda la app trabaja en hora de El Salvador (UTC-6, sin horario de verano).
date_default_timezone_set('America/El_Salvador');

define('APP_NAME', 'Joyería Onix');
define('MONEDA', '$ ');

// ERR-13: minutos de inactividad antes de cerrar la sesión (30 min).
define('SESION_INACTIVIDAD', 30 * 60);

$__local = __DIR__ . '/config.local.php';
$__cfg = is_file($__local) ? (require $__local) : [];
if (!is_array($__cfg)) $__cfg = [];

// Prioridad: variable de entorno (la usa la suite tests/) > config.local.php > valor por defecto de XAMPP.
define('DB_HOST', getenv('ONIX_DB_HOST') ?: ($__cfg['host'] ?? '127.0.0.1'));
define('DB_PORT', getenv('ONIX_DB_PORT') ?: ($__cfg['port'] ?? '3306'));
define('DB_NAME', getenv('ONIX_DB_NAME') ?: ($__cfg['name'] ?? 'joyeria_onix'));
define('DB_USER', getenv('ONIX_DB_USER') ?: ($__cfg['user'] ?? 'root'));
define('DB_PASS', getenv('ONIX_DB_PASS') ?: ($__cfg['pass'] ?? ''));
define('DB_CHARSET', 'utf8mb4');

unset($__local, $__cfg);
