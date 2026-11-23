<?php
/**
 * Bloque 2 · PWA híbrida: catálogo incremental (updated_at), venta offline idempotente, conflictos de negocio,
 * descarte con bitácora, zona horaria fija y artefactos PWA (manifest, íconos, Service Worker, layout).
 */
require_once __DIR__ . '/bootstrap.php';
require_once dirname(__DIR__) . '/app/models/Sincronizacion.php';
echo "\n▶ PWA híbrida: sincronización de catálogo y ventas sin conexión\n";
limpiar_bd();
if (!function_exists('uuid4')) {
    function uuid4(): string { $d = random_bytes(16); $d[6] = chr(ord($d[6]) & 0x0f | 0x40); $d[8] = chr(ord($d[8]) & 0x3f | 0x80); return vsprintf('%s%s-%s-%s-%s-%s%s%s', str_split(bin2hex($d), 4)); }
}
$pdo = Database::get();
$q = fn(string $sql, array $p = []) => (function () use ($pdo, $sql, $p) { $s = $pdo->prepare($sql); $s->execute($p); return $s; })();
$stock = fn(int $id) => (int)$q('SELECT stock FROM productos WHERE id_producto = ?', [$id])->fetchColumn();
$ticketActual = fn() => (int)$pdo->query("SELECT valor FROM secuencias WHERE nombre='ticket'")->fetchColumn();
$bit = fn(string $a) => (int)$q('SELECT COUNT(*) FROM bitacora WHERE accion = ?', [$a])->fetchColumn();
$ids = fn(array $r) => array_map(fn($f) => $f['id_producto'], $r['items']);
$iso = fn(int $delta) => gmdate('Y-m-d\TH:i:s\Z', time() + $delta);

/* ------------------------------------------------------------------ zona horaria */
prueba('zona horaria fija: PHP y la sesión de MariaDB en El Salvador (-06:00) y NOW() coincide con date()', function () {
    $z = Database::zonaHoraria();
    igual('America/El_Salvador', $z['php'], 'PHP');
    igual('-06:00', $z['php_offset'], 'offset PHP');
    igual('-06:00', $z['sesion'], 'sesión MariaDB');
    afirma($z['diferencia_seg'] <= 5, 'NOW() y date() difieren ' . $z['diferencia_seg'] . ' s');
});

prueba('TIMESTAMP (updated_at) se lee en la misma zona que PHP: no hay desfase de horas', function () use ($pdo) {
    $pdo->exec("INSERT INTO categorias (nombre) VALUES ('Zona')");
    $t = strtotime((string)$pdo->query("SELECT updated_at FROM categorias WHERE nombre='Zona'")->fetchColumn());
    afirma(abs($t - time()) <= 5, 'updated_at difiere ' . ($t - time()) . ' s de la hora de PHP');
});

/* ------------------------------------------------------------------ datos */
limpiar_bd();
$pdo->exec("INSERT INTO proveedores (nombre) VALUES ('Proveedor')");
$catA = nueva_categoria('Oro', 'gramos'); $catB = nueva_categoria('Plata', 'gramos');
$A = nuevo_producto($catA, 'ORO-0001', '4.000', '25.00');
$B = nuevo_producto($catA, 'ORO-0002', '2.000', '12.50');   // quedará SIN stock
$C = nuevo_producto($catB, 'PLA-0001', '1.000', '8.00');    // quedará inactivo
$D = nuevo_producto($catB, 'PLA-0002', '1.000', '9.00');
Compra::crear(1, 1, '', [['id' => $A, 'cantidad' => 10, 'costo' => '10.00'], ['id' => $C, 'cantidad' => 5, 'costo' => '3.00'], ['id' => $D, 'cantidad' => 5, 'costo' => '4.00']], uuid4(), 'F-1');
$pdo->exec("UPDATE productos SET estado = 0 WHERE id_producto = $C");

/* ------------------------------------------------------------------ catálogo */
$cursorFijo = '2026-02-01 00:00:00';
prueba('catálogo completo: solo lo vendible (activo y con stock), sin costos', function () use ($ids, $A, $D) {
    $r = Sincronizacion::catalogo('', 0, true);
    $v = $ids($r); sort($v);
    igual(implode(',', [$A, $D]), implode(',', $v), 'productos');
    afirma(!isset($r['items'][0]['costo']), 'el catálogo no debe exponer costos');
    afirma($r['items'][0]['vigente'] === true && !$r['hay_mas'] && $r['ultimo'] !== null, 'forma de la respuesta');
    $esperado = (new DateTimeImmutable($r['ahora']))->modify('-' . Sincronizacion::MARGEN_CURSOR_SEG . ' seconds')->format('Y-m-d H:i:s');
    igual($esperado, $r['cursor_sugerido'], 'cursor = ahora - margen');
    igual('-06:00', $r['zona'], 'zona informada');
});

