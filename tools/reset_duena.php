<?php
/**
 * RECUPERACIÓN DE EMERGENCIA (solo consola). Úsalo únicamente si la Dueña perdió la contraseña, la llave maestra y
 * el acceso al correo. Quien lo ejecuta ya tiene acceso al servidor: por eso NO funciona desde el navegador.
 *
 *   php tools/reset_duena.php                      (si hay una sola Dueña activa la toma sola)
 *   php tools/reset_duena.php --usuario=duena      (elige la cuenta)
 *   php tools/reset_duena.php --usuario=duena --si (sin pregunta de confirmación)
 *
 * Qué hace: activa la cuenta, le pone una CLAVE TEMPORAL (la obliga a cambiarla al entrar), rota su llave maestra
 * (la nueva se imprime una sola vez), borra códigos pendientes y contadores de intentos, y lo registra en bitácora.
 * Usa la cuenta de la app de config/config.local.php (le basta UPDATE en usuarios e INSERT en bitácora).
 * En XAMPP/Windows:  C:\xampp\php\php.exe tools\reset_duena.php
 */
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }

$raiz = dirname(__DIR__);
require_once $raiz . '/config/config.php';
require_once $raiz . '/app/core/Database.php';
require_once $raiz . '/app/core/Recuperacion.php';
require_once $raiz . '/app/models/Usuario.php';
require_once $raiz . '/app/models/Bitacora.php';

$op = [];
foreach (array_slice($argv, 1) as $a) {
    if (preg_match('/^--(usuario)=(.*)$/s', $a, $m)) $op[$m[1]] = $m[2];
    elseif ($a === '--si') $op['si'] = true;
}

try {
    $pdo = Database::get();
    $duenas = $pdo->query("SELECT id_usuario, nombre, usuario, estado FROM usuarios WHERE rol = 'duena' ORDER BY id_usuario")->fetchAll();
    if (!$duenas) throw new RuntimeException('No existe ninguna cuenta de Dueña en la base «' . DB_NAME . '». Reinstala o crea una desde la base de datos.');

    $elegida = null;
    if (!empty($op['usuario'])) {
        foreach ($duenas as $d) if (strcasecmp($d['usuario'], $op['usuario']) === 0) $elegida = $d;
        if (!$elegida) throw new RuntimeException('«' . $op['usuario'] . '» no es una Dueña. Dueñas: ' . implode(', ', array_column($duenas, 'usuario')) . '.');
    } else {
        $activas = array_values(array_filter($duenas, fn($d) => (int)$d['estado'] === 1));
        $candidatas = $activas ?: $duenas;
        if (count($candidatas) !== 1) throw new RuntimeException('Hay varias Dueñas (' . implode(', ', array_column($duenas, 'usuario')) . '). Indica cuál con --usuario=NOMBRE.');
        $elegida = $candidatas[0];
    }

    echo "\nBase de datos: " . DB_NAME . ' en ' . DB_HOST . ':' . DB_PORT . "\n";
    echo 'Cuenta a recuperar: ' . $elegida['nombre'] . ' (' . $elegida['usuario'] . ")\n";
    echo "Se activará la cuenta, se anulará su contraseña y su llave maestra actuales y se generarán nuevas.\n";
    if (empty($op['si'])) {
        if (!stream_isatty(STDIN)) throw new RuntimeException('Sin terminal interactiva: agrega --si para confirmar.');
        echo "Escribe el nombre de usuario para confirmar: ";
        $r = trim((string)fgets(STDIN));
        if (strcasecmp($r, $elegida['usuario']) !== 0) throw new RuntimeException('Confirmación incorrecta: no se cambió nada.');
    }

    $id = (int)$elegida['id_usuario'];
    $clave = Usuario::claveTemporal();
    [$llave, $hashLlave] = Recuperacion::generar();
    $quien = (string)(getenv('USERNAME') ?: getenv('USER') ?: get_current_user());
    Database::transaccion(function () use ($pdo, $id, $clave, $hashLlave, $quien, $elegida) {
        $pdo->prepare('UPDATE usuarios SET estado = 1, password = ?, debe_cambiar_password = 1, codigo_recuperacion_hash = ?,
                              reset_token_hash = NULL, reset_token_expira = NULL WHERE id_usuario = ?')
            ->execute([password_hash($clave, PASSWORD_DEFAULT), $hashLlave, $id]);
        $pdo->prepare("UPDATE recuperacion_intentos SET intentos = 0 WHERE clave LIKE ? OR clave LIKE ? OR clave LIKE ?")
            ->execute(['llave:' . $elegida['usuario'], 'codigo:' . $elegida['usuario'], 'cambio:' . $id]);
        Bitacora::registrar($id, 'RESET_DUENA_CONSOLA', 'Recuperación de emergencia por consola (usuario del sistema: ' . $quien . ').');
    });

    echo "\n✔ Listo. Anota estos datos (no se vuelven a mostrar):\n\n";
    echo '   Usuario:           ' . $elegida['usuario'] . "\n";
    echo '   Clave temporal:    ' . $clave . "   (te pedirá cambiarla al entrar)\n";
    echo '   NUEVA llave maestra: ' . $llave . "\n\n";
    echo "La llave anterior ya no sirve. Limpia la pantalla de la consola cuando termines.\n";
} catch (Throwable $e) {
    fwrite(STDERR, "✘ " . $e->getMessage() . "\n");
    exit(1);
}
