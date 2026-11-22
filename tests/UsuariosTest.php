<?php
require __DIR__ . '/bootstrap.php';
foreach (['Usuario', 'Intentos', 'Config'] as $m) require_once "$raiz/app/models/$m.php";
require_once "$raiz/app/core/Recuperacion.php";
require_once "$raiz/app/core/Mailer.php";
echo "\n▶ Bloque 1.3 · Usuarios · RBAC · Recuperación · Configuración · SMTP\n";
limpiar_bd();
$pdo = Database::get();
$pdo->exec("DELETE FROM usuarios WHERE usuario LIKE 't13\\_%'");
$pdo->exec("UPDATE recuperacion_intentos SET intentos = 0");

/* ---------- RBAC ---------- */
prueba('Permisos::delRol: Dueña = *, cajero = los 4 de la Parte 2 (sembrados en la BD)', function () {
    igual('*', implode(',', Permisos::delRol('duena')));
    $p = Permisos::delRol('cajero'); sort($p);
    igual('caja.operar,inventario.consultar,ventas.cobrar,ventas.reimprimir', implode(',', $p));
});
prueba('Permisos::asignar cambia el rol cajero; los permisos reservados no se pueden otorgar', function () {
    Permisos::asignar('cajero', ['ventas.cobrar', 'kardex.ver']);
    $p = Permisos::delRol('cajero'); sort($p);
    igual('kardex.ver,ventas.cobrar', implode(',', $p));
    lanza(fn() => Permisos::asignar('cajero', ['usuarios.gestionar']), 'Dueña');
    lanza(fn() => Permisos::asignar('cajero', ['no.existe']), 'desconocido');
    lanza(fn() => Permisos::asignar('duena', ['ventas.cobrar']));
    // aunque alguien lo marque directo en la BD, delRol lo ignora
    Database::get()->exec("UPDATE rol_permiso SET concedido = 1 WHERE rol='cajero' AND permiso='usuarios.gestionar'");
    afirma(!in_array('usuarios.gestionar', Permisos::delRol('cajero'), true), 'delRol devolvió un permiso reservado');
    Permisos::asignar('cajero', ['ventas.cobrar', 'ventas.reimprimir', 'inventario.consultar', 'caja.operar']);   // restaura
    Database::get()->exec("UPDATE rol_permiso SET concedido = 0 WHERE permiso IN ('usuarios.gestionar','configuracion.gestionar')");
});
prueba('Permisos::tiene: usa la lista de la sesión y nunca concede los reservados a un cajero', function () {
    $bak = $_SESSION['usuario'];
    $_SESSION['usuario'] = ['id' => 2, 'rol' => 'cajero', 'permisos' => ['kardex.ver', 'usuarios.gestionar']];
    afirma(Permisos::tiene('kardex.ver') && !Permisos::tiene('ventas.cobrar') && !Permisos::tiene('usuarios.gestionar'));
    $_SESSION['usuario'] = ['id' => 1, 'rol' => 'duena'];
    afirma(Permisos::tiene('usuarios.gestionar') && Permisos::tiene('lo.que.sea'));
    $_SESSION['usuario'] = $bak;
});