prueba('catálogo incremental: cambios por updated_at, con «tumbas» para lo que dejó de ser vendible', function () use ($pdo, $ids, $A, $B, $C, $D, $catB, $cursorFijo, $q) {
    $pdo->exec("UPDATE productos SET updated_at = '2026-01-01 00:00:00'");
    $pdo->exec("UPDATE categorias SET updated_at = '2026-01-01 00:00:00'");
    $pdo->exec("UPDATE productos SET precio_venta = 30.00 WHERE id_producto = $A");   // cambia precio -> updated_at = ahora
    $pdo->exec("UPDATE productos SET stock = 0 WHERE id_producto = $D");               // se agotó -> tumba
    $r = Sincronizacion::catalogo($cursorFijo, 0, false);
    $v = $ids($r); sort($v);
    igual(implode(',', [$A, $D]), implode(',', $v), 'solo A y D cambiaron');
    $porId = array_column($r['items'], null, 'id_producto');
    igual('30.00', $porId[$A]['precio_venta']); afirma($porId[$A]['vigente'] === true, 'A vigente');
    afirma($porId[$D]['vigente'] === false, 'D agotado debe viajar como no vigente');
    afirma(!isset($porId[$B]) && !isset($porId[$C]), 'B y C no cambiaron');
    $pdo->exec("UPDATE productos SET stock = 5 WHERE id_producto = $D");
});

prueba('catálogo: renombrar una categoría hace viajar sus productos (updated_at de la categoría)', function () use ($pdo, $ids, $catB, $A, $D, $cursorFijo) {
    $pdo->exec("UPDATE productos SET updated_at = '2026-01-01 00:00:00'");
    $pdo->exec("UPDATE categorias SET updated_at = '2026-01-01 00:00:00'");
    $pdo->exec("UPDATE categorias SET nombre = 'Plata 925' WHERE id_categoria = $catB");
    $r = Sincronizacion::catalogo($cursorFijo, 0, false);
    $porId = array_column($r['items'], null, 'id_producto');
    afirma(isset($porId[$D]) && !isset($porId[$A]), 'solo los de la categoría cambiada');
    igual('Plata 925', $porId[$D]['categoria']);
});

prueba('catálogo: paginación por (cambio, id) con muchas filas del mismo segundo no pierde ni repite', function () use ($pdo, $catA, $cursorFijo) {
    $pdo->exec("UPDATE categorias SET updated_at = '2026-01-01 00:00:00'");   // la renombrada del caso anterior no debe contaminar
    $nuevos = [];
    for ($i = 1; $i <= 5; $i++) {
        $id = nuevo_producto($catA, "PAG-000$i");
        $pdo->exec("UPDATE productos SET stock = 3 WHERE id_producto = $id");
        $nuevos[] = $id;
    }
    $pdo->exec("UPDATE productos SET updated_at = '2026-03-03 10:00:00' WHERE codigo LIKE 'PAG-%'");   // mismo segundo
    $vistos = []; $desde = '2026-03-03 09:00:00'; $did = 0; $paginas = 0;
    do {
        $r = Sincronizacion::catalogo($desde, $did, false, 2);
        foreach ($r['items'] as $f) $vistos[] = $f['id_producto'];
        if ($r['hay_mas']) { $desde = $r['ultimo']['cambio']; $did = $r['ultimo']['id']; }
        $paginas++;
    } while ($r['hay_mas'] && $paginas < 10);
    $esperados = $nuevos; sort($esperados); sort($vistos);
    igual(implode(',', $esperados), implode(',', $vistos), 'todas exactamente una vez');
    igual(3, $paginas, 'páginas de 2, 2 y 1');
    lanza(fn() => Sincronizacion::catalogo('ayer', 0, false), 'Cursor');
});

/* ------------------------------------------------------------------ Venta::crear con datos offline */
$caja = Caja::abrir(1, 0);
$pdo->exec("UPDATE cajas SET fecha_apertura = NOW() - INTERVAL 1 DAY WHERE id_caja = $caja");
$A_stock0 = $stock($A);

