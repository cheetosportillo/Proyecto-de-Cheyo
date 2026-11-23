<?php
/**
 * Migración de una base de la versión 1.2 a la 1.3 (solo CLI). Idempotente: se puede repetir.
 *
 *   php tools/migrar_1_3.php --user=root --pass= [--host=127.0.0.1] [--port=3306] [--db=joyeria_onix]
 *
 * Qué hace: carga schema.sql (crea las tablas nuevas y las semillas; no toca lo existente), agrega las 3 columnas
 * nuevas de `usuarios` si faltan (schema.sql no puede: solo CREATE TABLE IF NOT EXISTS) y, si existe el usuario
 * restringido «onix_app», le otorga permisos sobre las tablas nuevas.
 *
 * Usa una cuenta con ALTER/GRANT (root en XAMPP), NO la de la app. Sin argumentos toma host/puerto/base de
 * config/config.local.php y las variables ONIX_DB_USER / ONIX_DB_PASS (si faltan, root sin clave).
 * En Windows/XAMPP:  C:\xampp\php\php.exe tools\migrar_1_3.php --user=root --pass=
 */
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }

$raiz = dirname(__DIR__);
require_once $raiz . '/app/core/Recuperacion.php';
require_once $raiz . '/app/core/Instalador.php';

$op = [];
foreach (array_slice($argv, 1) as $a) {
    if (preg_match('/^--(host|port|user|pass|db)=(.*)$/s', $a, $m)) $op[$m[1]] = $m[2];
}
$cfg = Instalador::leerConfig() ?? [];
$c = [
    'host' => $op['host'] ?? getenv('ONIX_DB_HOST') ?: ($cfg['host'] ?? '127.0.0.1'),
    'port' => $op['port'] ?? getenv('ONIX_DB_PORT') ?: ($cfg['port'] ?? '3306'),
    'name' => $op['db']   ?? getenv('ONIX_DB_NAME') ?: ($cfg['name'] ?? 'joyeria_onix'),
    'user' => $op['user'] ?? getenv('ONIX_DB_USER') ?: 'root',
    'pass' => $op['pass'] ?? getenv('ONIX_DB_PASS') ?: '',
];
try {
    $c = Instalador::validarConexion($c);
    $pdo = Instalador::conectar($c, true);
    echo "Conectado a «{$c['name']}» en {$c['host']}:{$c['port']} como {$c['user']}.\n";
    $n = Instalador::ejecutarArchivo($pdo, $raiz . '/schema.sql');
    echo "✔ schema.sql aplicado ($n sentencias; tablas nuevas y semillas).\n";
    $hecho = Instalador::migrar($pdo);
    foreach ($hecho as $m) echo "✔ $m\n";
    if (!$hecho) echo "✔ Las columnas de usuarios ya estaban al día.\n";
    $g = Instalador::reaplicarPrivilegios($pdo, $c['name']);
    echo $g ? "✔ $g\n" : "• Permisos de onix_app: sin cambios (no existe ese usuario o esta cuenta no puede otorgarlos).\n";
    echo "\nListo: base en versión " . Instalador::VERSION_ESQUEMA . ".\n";
} catch (Throwable $e) {
    fwrite(STDERR, "✘ " . $e->getMessage() . "\n");
    exit(1);
}