/* ---------- Usuarios ---------- */
prueba('política de contraseña (8-72) y claves temporales (10 caracteres, sin ambiguos, distintas)', function () {
    afirma(Usuario::validarPassword('1234567') !== null && Usuario::validarPassword('12345678') === null);
    afirma(Usuario::validarPassword(str_repeat('a', 73)) !== null);
    $s = [];
    for ($i = 0; $i < 50; $i++) { $c = Usuario::claveTemporal(); afirma(preg_match('/^[A-HJ-NP-Za-km-z2-9]{10}$/', $c) === 1, "formato $c"); $s[$c] = 1; }
    igual(50, count($s), 'únicas');
});
prueba('normalizarEmail: vacío = null, válido pasa, inválido lanza', function () {
    afirma(Usuario::normalizarEmail('  ') === null);
    igual('a@b.co', Usuario::normalizarEmail(' a@b.co '));
    lanza(fn() => Usuario::normalizarEmail('no-es-correo'));
});
prueba('crear: clave temporal con debe_cambiar_password=1; Dueña con llave; actualizar no toca la contraseña', function () {
    $id = Usuario::crear(['nombre' => 'T13 Uno', 'usuario' => 't13_uno', 'email' => 'uno@x.com', 'rol' => 'cajero', 'password' => 'Temporal123']);
    $u = Usuario::porId($id);
    afirma(password_verify('Temporal123', $u['password']) && (int)$u['debe_cambiar_password'] === 1 && $u['email'] === 'uno@x.com');
    afirma($u['codigo_recuperacion_hash'] === null);
    [$llave, $h] = Recuperacion::generar();
    Usuario::actualizar($id, ['nombre' => 'T13 Uno', 'usuario' => 't13_uno', 'email' => null, 'rol' => 'duena', 'llave_hash' => $h]);
    $u = Usuario::porId($id);
    afirma($u['rol'] === 'duena' && $u['email'] === null && Recuperacion::verificar($llave, $u['codigo_recuperacion_hash']), 'ascenso a Dueña');
    afirma(password_verify('Temporal123', $u['password']), 'actualizar cambió la contraseña');
    Usuario::actualizar($id, ['nombre' => 'T13 Uno', 'usuario' => 't13_uno', 'email' => null, 'rol' => 'cajero']);
    afirma(Usuario::porId($id)['codigo_recuperacion_hash'] === null, 'al volver a cajero debe perder la llave');
    afirma(Usuario::existe('t13_uno') && !Usuario::existe('t13_uno', $id));
});
prueba('establecerPassword(forzar) / establecerPasswordYLlave: banderas y token limpios', function () {
    $u = Usuario::porUsuario('t13_uno'); $id = (int)$u['id_usuario'];
    Usuario::guardarTokenCorreo($id, 'x');
    Usuario::establecerPassword($id, 'OtraClave99', true);
    $u = Usuario::porId($id);
    afirma((int)$u['debe_cambiar_password'] === 1 && $u['reset_token_hash'] === null && password_verify('OtraClave99', $u['password']));
    Usuario::establecerPasswordYLlave($id, 'ClaveFinal88', 'hash-llave');
    $u = Usuario::porId($id);
    afirma((int)$u['debe_cambiar_password'] === 0 && $u['codigo_recuperacion_hash'] === 'hash-llave' && password_verify('ClaveFinal88', $u['password']));
});

/* ---------- Código por correo ---------- */
prueba('código de correo: formato ABCD-EFGH, normaliza y verifica; el hash no lo contiene', function () {
    [$c, $h] = Recuperacion::generarCodigoCorreo();
    afirma(preg_match('/^[A-HJ-NP-Z2-9]{4}-[A-HJ-NP-Z2-9]{4}$/', $c) === 1, $c);
    afirma(Recuperacion::verificarCodigoCorreo(strtolower($c), $h) && Recuperacion::verificarCodigoCorreo(str_replace('-', ' ', $c), $h));
    afirma(!Recuperacion::verificarCodigoCorreo('AAAA-AAAA', $h) && !Recuperacion::verificarCodigoCorreo('', $h) && !Recuperacion::verificarCodigoCorreo($c, null));
    afirma(Recuperacion::normalizarCodigoCorreo('0000-1111') === null, 'aceptó ambiguos');
    afirma(!str_contains($h, str_replace('-', '', $c)));
});
prueba('token de correo: vigente 15 min; vencido o invalidado => null', function () {
    $id = (int)Usuario::porUsuario('t13_uno')['id_usuario'];
    Usuario::guardarTokenCorreo($id, 'hash-vigente');
    igual('hash-vigente', Usuario::tokenCorreo($id));
    Database::get()->prepare('UPDATE usuarios SET reset_token_expira = DATE_SUB(NOW(), INTERVAL 1 MINUTE) WHERE id_usuario = ?')->execute([$id]);
    afirma(Usuario::tokenCorreo($id) === null, 'vencido');
    Usuario::guardarTokenCorreo($id, 'otro'); Usuario::invalidarTokenCorreo($id);
    afirma(Usuario::tokenCorreo($id) === null, 'invalidado');
});

/* ---------- Límite de intentos ---------- */
prueba('Intentos: bloquea al llegar al máximo, se libera al vencer la ventana y limpiar() lo reinicia', function () {
    $k = 't13:clave';
    afirma(!Intentos::excedido($k, 3, 900));
    for ($i = 0; $i < 3; $i++) Intentos::sumar($k, 900);
    afirma(Intentos::excedido($k, 3, 900), 'debía bloquear');
    afirma(!Intentos::excedido($k, 4, 900), 'con max=4 aún no');
    Database::get()->prepare("UPDATE recuperacion_intentos SET inicio = DATE_SUB(NOW(), INTERVAL 20 MINUTE) WHERE clave = ?")->execute([$k]);
    afirma(!Intentos::excedido($k, 3, 900), 'la ventana vencida no debe bloquear');
    Intentos::sumar($k, 900);   // ventana nueva: vuelve a 1
    afirma(!Intentos::excedido($k, 2, 900));
    Intentos::sumar($k, 900); Intentos::limpiar($k);
    afirma(!Intentos::excedido($k, 1, 900), 'limpiar');
});

