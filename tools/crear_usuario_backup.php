<?php
/**
 * Crea (o recrea) el usuario de SOLO LECTURA «onix_backup» para los respaldos y guarda sus credenciales en
 * config/config.local.php. Opcional: en hosting compartido no hace falta (y suele no estar permitido); los respaldos
 * siguen funcionando con la cuenta de la app.
 *
 *   php tools/crear_usuario_backup.php --user=root --pass= [--host=127.0.0.1] [--port=3306] [--db=joyeria_onix]
 *
 * Con la cuenta root de MariaDB local (XAMPP). Si el servidor no permite crear usuarios, avisa y termina sin error.
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
    $r = Instalador::prepararUsuarioBackup($pdo, $c);
    if (!$r['config']) {
        echo '• ' . $r['mensaje'] . "\n";
        exit(0);
    }
    if (!Instalador::guardarCredencialesBackup($r['config']['backup_user'], $r['config']['backup_pass'])) {
        Instalador::eliminarUsuarioBackup($pdo);
        throw new RuntimeException('No se pudo escribir config/config.local.php (¿existe y tiene permisos?). Se revirtió el usuario.');
    }
    echo '✔ ' . $r['mensaje'] . "\n✔ Credenciales guardadas en config/config.local.php (ignorado por git y bloqueado desde el navegador).\n";
} catch (Throwable $e) {
    fwrite(STDERR, '✘ ' . $e->getMessage() . "\n");
    exit(1);
}
