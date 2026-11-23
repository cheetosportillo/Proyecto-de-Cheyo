<?php
require __DIR__ . '/bootstrap.php';
require_once "$raiz/app/models/Bitacora.php";
require_once "$raiz/app/core/Respaldo.php";
echo "\n▶ Bloque 1.4 · Respaldos · Visor de bitácora · permisos reservados\n";
limpiar_bd();
$pdo = Database::get();

/* ---------- Permisos reservados (sin filas en BD) ---------- */
prueba('respaldos.gestionar y bitacora.ver: siempre la Dueña, nunca un cajero (ni aunque la sesión lo liste)', function () {
    $bak = $_SESSION['usuario'];
    $_SESSION['usuario'] = ['id' => 1, 'rol' => 'duena'];
    afirma(Permisos::tiene('respaldos.gestionar') && Permisos::tiene('bitacora.ver'));
    $_SESSION['usuario'] = ['id' => 2, 'rol' => 'cajero', 'permisos' => ['respaldos.gestionar', 'bitacora.ver', 'ventas.cobrar']];
    afirma(!Permisos::tiene('respaldos.gestionar') && !Permisos::tiene('bitacora.ver') && Permisos::tiene('ventas.cobrar'));
    $_SESSION['usuario'] = $bak;
});

/* ---------- Visor de bitácora ---------- */
prueba('Bitacora::filtros: ignora lo inválido, ordena el rango y valida usuario/acción', function () {
    $f = Bitacora::filtros(['desde' => '2026-02-31', 'hasta' => 'x', 'usuario' => '1; DROP', 'accion' => "A' OR 1=1"]);
    igual('', $f['desde']); igual('', $f['hasta']); igual('', $f['usuario']); igual('', $f['accion']);
    $f = Bitacora::filtros(['desde' => '2026-03-10', 'hasta' => '2026-03-01', 'usuario' => 'sistema', 'accion' => 'PASSWORD_CAMBIADA']);
    igual('2026-03-01', $f['desde']); igual('2026-03-10', $f['hasta']); igual('sistema', $f['usuario']); igual('PASSWORD_CAMBIADA', $f['accion']);
    igual('2', Bitacora::filtros(['usuario' => '2'])['usuario']);
    $f = Bitacora::filtros(['usuario' => ['x'], 'accion' => ['y']]);   // arreglos en la URL (?usuario[]=)
    igual('', $f['usuario']); igual('', $f['accion']);
});
prueba('Bitacora: filtros por fecha, usuario y acción; paginación de 50; recorrer() sin cargar todo', function () use ($pdo) {
    $ins = $pdo->prepare('INSERT INTO bitacora (fecha, id_usuario, accion, detalle) VALUES (?,?,?,?)');
    $ins->execute(['2020-01-14 23:59:59', 1, 'VIEJA', 'antes']);
    $ins->execute(['2020-01-15 00:00:00', 1, 'VIEJA', 'inicio del día']);
    $ins->execute(['2020-01-15 23:59:59', 2, 'VIEJA', 'fin del día']);
    $ins->execute(['2020-01-16 00:00:00', null, 'SISTEMA_X', 'sin usuario']);
    for ($i = 1; $i <= 120; $i++) $ins->execute(['2021-05-05 10:00:00', 1, 'MASIVA', "fila $i"]);

    $f = fn(array $x) => Bitacora::filtros($x);
    igual(124, Bitacora::contar($f([])));
    igual(2, Bitacora::contar($f(['desde' => '2020-01-15', 'hasta' => '2020-01-15', 'accion' => 'VIEJA'])), 'día completo, ambos extremos');
    igual(1, Bitacora::contar($f(['usuario' => 'sistema'])));
    igual(1, Bitacora::contar($f(['usuario' => '2'])));
    igual(120, Bitacora::contar($f(['accion' => 'MASIVA'])));
    igual(0, Bitacora::contar($f(['accion' => 'MASIVA', 'usuario' => '2'])));

    $m = $f(['accion' => 'MASIVA']);
    igual(50, count(Bitacora::buscar($m, 1))); igual(50, count(Bitacora::buscar($m, 2))); igual(20, count(Bitacora::buscar($m, 3)));
    $p1 = Bitacora::buscar($m, 1); $p3 = Bitacora::buscar($m, 3);
    afirma((int)$p1[0]['id_bitacora'] > (int)$p1[49]['id_bitacora'], 'más recientes primero');
    afirma($p1[0]['u_nombre'] === 'Dueña', 'une el nombre del usuario');
    $sis = Bitacora::buscar($f(['usuario' => 'sistema']), 1);
    afirma($sis[0]['id_usuario'] === null && $sis[0]['u_nombre'] === null, 'fila sin usuario');

    $ids = [];
    $n = Bitacora::recorrer($m, 120, function ($r) use (&$ids) { $ids[] = (int)$r['id_bitacora']; });
    igual(120, $n); igual(120, count(array_unique($ids)));
    $d = $ids; rsort($d); afirma($d === $ids, 'recorrer debe ir de más reciente a más antiguo');
    igual(30, Bitacora::recorrer($m, 30, function ($r) {}), 'respeta el máximo');
    afirma(in_array('MASIVA', Bitacora::acciones(), true) && count(Bitacora::usuarios()) >= 2);
});
prueba('exportación CSV: celdas con fórmulas quedan neutralizadas y el CSV se puede leer de vuelta', function () {
    $fp = fopen('php://memory', 'w+');
    fputcsv($fp, array_map('csv_celda', ['1', '2026-01-01', '=HYPERLINK("http://x")', '+cmd|calc', '@SUM(A1)', "-1+2", 'normal, con "comas"']), ',', '"', '');
    rewind($fp);
    $fila = fgetcsv($fp, 0, ',', '"', '');
    igual("'=HYPERLINK(\"http://x\")", $fila[2]); igual("'+cmd|calc", $fila[3]); igual("'@SUM(A1)", $fila[4]); igual("'-1+2", $fila[5]);
    igual('normal, con "comas"', $fila[6]);
});
prueba('la bitácora no tiene API de edición/borrado en el modelo', function () {
    foreach (get_class_methods('Bitacora') as $m) afirma(!preg_match('/borrar|eliminar|editar|actualizar|delete|update|truncate/i', $m), "método sospechoso $m");
});