prueba('fecha original: UTC -> El Salvador, sin futuro y sin quedar antes de la apertura de la caja', function () use ($iso) {
    $f = Venta::fechaLocal($iso(-7200), '2000-01-01 00:00:00');
    afirma(abs(strtotime($f) - (time() - 7200)) <= 2, "conversión UTC -> -06:00 incorrecta: $f vs " . date('Y-m-d H:i:s', time() - 7200));
    afirma(Venta::fechaLocal($iso(3600), '2000-01-01 00:00:00') <= date('Y-m-d H:i:s'), 'el futuro se recorta a ahora');
    $apertura = date('Y-m-d H:i:s', time() - 3600);
    igual($apertura, Venta::fechaLocal($iso(-10 * 86400), $apertura), 'no antes de la apertura');
    afirma(Venta::fechaLocal('mañana', '') === null && Venta::fechaLocal('', '') === null && Venta::fechaLocal('now', '') === null, 'valores no ISO se ignoran');
});

prueba('venta con fecha_iso guarda la hora real de la venta (no la de sincronización)', function () use ($A, $iso, $q, $pdo) {
    $u = uuid4();
    $r = Venta::crear(2, [['id' => $A, 'cantidad' => 1]], 3000, $u, ['fecha_iso' => $iso(-7200)]);
    $f = strtotime((string)$q('SELECT fecha FROM ventas WHERE id_venta = ?', [$r['id_venta']])->fetchColumn());
    afirma(abs($f - (time() - 7200)) <= 2, 'ventas.fecha debía ser hace ~2 h; diferencia ' . ($f - (time() - 7200)) . ' s');
});

prueba('total_esperado distinto => conflicto «precio_cambiado» SIN efectos (ni stock, ni ticket, ni venta)', function () use ($A, $stock, $ticketActual, $q) {
    $s0 = $stock($A); $t0 = $ticketActual(); $u = uuid4();
    try { Venta::crear(2, [['id' => $A, 'cantidad' => 1]], 5000, $u, ['total_esperado' => 2500]); throw new RuntimeException('Debía fallar'); }
    catch (VentaConflicto $e) {
        igual('precio_cambiado', $e->codigo);
        afirma($e instanceof RuntimeException, 'sigue siendo RuntimeException');
        afirma(str_contains($e->getMessage(), '$ 25.00') && str_contains($e->getMessage(), '$ 30.00'), 'el mensaje debe decir cuánto se cobró y cuánto es ahora: ' . $e->getMessage());
    }
    igual($s0, $stock($A), 'stock'); igual($t0, $ticketActual(), 'ticket sin huecos');
    igual(0, $q('SELECT COUNT(*) FROM ventas WHERE uuid_operacion = ?', [$u])->fetchColumn(), 'sin venta');
    Venta::crear(2, [['id' => $A, 'cantidad' => 1]], 5000, uuid4(), ['total_esperado' => 3000]);   // total correcto: se registra
});

/* ------------------------------------------------------------------ Sincronizacion::venta */
prueba('sincronizar: registra una vez, es idempotente y deja bitácora', function () use ($A, $stock, $bit) {
    $u = uuid4(); $s0 = $stock($A); $b0 = $bit('VENTA_OFFLINE_SINCRONIZADA');
    $d = ['uuid_operacion' => $u, 'efectivo' => 40, 'items' => [['id' => $A, 'cantidad' => 1]], 'total_esperado' => 30, 'ticket_temp' => 'TEMP-AB12CD', 'id_usuario_origen' => 2];
    $r1 = Sincronizacion::venta(2, $d);
    afirma($r1['ok'] && $r1['estado'] === 'registrada', 'primera: registrada');
    igual('10.00', number_format($r1['cambio'], 2, '.', ''), 'cambio');
    $r2 = Sincronizacion::venta(2, $d);
    afirma($r2['ok'] && $r2['estado'] === 'duplicada' && $r2['id_venta'] === $r1['id_venta'] && $r2['numero_ticket'] === $r1['numero_ticket'], 'segunda: duplicada, mismo ticket');
    igual($s0 - 1, $stock($A), 'stock descontado una sola vez');
    igual($b0 + 1, $bit('VENTA_OFFLINE_SINCRONIZADA'), 'una sola entrada de bitácora');
});

prueba('sincronizar en orden FIFO: los tickets salen en el orden en que se enviaron', function () use ($A, $D) {
    $t = [];
    foreach ([1, 2, 3] as $n) {
        $r = Sincronizacion::venta(2, ['uuid_operacion' => uuid4(), 'efectivo' => 100, 'items' => [['id' => $A, 'cantidad' => 1]]]);
        afirma($r['ok'], 'venta ' . $n);
        $t[] = $r['numero_ticket'];
    }
    afirma($t[0] < $t[1] && $t[1] < $t[2] && $t[1] === $t[0] + 1 && $t[2] === $t[1] + 1, 'tickets consecutivos: ' . implode(',', $t));
});

