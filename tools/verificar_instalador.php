<?php
/**
 * Verificación de los Bloques 1.2, 1.3 y 1.4 (solo CLI).
 *
 *   php tools/verificar_instalador.php              comprobaciones sin base de datos
 *   php tools/verificar_instalador.php --bd         además RECREA joyeria_onix_test desde schema.sql y revisa la estructura
 *   php tools/verificar_instalador.php --bd --triggers   igual, pero instala también schema_triggers.sql y los prueba
 *
 * Variables de entorno (las mismas que tests/): ONIX_DB_HOST, ONIX_DB_PORT, ONIX_DB_USER, ONIX_DB_PASS,
 * ONIX_DB_NAME (debe terminar en _test: se hace DROP DATABASE de ella).
 *
 * Al terminar con --bd, la base queda LIMPIA y lista para:   php tests/run.php
 * En XAMPP/Windows:  C:\xampp\php\php.exe tools\verificar_instalador.php --bd
 */
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }

$raiz = dirname(__DIR__);
require_once $raiz . '/app/core/Recuperacion.php';
require_once $raiz . '/app/core/Instalador.php';

$ok = 0; $fallos = 0;
function prueba(string $nombre, callable $fn): void
{
    global $ok, $fallos;
    try { $fn(); echo "  \033[32m✔\033[0m $nombre\n"; $ok++; }
    catch (Throwable $e) { echo "  \033[31m✘ $nombre\033[0m\n      " . $e->getMessage() . "\n"; $fallos++; }
}
function afirma($c, string $m = 'Afirmación falsa'): void { if (!$c) throw new RuntimeException($m); }
function igual($esperado, $real, string $m = ''): void
{
    if ((string)$esperado !== (string)$real) throw new RuntimeException(trim("$m · esperado [$esperado] pero fue [$real]"));
}
function lanza(callable $fn): void
{
    try { $fn(); } catch (Throwable $e) { return; }
    throw new RuntimeException('Debía lanzar una excepción');
}

/** [tabla => [columna => definición normalizada]] de un volcado/esquema. */
function columnas_sql(string $sql): array
{
    $r = [];
    preg_match_all('/CREATE TABLE (?:IF NOT EXISTS )?`(\w+)` \((.*?)\)\s*ENGINE=/s', $sql, $m, PREG_SET_ORDER);
    foreach ($m as $t) {
        foreach (preg_split('/\R/', $t[2]) as $linea) {
            if (preg_match('/^\s+`(\w+)`\s+(.*?),?\s*$/', $linea, $c)) {
                $def = trim(str_ireplace(' AUTO_INCREMENT', '', $c[2]));
                $r[$t[1]][$c[1]] = preg_replace('/\s+/', ' ', $def);
            }
        }
    }
    return $r;
}

$esquema = (string)file_get_contents($raiz . '/schema.sql');
$triggersSql = (string)file_get_contents($raiz . '/schema_triggers.sql');

echo "\n▶ schema.sql\n";
$TABLAS = ['bitacora','caja_movimientos','cajas','categorias','compras','configuracion','detalle_compra','detalle_venta','kardex',
           'permisos','productos','proveedores','recuperacion_intentos','rol_permiso','roles','schema_migrations','secuencias','usuarios','ventas'];

prueba('19 tablas, todas con CREATE TABLE IF NOT EXISTS y 0 ALTER TABLE', function () use ($esquema, $TABLAS) {
    $s = Instalador::dividirSql($esquema);
    $crear = array_values(array_filter($s, fn($x) => stripos($x, 'CREATE TABLE IF NOT EXISTS') === 0));
    igual(19, count($crear), 'CREATE TABLE');
    afirma(preg_match('/^\s*ALTER TABLE/mi', $esquema) === 0, 'hay ALTER TABLE');
    $nombres = [];
    foreach ($crear as $x) { preg_match('/`(\w+)`/', $x, $m); $nombres[] = $m[1]; }
    sort($nombres);
    igual(implode(',', $TABLAS), implode(',', $nombres), 'tablas');
});
prueba('divisor SQL: cada sentencia termina bien (27 = SET + 19 tablas + 7 semillas)', function () use ($esquema) {
    $s = Instalador::dividirSql($esquema);
    igual(27, count($s), 'sentencias');
    foreach ($s as $x) afirma(substr($x, -1) !== ';', 'quedó un ; al final');
});
prueba('schema_triggers.sql: 5 triggers (10 sentencias con sus DROP) y el divisor respeta DELIMITER', function () use ($triggersSql) {
    $s = Instalador::dividirSql($triggersSql);
    igual(10, count($s), 'sentencias');
    $crear = array_filter($s, fn($x) => stripos($x, 'CREATE TRIGGER') === 0);
    igual(5, count($crear), 'CREATE TRIGGER');
    foreach ($crear as $x) afirma(str_contains($x, 'SIGNAL') && substr(trim($x), -3) === 'END', 'trigger mal cortado');
});