/* ---------- Respaldos ---------- */
$dirBk = sys_get_temp_dir() . '/onix_bk_' . bin2hex(random_bytes(4));
putenv('ONIX_BACKUP_DIR=' . $dirBk);
$rarezas = "Ñandú Åkesson ą; 'comillas' \"dobles\" \\ barra\nsalto de línea;\nfin;";

prueba('Respaldo::generar: crea backups protegido, archivo completo con todas las tablas y sin columnas generadas', function () use ($pdo, $dirBk, $rarezas) {
    $pdo->prepare("INSERT INTO usuarios (nombre, usuario, password, rol, email) VALUES (?,?,?,?,?)")->execute([$rarezas, 't14_raro', 'x', 'cajero', 'ñ@x.com']);
    [$conn, $cuenta] = Respaldo::conexion();
    afirma($conn instanceof PDO && $cuenta !== '');
    $r = Respaldo::generar($conn);
    afirma(preg_match(Respaldo::PATRON, $r['archivo']) === 1, 'nombre ' . $r['archivo']);
    afirma($r['tablas'] === 19 && $r['filas'] > 0 && $r['bytes'] > 1000, 'tablas=' . $r['tablas']);
    afirma(is_file($dirBk . '/.htaccess') && str_contains(file_get_contents($dirBk . '/.htaccess'), 'Require all denied'), '.htaccess');
    afirma(!is_file($dirBk . '/' . $r['archivo'] . '.part'), 'quedó un .part');
    $sql = (string)file_get_contents($dirBk . '/' . $r['archivo']);
    afirma(str_contains($sql, Respaldo::MARCA_FIN) && Respaldo::esCompleto($dirBk . '/' . $r['archivo']));
    foreach ($pdo->query("SHOW TABLES")->fetchAll(PDO::FETCH_COLUMN) as $t) afirma(str_contains($sql, "CREATE TABLE `$t`"), "falta $t");
    afirma(!preg_match('/INSERT INTO `cajas` \([^)]*abierta_unica/', $sql), 'la columna generada no debe ir en el INSERT');
    afirma(!str_contains($sql, 'INSERT INTO `recuperacion_intentos`'), 'datos efímeros no se respaldan');
    afirma(!str_contains($sql, "\r"), 'cada valor debe ir escapado en una línea');
});
prueba('Respaldo::listar/ruta: lista, detecta incompletos y no admite nombres con rutas', function () use ($dirBk) {
    file_put_contents($dirBk . '/respaldo_x_20200101_000000_abcdef.sql', "SET NAMES utf8mb4;\n");   // sin marca de fin
    $l = Respaldo::listar();
    afirma(count($l) === 2, 'esperaba 2 respaldos, hay ' . count($l));
    $inc = array_values(array_filter($l, fn($a) => $a['archivo'] === 'respaldo_x_20200101_000000_abcdef.sql'));
    afirma($inc && $inc[0]['completo'] === false, 'incompleto no detectado');
    afirma(count(array_filter($l, fn($a) => $a['completo'])) === 1);
    afirma(Respaldo::ruta($l[0]['archivo']) !== null);
    foreach (['../config/config.local.php', '..\\x.sql', 'respaldo_x.sql', 'respaldo_a_20200101_000000_abcdef.sql', "respaldo_x_20200101_000000_abcdef.sql\0.php", '.htaccess', ''] as $mal)
        afirma(Respaldo::ruta($mal) === null, 'aceptó «' . $mal . '»');
});
prueba('Respaldo: dos respaldos a la vez no se pisan (bloqueo)', function () use ($pdo, $dirBk) {
    $lock = fopen($dirBk . '/.lock', 'c');
    afirma(flock($lock, LOCK_EX | LOCK_NB));
    try { lanza(fn() => Respaldo::generar($pdo), 'en curso'); } finally { flock($lock, LOCK_UN); fclose($lock); }
});
prueba('RESTAURAR: el respaldo reconstruye una base idéntica (conteos y texto con ; comillas y saltos de línea)', function () use ($pdo, $dirBk, $rarezas) {
    $archivo = null;
    foreach (Respaldo::listar() as $a) if ($a['completo']) { $archivo = $dirBk . '/' . $a['archivo']; break; }
    afirma($archivo !== null);
    $scratch = 'joyeria_onix_rt_test';
    $root = new PDO('mysql:host=' . DB_HOST . ';port=' . DB_PORT . ';charset=utf8mb4', DB_USER, DB_PASS, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
    try { $root->exec("DROP DATABASE IF EXISTS `$scratch`"); $root->exec("CREATE DATABASE `$scratch` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci"); }
    catch (PDOException $e) { echo "      (omitida: esta cuenta no puede crear bases de prueba: " . $e->getCode() . ")\n"; return; }
    try {
        $root->exec("USE `$scratch`");
        Instalador::ejecutarArchivo($root, $archivo);
        foreach ($pdo->query('SHOW TABLES')->fetchAll(PDO::FETCH_COLUMN) as $t) {
            $a = (int)$pdo->query("SELECT COUNT(*) FROM `$t`")->fetchColumn();
            $b = (int)$root->query("SELECT COUNT(*) FROM `$scratch`.`$t`")->fetchColumn();
            if ($t === 'recuperacion_intentos') { igual(0, $b, "$t sin datos"); continue; }
            igual($a, $b, "filas de $t");
        }
        $f = $root->query("SELECT nombre, email FROM `$scratch`.usuarios WHERE usuario = 't14_raro'")->fetch(PDO::FETCH_ASSOC);
        igual($rarezas, $f['nombre'], 'texto con rarezas'); igual('ñ@x.com', $f['email']);
        $ddl = $root->query("SHOW CREATE TABLE `$scratch`.cajas")->fetch(PDO::FETCH_NUM)[1];
        afirma(stripos($ddl, 'GENERATED') !== false, 'cajas.abierta_unica debe seguir siendo columna generada');
    } finally {
        $root->exec("DROP DATABASE IF EXISTS `$scratch`");
    }
});

$pdo->exec("DELETE FROM usuarios WHERE usuario = 't14_raro'");
foreach (scandir($dirBk) ?: [] as $f) if (is_file($dirBk . '/' . $f)) @unlink($dirBk . '/' . $f);
@rmdir($dirBk);
putenv('ONIX_BACKUP_DIR');
exit(resumen());