/* ---------- Configuración y cifrado ---------- */
prueba('Config: guardar/obtener; la contraseña SMTP se guarda cifrada (no en claro) y se recupera', function () {
    Config::guardar(['negocio_nombre' => 'Joyería Prueba', 'smtp_password' => 'S3cr3to!clave']);
    igual('Joyería Prueba', Config::obtener('negocio_nombre'));
    igual('S3cr3to!clave', Config::obtener('smtp_password'));
    $crudo = (string)Database::get()->query("SELECT valor FROM configuracion WHERE clave='smtp_password'")->fetchColumn();
    afirma(strncmp($crudo, 'v1:', 3) === 0 && !str_contains($crudo, 'S3cr3to'), 'no está cifrada');
    afirma(Config::tieneSecreto('smtp_password'));
    afirma(Config::obtener('no_existe', 'def') === 'def');
    afirma(Config::_descifrarParaPrueba('v1:' . base64_encode(random_bytes(40))) === null, 'basura debe dar null');
    $a = Config::_cifrarParaPrueba('hola'); $b = Config::_cifrarParaPrueba('hola');
    afirma($a !== $b && Config::_descifrarParaPrueba($a) === 'hola', 'IV distinto en cada cifrado');
    Config::guardar(['negocio_nombre' => 'Joyería Onix', 'smtp_password' => '']);
});

/* ---------- SMTP ---------- */
prueba('Mailer::armarMensaje: sin inyección de cabeceras, asunto UTF-8 codificado, cuerpo base64', function () {
    $m = Mailer::armarMensaje('tienda@x.com', "Joyería\r\nBcc: mal@x.com", 'dest@x.com', "Código ñ\r\nBcc: otro@x.com", "Hola\n.punto al inicio\nñandú");
    afirma(!preg_match('/^Bcc:/mi', $m), 'cabecera inyectada');
    afirma(str_contains($m, '=?UTF-8?B?') && str_contains($m, 'Content-Transfer-Encoding: base64'));
    [$cab, $cuerpo] = explode("\r\n\r\n", $m, 2);
    igual("Hola\n.punto al inicio\nñandú", base64_decode($cuerpo));
    foreach (explode("\r\n", $m) as $l) afirma(strlen($l) <= 998 && ($l === '' || $l[0] !== '.' || $l === '..'), 'línea inválida');
});
prueba('Mailer::enviar contra un servidor SMTP falso (EHLO, AUTH LOGIN, MAIL, RCPT, DATA)', function () {
    $out = tempnam(sys_get_temp_dir(), 'smtp');
    $p = proc_open([PHP_BINARY, __DIR__ . '/_smtp_falso.php', $out], [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
    afirma(is_resource($p), 'no arrancó el servidor falso');
    try {
        $puerto = (int)trim((string)fgets($pipes[1]));
        afirma($puerto > 0, 'sin puerto');
        Mailer::enviar('cliente@x.com', 'Código de prueba ñ', "Tu código es ABCD-EFGH\n", [
            'host' => '127.0.0.1', 'puerto' => $puerto, 'cifrado' => 'none', 'usuario' => 'user@x.com', 'password' => 'p@ss',
            'remitente' => 'tienda@x.com', 'remitente_nombre' => 'Joyería', 'verificar_ssl' => true,
        ]);
        proc_close($p); $p = null;
        $r = json_decode((string)file_get_contents($out), true);
        igual('user@x.com', $r['user'], 'usuario AUTH'); igual('p@ss', $r['pass'], 'clave AUTH');
        afirma(str_contains($r['from'], '<tienda@x.com>') && str_contains($r['to'], '<cliente@x.com>'), 'sobre');
        [, $cuerpo] = explode("\r\n\r\n", $r['data'], 2);
        igual("Tu código es ABCD-EFGH\n", base64_decode(str_replace("\r\n", '', $cuerpo)));
    } finally {
        if (is_resource($p)) { proc_terminate($p); proc_close($p); }
        @unlink($out);
    }
});
prueba('Mailer: errores claros (sin configuración, destino inválido, puerto cerrado)', function () {
    $base = ['host' => '127.0.0.1', 'puerto' => 1, 'cifrado' => 'none', 'usuario' => '', 'password' => '', 'remitente' => 'a@b.co', 'remitente_nombre' => '', 'verificar_ssl' => true];
    lanza(fn() => Mailer::enviar('x@y.com', 'a', 'b', null), 'configurado');
    lanza(fn() => Mailer::enviar('no-es-correo', 'a', 'b', $base), 'destino');
    lanza(fn() => Mailer::enviar('x@y.com', 'a', 'b', $base), 'conectar');
});

$pdo->exec("DELETE FROM usuarios WHERE usuario LIKE 't13\\_%'");
exit(resumen());
