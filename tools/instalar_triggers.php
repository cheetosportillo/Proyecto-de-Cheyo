<?php
/**
 * (Re)instala los 5 triggers de schema_triggers.sql (inmutabilidad de kardex/bitácora, etc.). Úsalo DESPUÉS de restaurar un
 * respaldo: el respaldo recrea las tablas y los triggers no viajan en él.
 *
 *   php tools/instalar_triggers.php --user=root --pass= [--host=127.0.0.1] [--port=3306] [--db=joyeria_onix]
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
    [$creados, $errores] = Instalador::instalarTriggers($pdo);
    echo "Triggers creados en «{$c['name']}»: $creados\n";
    if ($errores) { fwrite(STDERR, '✘ No se pudieron crear: ' . implode(', ', $errores) . "\n"); exit(1); }
    echo "✔ Listo.\n";
} catch (Throwable $e) {
    fwrite(STDERR, '✘ ' . $e->getMessage() . "\n");
    exit(1);
}