prueba('conflicto de stock: estado «conflicto», motivo exacto del servidor y nada registrado', function () use ($A, $q, $ticketActual) {
    $u = uuid4(); $t0 = $ticketActual();
    $r = Sincronizacion::venta(2, ['uuid_operacion' => $u, 'efectivo' => 99999, 'items' => [['id' => $A, 'cantidad' => 999]]]);
    afirma(!$r['ok'] && $r['estado'] === 'conflicto' && $r['codigo'] === 'stock_insuficiente', 'código: ' . json_encode($r));
    afirma(str_starts_with($r['msg'], 'Stock insuficiente de «Producto ORO-0001»'), 'motivo exacto: ' . $r['msg']);
    igual($t0, $ticketActual(), 'no consume ticket');
    igual(0, $q('SELECT COUNT(*) FROM ventas WHERE uuid_operacion = ?', [$u])->fetchColumn());
});

prueba('conflicto de precio desde el servicio (total_esperado decimal)', function () use ($A) {
    $r = Sincronizacion::venta(2, ['uuid_operacion' => uuid4(), 'efectivo' => 100, 'items' => [['id' => $A, 'cantidad' => 1]], 'total_esperado' => '1.00']);
    afirma(!$r['ok'] && $r['codigo'] === 'precio_cambiado', json_encode($r));
});

prueba('caja cerrada mientras no había internet: conflicto con el mensaje de siempre; al reabrir, el reintento con el MISMO uuid funciona', function () use ($pdo, $A, $q, $stock, $iso) {
    $u = uuid4();
    $pdo->exec("UPDATE cajas SET estado = 'CERRADA' WHERE estado = 'ABIERTA'");
    $d = ['uuid_operacion' => $u, 'efectivo' => 100, 'items' => [['id' => $A, 'cantidad' => 1]], 'fecha_iso' => $iso(-600)];
    $r = Sincronizacion::venta(2, $d);
    afirma(!$r['ok'] && $r['estado'] === 'conflicto' && $r['codigo'] === 'caja_cerrada', json_encode($r));
    igual('No hay caja abierta. Abre la caja para poder cobrar.', $r['msg'], 'mensaje exacto');
    $s0 = $stock($A);
    $nueva = Caja::abrir(1, 0);
    $r2 = Sincronizacion::venta(2, $d);
    afirma($r2['ok'] && $r2['estado'] === 'registrada', 'reintento: ' . json_encode($r2));
    igual($s0 - 1, $stock($A));
    $apertura = (string)$q('SELECT fecha_apertura FROM cajas WHERE id_caja = ?', [$nueva])->fetchColumn();
    $fecha = (string)$q('SELECT fecha FROM ventas WHERE id_venta = ?', [$r2['id_venta']])->fetchColumn();
    afirma($fecha >= $apertura, 'la venta no queda antes de la apertura de su caja');
});

prueba('datos inválidos y usuario distinto se devuelven como conflicto (nunca como excepción)', function () use ($A) {
    $c = fn(array $d, int $uid = 2) => Sincronizacion::venta($uid, $d)['codigo'] ?? '';
    igual('carrito_vacio', $c(['uuid_operacion' => uuid4(), 'efectivo' => 10, 'items' => []]));
    igual('datos_invalidos', $c(['uuid_operacion' => uuid4(), 'efectivo' => 10, 'items' => [['id' => $A, 'cantidad' => -1]]]));
    igual('datos_invalidos', $c(['uuid_operacion' => uuid4(), 'efectivo' => 'abc', 'items' => [['id' => $A, 'cantidad' => 1]]]));
    igual('uuid_invalido', $c(['uuid_operacion' => 'no-es-uuid', 'efectivo' => 100, 'items' => [['id' => $A, 'cantidad' => 1]]]));
    igual('usuario_distinto', $c(['uuid_operacion' => uuid4(), 'efectivo' => 100, 'items' => [['id' => $A, 'cantidad' => 1]], 'id_usuario_origen' => 1]));
});