$viejo = $raiz . '/joyeria_onix.sql';
if (is_file($viejo)) {
    echo "\n▶ Compatibilidad con el volcado de la Parte 2 (joyeria_onix.sql)\n";
    $v = columnas_sql((string)file_get_contents($viejo));
    $n = columnas_sql($esquema);
    prueba('toda tabla y columna del volcado existe en schema.sql con la MISMA definición', function () use ($v, $n) {
        $mal = [];
        foreach ($v as $t => $cols) foreach ($cols as $c => $def) {
            if (!isset($n[$t][$c])) $mal[] = "$t.$c falta";
            elseif ($n[$t][$c] !== $def) $mal[] = "$t.$c: [$def] != [{$n[$t][$c]}]";
        }
        afirma(!$mal, implode("\n      ", $mal));
    });
    prueba('en las tablas ya existentes lo ÚNICO nuevo son las 9 columnas previstas (1.2: 6, 1.3: email y reset_token_*)', function () use ($v, $n) {
        $extra = [];
        foreach ($n as $t => $cols) {
            if (!isset($v[$t])) continue;   // tablas nuevas: se revisan aparte
            foreach ($cols as $c => $def) if (!isset($v[$t][$c])) $extra[] = "$t.$c";
        }
        sort($extra);
        $esperado = ['caja_movimientos.uuid_operacion', 'categorias.updated_at', 'productos.updated_at',
                     'proveedores.updated_at', 'usuarios.codigo_recuperacion_hash', 'usuarios.debe_cambiar_password',
                     'usuarios.email', 'usuarios.reset_token_expira', 'usuarios.reset_token_hash'];
        sort($esperado);
        igual(implode(',', $esperado), implode(',', $extra), 'columnas nuevas');
    });
    prueba('tablas nuevas respecto a la Parte 2: las 5 del Bloque 1.3 + schema_migrations/secuencias si el volcado no las trae', function () use ($v, $n) {
        $nuevas = array_values(array_diff(array_keys($n), array_keys($v)));
        sort($nuevas);
        foreach (['configuracion', 'permisos', 'recuperacion_intentos', 'rol_permiso', 'roles'] as $t) afirma(in_array($t, $nuevas, true), "falta $t");
    });
}

echo "\n▶ Código de recuperación\n";
prueba('formato ONIX-XXXX-XXXX-XXXX con alfabeto sin ambiguos, hash válido y no contiene el código', function () {
    [$cod, $hash] = Recuperacion::generar();
    afirma(preg_match('/^ONIX-[A-HJ-NP-Z2-9]{4}-[A-HJ-NP-Z2-9]{4}-[A-HJ-NP-Z2-9]{4}$/', $cod) === 1, "formato: $cod");
    afirma(Recuperacion::verificar($cod, $hash), 'no verifica el original');
    afirma(!str_contains($hash, str_replace('-', '', substr($cod, 5))), 'el hash contiene el código');
    afirma(password_get_info($hash)['algoName'] === 'bcrypt', 'se esperaba bcrypt');
});
prueba('tolera minúsculas, espacios y falta de prefijo/guiones; rechaza códigos errados', function () {
    [$cod, $hash] = Recuperacion::generar();
    afirma(Recuperacion::verificar(strtolower($cod), $hash), 'minúsculas');
    afirma(Recuperacion::verificar(str_replace('-', ' ', $cod), $hash), 'espacios');
    afirma(Recuperacion::verificar(substr($cod, 5), $hash), 'sin prefijo');
    afirma(!Recuperacion::verificar('ONIX-AAAA-AAAA-AAAA', $hash), 'aceptó uno errado');
    afirma(!Recuperacion::verificar('', $hash) && !Recuperacion::verificar($cod, null) && !Recuperacion::verificar($cod, ''), 'vacíos');
    afirma(Recuperacion::normalizar('ONIX-0000-1111-OOOO') === null, 'aceptó caracteres ambiguos');
});
prueba('100 códigos generados son distintos', function () {
    $s = [];
    for ($i = 0; $i < 100; $i++) $s[Recuperacion::generar()[0]] = 1;
    igual(100, count($s), 'únicos');
});