prueba('descartar: exige motivo, no descarta una venta que SÍ existe y deja bitácora cuando procede', function () use ($A, $bit) {
    $u = uuid4();
    $r = Sincronizacion::descartar(1, ['uuid_operacion' => $u, 'motivo' => 'x']);
    afirma(!$r['ok'] && str_contains($r['msg'], '5 caracteres'), 'motivo corto');
    $b0 = $bit('VENTA_OFFLINE_DESCARTADA');
    $r = Sincronizacion::descartar(1, ['uuid_operacion' => $u, 'motivo' => 'Corregida a mano', 'ticket_temp' => 'TEMP-ZZZ111', 'total' => 30, 'detalle_motivo' => 'Stock insuficiente']);
    afirma($r['ok'] && $r['estado'] === 'descartada', json_encode($r));
    igual($b0 + 1, $bit('VENTA_OFFLINE_DESCARTADA'));
    $real = Sincronizacion::venta(2, ['uuid_operacion' => ($v = uuid4()), 'efectivo' => 100, 'items' => [['id' => $A, 'cantidad' => 1]]]);
    $r = Sincronizacion::descartar(1, ['uuid_operacion' => $v, 'motivo' => 'Intento de descartar']);
    afirma(!$r['ok'] && $r['estado'] === 'ya_registrada' && $r['numero_ticket'] === $real['numero_ticket'], 'venta existente: ' . json_encode($r));
    igual($b0 + 1, $bit('VENTA_OFFLINE_DESCARTADA'), 'no se registró un descarte falso');
});

/* ------------------------------------------------------------------ artefactos PWA */
$raiz = dirname(__DIR__);
prueba('manifest.json: instalable (start_url, standalone, colores Onix, íconos 192 y 512 reales)', function () use ($raiz) {
    $m = json_decode((string)file_get_contents("$raiz/manifest.json"), true);
    afirma(is_array($m), 'JSON inválido');
    foreach (['name', 'short_name', 'start_url', 'scope', 'display', 'background_color', 'theme_color', 'icons'] as $k) afirma(!empty($m[$k]), "falta $k");
    igual('standalone', $m['display']);
    igual('#17171c', $m['theme_color']);
    $tam = [];
    foreach ($m['icons'] as $i) {
        $f = "$raiz/" . $i['src'];
        afirma(is_file($f), "no existe {$i['src']}");
        $g = getimagesize($f);
        afirma($g && $g[2] === IMAGETYPE_PNG, "{$i['src']} no es PNG");
        igual($i['sizes'], $g[0] . 'x' . $g[1], 'tamaño declarado vs real de ' . $i['src']);
        $tam[$i['sizes']] = true;
    }
    afirma(isset($tam['192x192']) && isset($tam['512x512']), 'faltan 192x192 y 512x512');
});

prueba('sw.js: todo lo precacheado existe; no cachea POST/JSON/no-store; limpia al cerrar sesión', function () use ($raiz) {
    $sw = (string)file_get_contents("$raiz/sw.js");
    preg_match("/const PRECACHE = \[(.*?)\];/s", $sw, $m);
    afirma(!empty($m[1]), 'no se encontró PRECACHE');
    preg_match_all("/'([^']+)'/", $m[1], $r);
    afirma(count($r[1]) >= 10, 'lista de precache muy corta');
    foreach ($r[1] as $ruta) {
        if (str_starts_with($ruta, 'public/vendor/') && !is_dir("$raiz/public/vendor")) continue;   // vendor se descarga con tools/descargar_vendor.php
        afirma(is_file("$raiz/$ruta"), "precache apunta a un archivo inexistente: $ruta");
    }
    foreach (["req.method !== 'GET'", 'no-store|private', "application/json", 'LIMPIAR_PAGINAS', 'activate'] as $frag) afirma(str_contains($sw, $frag), "sw.js no contiene «$frag»");
    afirma(str_contains($sw, "a=pos"), 'el POS offline debe ser la variante sin historial');
});

prueba('layout: manifest + theme-color, offline.js antes que pwa.js, indicador, y SW solo en contexto seguro', function () use ($raiz) {
    $h = (string)file_get_contents("$raiz/app/views/layout/header.php");
    afirma(str_contains($h, 'rel="manifest"') && str_contains($h, 'theme-color') && str_contains($h, 'data-onix-estado'), 'header');
    $f = (string)file_get_contents("$raiz/app/views/layout/footer.php");
    afirma(strpos($f, 'offline.js') !== false && strpos($f, 'offline.js') < strpos($f, 'pwa.js') && strpos($f, 'ONIX_USR') < strpos($f, 'offline.js'), 'orden de scripts en footer');
    afirma(str_contains((string)file_get_contents("$raiz/public/js/pwa.js"), 'isSecureContext'), 'pwa.js debe registrar solo en contexto seguro');
    afirma(str_contains((string)file_get_contents("$raiz/app/views/auth/login.php"), 'rel="manifest"'), 'login con manifest');
});

exit(resumen());