echo "\n▶ Instalador (lógica sin BD)\n";
prueba('config.local.php generado es PHP válido y devuelve el mismo arreglo (incl. comillas y símbolos)', function () {
    $cfg = ['host' => '127.0.0.1', 'port' => '3307', 'name' => 'joyeria_onix', 'user' => 'onix_app',
            'pass' => "p'a\"s\$s\\w{o}rd;#é", 'entorno' => 'xampp', 'instalado_en' => '2026-11-21T10:00:00-06:00'];
    $f = tempnam(sys_get_temp_dir(), 'onixcfg');
    file_put_contents($f, Instalador::contenidoConfig($cfg));
    $leido = include $f;
    @unlink($f);
    afirma($leido === $cfg, 'el arreglo leído no coincide');
});
prueba('validarConexion rechaza servidor/base/puerto con caracteres peligrosos', function () {
    $base = ['host' => '127.0.0.1', 'port' => '3306', 'name' => 'joyeria_onix', 'user' => 'root', 'pass' => ''];
    Instalador::validarConexion($base);
    lanza(fn() => Instalador::validarConexion(['host' => '127.0.0.1;x=1'] + $base));
    lanza(fn() => Instalador::validarConexion(['name' => 'a`; DROP DATABASE x; --'] + $base));
    lanza(fn() => Instalador::validarConexion(['port' => '33 06'] + $base));
    lanza(fn() => Instalador::validarConexion(['user' => ''] + $base));
});
prueba('esLocal: solo loopback sin cabeceras de proxy', function () {
    $bak = $_SERVER;
    $_SERVER = ['REMOTE_ADDR' => '127.0.0.1'];            afirma(Instalador::esLocal(), '127.0.0.1');
    $_SERVER = ['REMOTE_ADDR' => '::1'];                  afirma(Instalador::esLocal(), '::1');
    $_SERVER = ['REMOTE_ADDR' => '203.0.113.9'];          afirma(!Instalador::esLocal(), 'ip pública');
    $_SERVER = ['REMOTE_ADDR' => '127.0.0.1', 'HTTP_X_FORWARDED_FOR' => '203.0.113.9']; afirma(!Instalador::esLocal(), 'proxy');
    $_SERVER = $bak;
});
prueba('los GRANT por tabla cubren exactamente las 19 tablas del esquema', function () use ($TABLAS) {
    $t = array_keys(Instalador::PRIVILEGIOS);
    sort($t);
    igual(implode(',', $TABLAS), implode(',', $t), 'tablas con GRANT');
    foreach (Instalador::PRIVILEGIOS as $tabla => $p) afirma(stripos($p, 'DELETE') === false && stripos($p, 'DROP') === false, "$tabla permite borrar");
});

echo "\n▶ Bloque 1.4 (respaldos, bitácora, herramientas de consola)\n";
prueba('backups/ viene protegido (.htaccess «Require all denied») y su contenido está fuera de git', function () use ($raiz) {
    $ht = (string)@file_get_contents($raiz . '/backups/.htaccess');
    afirma(str_contains($ht, 'Require all denied') && str_contains($ht, 'Deny from all'), 'backups/.htaccess');
    $gi = (string)file_get_contents($raiz . '/.gitignore');
    afirma(str_contains($gi, "backups/*") && str_contains($gi, '!backups/.htaccess'), '.gitignore de backups');
});
prueba('tools/reset_duena.php, crear_usuario_backup.php e instalar_triggers.php solo corren en consola (404 por web)', function () use ($raiz) {
    foreach (['reset_duena.php', 'crear_usuario_backup.php', 'instalar_triggers.php'] as $f) {
        $src = (string)file_get_contents($raiz . '/tools/' . $f);
        afirma(preg_match('/^<\?php\s*\/\*\*.*?\*\/\s*if \(PHP_SAPI !== \'cli\'\) \{ http_response_code\(404\); exit; \}/s', $src) === 1, "$f sin guarda CLI al inicio");
    }
    afirma(is_file($raiz . '/tools/.htaccess'), 'tools/.htaccess');
});
prueba('el usuario de respaldos es de solo lectura por diseño (SELECT/SHOW VIEW) y no figura en PRIVILEGIOS de la app', function () {
    afirma(!array_key_exists(Instalador::USUARIO_BACKUP, Instalador::PRIVILEGIOS));
    afirma(Instalador::USUARIO_BACKUP === 'onix_backup' && Instalador::USUARIO_BACKUP !== Instalador::USUARIO_APP);
});

/* ======================================================================= BD */
if (in_array('--bd', $argv ?? [], true)) {
    $conTriggers = in_array('--triggers', $argv ?? [], true);
    $c = [
        'host' => getenv('ONIX_DB_HOST') ?: '127.0.0.1', 'port' => getenv('ONIX_DB_PORT') ?: '3306',
        'name' => getenv('ONIX_DB_NAME') ?: 'joyeria_onix_test',
        'user' => getenv('ONIX_DB_USER') ?: 'root', 'pass' => getenv('ONIX_DB_PASS') ?: '',
    ];
    if (!str_ends_with($c['name'], '_test')) { echo "\nPor seguridad la BD debe terminar en _test (se hace DROP DATABASE).\n"; exit(1); }
    echo "\n▶ Base de pruebas «{$c['name']}» en {$c['host']}:{$c['port']}" . ($conTriggers ? ' (con triggers)' : '') . "\n";

    $recrear = function () use ($c, $raiz, $conTriggers): PDO {
        $srv = Instalador::conectar($c, false);
        $srv->exec('DROP DATABASE IF EXISTS `' . $c['name'] . '`');
        $srv->exec('CREATE DATABASE `' . $c['name'] . '` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci');
        $pdo = Instalador::conectar($c, true);
        Instalador::ejecutarArchivo($pdo, $raiz . '/schema.sql');
        if ($conTriggers) {
            [$n, $err] = Instalador::instalarTriggers($pdo);
            if ($err) throw new RuntimeException('Triggers con error: ' . implode(', ', $err));
        }
        return $pdo;
    };

    try { $pdo = $recrear(); } catch (Throwable $e) { echo "  \033[31m✘ No se pudo crear la BD de pruebas: " . $e->getMessage() . "\033[0m\n"; exit(1); }
    $q = function (string $sql, array $p = []) use ($pdo) { $st = $pdo->prepare($sql); $st->execute($p); return $st; };
    $bd = $c['name'];

    prueba('schema.sql se puede ejecutar dos veces seguidas (idempotente)', function () use ($pdo, $raiz) {
        Instalador::ejecutarArchivo($pdo, $raiz . '/schema.sql');
        igual(1, $pdo->query("SELECT COUNT(*) FROM secuencias WHERE nombre='ticket'")->fetchColumn(), 'fila ticket');
        igual(0, $pdo->query("SELECT valor FROM secuencias WHERE nombre='ticket'")->fetchColumn(), 'valor ticket');
    });
    prueba('uuid_operacion: char(36) NULL con índice UNIQUE en ventas, compras y caja_movimientos', function () use ($q, $bd) {
        foreach (['ventas', 'compras', 'caja_movimientos'] as $t) {
            $col = $q('SELECT DATA_TYPE, CHARACTER_MAXIMUM_LENGTH l, IS_NULLABLE n FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=? AND TABLE_NAME=? AND COLUMN_NAME=?', [$bd, $t, 'uuid_operacion'])->fetch();
            afirma($col && $col['DATA_TYPE'] === 'char' && (int)$col['l'] === 36 && $col['n'] === 'YES', "$t.uuid_operacion no es char(36) NULL");
            $u = $q('SELECT COUNT(*) FROM information_schema.STATISTICS WHERE TABLE_SCHEMA=? AND TABLE_NAME=? AND COLUMN_NAME=? AND NON_UNIQUE=0 AND SEQ_IN_INDEX=1', [$bd, $t, 'uuid_operacion'])->fetchColumn();
            afirma((int)$u >= 1, "$t.uuid_operacion sin UNIQUE");
        }
    });
    prueba('updated_at TIMESTAMP … ON UPDATE CURRENT_TIMESTAMP (+índice) en productos, categorias y proveedores', function () use ($q, $bd) {
        foreach (['productos', 'categorias', 'proveedores'] as $t) {
            $col = $q('SELECT COLUMN_TYPE, EXTRA FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=? AND TABLE_NAME=? AND COLUMN_NAME=?', [$bd, $t, 'updated_at'])->fetch();
            afirma($col && stripos($col['COLUMN_TYPE'], 'timestamp') === 0 && stripos($col['EXTRA'], 'on update') !== false, "$t.updated_at mal definido");
            $i = $q('SELECT COUNT(*) FROM information_schema.STATISTICS WHERE TABLE_SCHEMA=? AND TABLE_NAME=? AND COLUMN_NAME=?', [$bd, $t, 'updated_at'])->fetchColumn();
            afirma((int)$i >= 1, "$t sin índice en updated_at");
        }
    });
    prueba('usuarios: codigo_recuperacion_hash VARCHAR(255) NULL y debe_cambiar_password TINYINT(1) NOT NULL DEFAULT 0', function () use ($q, $bd) {
        $h = $q('SELECT COLUMN_TYPE, IS_NULLABLE n FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=? AND TABLE_NAME=? AND COLUMN_NAME=?', [$bd, 'usuarios', 'codigo_recuperacion_hash'])->fetch();
        afirma($h && $h['COLUMN_TYPE'] === 'varchar(255)' && $h['n'] === 'YES', 'codigo_recuperacion_hash');
        $d = $q('SELECT COLUMN_TYPE, IS_NULLABLE n, COLUMN_DEFAULT d FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=? AND TABLE_NAME=? AND COLUMN_NAME=?', [$bd, 'usuarios', 'debe_cambiar_password'])->fetch();
        afirma($d && stripos($d['COLUMN_TYPE'], 'tinyint(1)') === 0 && $d['n'] === 'NO' && (string)$d['d'] === '0', 'debe_cambiar_password');
    });
    prueba('usuarios 1.3: email VARCHAR(150) NULL, reset_token_hash VARCHAR(255) NULL, reset_token_expira DATETIME NULL', function () use ($q, $bd) {
        $esp = ['email' => 'varchar(150)', 'reset_token_hash' => 'varchar(255)', 'reset_token_expira' => 'datetime'];
        foreach ($esp as $col => $tipo) {
            $r = $q('SELECT COLUMN_TYPE, IS_NULLABLE n FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=? AND TABLE_NAME=? AND COLUMN_NAME=?', [$bd, 'usuarios', $col])->fetch();
            afirma($r && $r['COLUMN_TYPE'] === $tipo && $r['n'] === 'YES', "usuarios.$col");
        }
    });
    prueba('RBAC sembrado: 2 roles, 12 permisos, cajero con 4 permisos (los de la Parte 2) y ninguno reservado', function () use ($pdo) {
        igual(2, $pdo->query('SELECT COUNT(*) FROM roles')->fetchColumn(), 'roles');
        igual(12, $pdo->query('SELECT COUNT(*) FROM permisos')->fetchColumn(), 'permisos');
        $p = $pdo->query("SELECT permiso FROM rol_permiso WHERE rol='cajero' AND concedido=1 ORDER BY permiso")->fetchAll(PDO::FETCH_COLUMN);
        igual('caja.operar,inventario.consultar,ventas.cobrar,ventas.reimprimir', implode(',', $p), 'permisos del cajero');
    });
    prueba('migrar(): una base 1.2 (sin las 3 columnas) las recupera y repetirlo no hace nada', function () use ($pdo) {
        foreach (['email', 'reset_token_hash', 'reset_token_expira'] as $col) $pdo->exec('ALTER TABLE usuarios DROP COLUMN `' . $col . '`');
        igual(3, count(Instalador::migrar($pdo)), 'columnas agregadas');
        igual(0, count(Instalador::migrar($pdo)), 'segunda ejecución');
        $n = $pdo->query("SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='usuarios' AND COLUMN_NAME IN ('email','reset_token_hash','reset_token_expira')")->fetchColumn();
        igual(3, $n, 'columnas presentes');
    });
    prueba('los inserts de tests/ (usuarios sin las columnas nuevas; movimientos sin uuid) siguen funcionando', function () use ($pdo) {
        $pdo->exec("INSERT IGNORE INTO usuarios (id_usuario, nombre, usuario, password, rol) VALUES (1,'Dueña','duena','x','duena'),(2,'Cajero','cajero','x','cajero')");
        $u = $pdo->query('SELECT codigo_recuperacion_hash h, debe_cambiar_password d FROM usuarios WHERE id_usuario=1')->fetch();
        afirma($u['h'] === null && (int)$u['d'] === 0, 'defaults de usuarios');
        $pdo->exec('INSERT INTO cajas (id_usuario_apertura, monto_inicial) VALUES (1, 0)');
        $caja = (int)$pdo->lastInsertId();
        $ins = $pdo->prepare("INSERT INTO caja_movimientos (id_caja, tipo, monto, motivo, id_venta, id_usuario) VALUES (?,?,?,?,?,?)");
        $ins->execute([$caja, 'INGRESO', '1.00', 'a', null, 1]);
        $ins->execute([$caja, 'GASTO', '1.00', 'b', null, 1]);   // dos NULL en una columna UNIQUE: permitido
        $u2 = $pdo->prepare('INSERT INTO caja_movimientos (id_caja, tipo, monto, motivo, id_usuario, uuid_operacion) VALUES (?,?,?,?,?,?)');
        $u2->execute([$caja, 'INGRESO', '2.00', 'c', 1, '11111111-1111-4111-8111-111111111111']);
        lanza(fn() => $u2->execute([$caja, 'INGRESO', '2.00', 'dup', 1, '11111111-1111-4111-8111-111111111111']));   // mismo uuid: rechazado
    });
    prueba('updated_at cambia solo al modificar la fila', function () use ($pdo) {
        $pdo->exec("INSERT INTO categorias (nombre) VALUES ('Prueba')");
        $pdo->exec("UPDATE categorias SET updated_at = '2020-01-01 00:00:00' WHERE nombre='Prueba'");
        $pdo->exec("UPDATE categorias SET stock_minimo = 5 WHERE nombre='Prueba'");
        $f = $pdo->query("SELECT updated_at FROM categorias WHERE nombre='Prueba'")->fetchColumn();
        afirma(strtotime((string)$f) > strtotime('2021-01-01'), 'updated_at no se actualizó: ' . $f);
    });

    if ($conTriggers) {
        prueba('triggers: 5 instalados; kardex/bitácora inmutables; venta sin caja abierta denegada', function () use ($pdo, $q, $bd) {
            igual(5, $q('SELECT COUNT(*) FROM information_schema.TRIGGERS WHERE TRIGGER_SCHEMA=?', [$bd])->fetchColumn(), 'triggers');
            $pdo->exec("INSERT INTO bitacora (accion) VALUES ('X')");
            lanza(fn() => $pdo->exec("UPDATE bitacora SET accion='Y'"));
            lanza(fn() => $pdo->exec('DELETE FROM bitacora'));
            lanza(fn() => $pdo->exec('INSERT INTO ventas (id_usuario, total, efectivo, numero_ticket, id_caja) VALUES (1,1,1,1,NULL)'));
            $pdo->exec("UPDATE cajas SET estado='CERRADA' WHERE estado='ABIERTA'");
            $cerrada = (int)$pdo->query('SELECT MAX(id_caja) FROM cajas')->fetchColumn();
            lanza(fn() => $pdo->exec("INSERT INTO ventas (id_usuario, total, efectivo, numero_ticket, id_caja) VALUES (1,1,1,2,$cerrada)"));
            $pdo->exec('INSERT INTO cajas (id_usuario_apertura, monto_inicial) VALUES (1, 0)');
            $abierta = (int)$pdo->lastInsertId();
            $pdo->exec("INSERT INTO ventas (id_usuario, total, efectivo, numero_ticket, id_caja) VALUES (1,1,1,3,$abierta)");   // con caja abierta: permitido
            $pdo->exec('TRUNCATE TABLE bitacora');   // TRUNCATE no dispara triggers (así limpia tests/)
        });
    }

    // Se deja la BD limpia y lista para tests/run.php
    try { $recrear(); echo "\n  BD «{$bd}» recreada limpia desde schema.sql. Ahora ejecuta:  php tests/run.php\n"; }
    catch (Throwable $e) { echo "\n  No se pudo dejar la BD limpia: " . $e->getMessage() . "\n"; $fallos++; }
} else {
    echo "\n  (Para probar contra MariaDB y dejar joyeria_onix_test lista:  php tools/verificar_instalador.php --bd)\n";
}

echo "\n  Resultado: $ok ok, $fallos fallidas\n";
exit($fallos ? 1 : 0);
