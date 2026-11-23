<?php
require_once __DIR__ . '/bootstrap.php';
echo "\n▶ Compras · Ventas · Caja · Anulaciones · Arqueo\n";
limpiar_bd();
function uuid4(): string { $d = random_bytes(16); $d[6] = chr(ord($d[6]) & 0x0f | 0x40); $d[8] = chr(ord($d[8]) & 0x3f | 0x80); return vsprintf('%s%s-%s-%s-%s-%s%s%s', str_split(bin2hex($d), 4)); }
function stock_de(int $id): int { return (int)Database::get()->query("SELECT stock FROM productos WHERE id_producto=$id")->fetchColumn(); }
function det(int $idVenta, int $idProd): array { $st = Database::get()->prepare('SELECT * FROM detalle_venta WHERE id_venta=? AND id_producto=?'); $st->execute([$idVenta, $idProd]); return $st->fetch(); }

$cat = nueva_categoria('Plata', 'gramos');
Database::get()->exec("INSERT INTO proveedores (nombre, telefono) VALUES ('Proveedor Uno','2222-0000'),('Proveedor Dos',NULL)");
$A = nuevo_producto($cat, 'PLA-0001', '4.000', '25.00');
$B = nuevo_producto($cat, 'PLA-0002', '2.250', '12.50');

prueba('compra: entra al Kardex y productos.stock/costo quedan sincronizados', function () use ($A, $B) {
    $r = Compra::crear(1, 1, 'primera', [['id' => $B, 'cantidad' => 10, 'costo' => '5.00'], ['id' => $A, 'cantidad' => 10, 'costo' => '10.00']], uuid4(), 'F-001');
    afirma(!$r['duplicada']);
    igual(10, stock_de($A)); igual(10, stock_de($B));
    igual('100.00', ultimo_kardex($A)['saldo_monetario']); igual('ENTRADA', ultimo_kardex($A)['tipo']);
    igual('10.0000', Database::get()->query("SELECT costo FROM productos WHERE id_producto=$A")->fetchColumn(), 'caché costo = CPP');
});

prueba('compra idempotente: reenviar el mismo uuid_operacion NO duplica el stock', function () use ($A) {
    $u = uuid4();
    $r1 = Compra::crear(1, 1, '', [['id' => $A, 'cantidad' => 2, 'costo' => '10.00']], $u, 'F-002');
    $r2 = Compra::crear(1, 1, '', [['id' => $A, 'cantidad' => 2, 'costo' => '10.00']], $u, 'F-002');
    igual($r1['id_compra'], $r2['id_compra']); afirma($r2['duplicada']);
    igual(12, stock_de($A));
});

prueba('compra: factura única por proveedor (se puede repetir en otro proveedor)', function () use ($A) {
    lanza(fn() => Compra::crear(1, 1, '', [['id' => $A, 'cantidad' => 1, 'costo' => '10.00']], uuid4(), 'F-001'), 'factura');
    igual(12, stock_de($A), 'la compra rechazada no movió stock');
    Compra::crear(1, 2, '', [['id' => $A, 'cantidad' => 1, 'costo' => '10.00']], uuid4(), 'F-001');
    igual(13, stock_de($A));
});

prueba('compra: paginación real y proveedor inactivo rechazado', function () use ($A) {
    $p = Compra::listar(1, 2);
    igual(2, count($p['filas'])); afirma($p['paginas'] >= 2 && $p['total'] >= 3);
    Proveedor::quitar(2);
    lanza(fn() => Compra::crear(1, 2, '', [['id' => $A, 'cantidad' => 1, 'costo' => '1.00']], uuid4()), 'inactivo');
    Proveedor::reactivar(2);
});

prueba('venta sin caja abierta es rechazada', function () use ($A) {
    lanza(fn() => Venta::crear(2, [['id' => $A, 'cantidad' => 1]], 2500, uuid4()), 'caja');
});

prueba('caja única: no se pueden abrir dos a la vez', function () {
    afirma(Caja::abrir(1, 5000) > 0);
    lanza(fn() => Caja::abrir(2, 100), 'ya hay una caja abierta');
});

prueba('venta rechazada si el vuelto supera el efectivo disponible en caja', function () use ($A) {
    $ventasAntes = (int)Database::get()->query('SELECT COUNT(*) FROM ventas')->fetchColumn();
    lanza(fn() => Venta::crear(2, [['id' => $A, 'cantidad' => 1]], 10000, uuid4()), 'no hay dinero suficiente en caja');
    igual($ventasAntes, (int)Database::get()->query('SELECT COUNT(*) FROM ventas')->fetchColumn(), 'no se registra la venta');
    igual(0, (int)Database::get()->query("SELECT valor FROM secuencias WHERE nombre='ticket'")->fetchColumn(), 'no consume ticket');
});

$V1 = $V2 = $V3 = 0;
prueba('venta: ticket correlativo, foto histórica, costo_total y SALIDA en el Kardex', function () use ($A, $B, &$V1, &$V2) {
    $r = Venta::crear(2, [['id' => $B, 'cantidad' => 2], ['id' => $A, 'cantidad' => 3]], 10000, uuid4());
    $V1 = $r['id_venta']; $v = Venta::obtener($V1);
    igual(1, $v['numero_ticket']); igual('100.00', $v['total']); igual('0.00', $v['cambio']);
    igual('30.00', det($V1, $A)['costo_total'], 'costo_total A = 3 x 10.00'); igual('10.00', det($V1, $B)['costo_total']);
    igual('10.0000', det($V1, $A)['costo_unitario']); igual('PLA-0001', det($V1, $A)['codigo']); igual('gramos', det($V1, $A)['unidad_peso']);
    igual(10, stock_de($A)); igual(8, stock_de($B));
    $V2 = Venta::crear(2, [['id' => $A, 'cantidad' => 1]], 3000, uuid4())['id_venta'];
    igual(2, Venta::obtener($V2)['numero_ticket']); igual('5.00', Venta::obtener($V2)['cambio']);
});

prueba('venta rechazada (efectivo corto / sin stock) NO consume número de ticket', function () use ($A, &$V3, $B) {
    lanza(fn() => Venta::crear(2, [['id' => $A, 'cantidad' => 1]], 1000, uuid4()), 'efectivo');
    lanza(fn() => Venta::crear(2, [['id' => $A, 'cantidad' => 999]], 99999999, uuid4()), 'insuficiente');
    $V3 = Venta::crear(2, [['id' => $B, 'cantidad' => 1]], 1250, uuid4())['id_venta'];
    igual(3, Venta::obtener($V3)['numero_ticket'], 'sin huecos');
});

prueba('venta idempotente: mismo uuid_operacion devuelve la misma venta y descuenta una sola vez', function () use ($B) {
    $u = uuid4(); $antes = stock_de($B);
    $r1 = Venta::crear(2, [['id' => $B, 'cantidad' => 1]], 1250, $u);
    $r2 = Venta::crear(2, [['id' => $B, 'cantidad' => 1]], 1250, $u);
    igual($r1['id_venta'], $r2['id_venta']); afirma($r2['duplicada']);
    igual($antes - 1, stock_de($B)); igual(4, Venta::obtener($r1['id_venta'])['numero_ticket']);
    Venta::anular($r1['id_venta'], 'prueba idempotencia', 1);   // deja el escenario como estaba para el arqueo
});

prueba('ticket histórico: renombrar/cambiar precio del producto NO altera ventas pasadas', function () use ($A, &$V1) {
    Database::get()->exec("UPDATE productos SET nombre='Nombre NUEVO', codigo='PLA-9999', precio_venta=99.00 WHERE id_producto=$A");
    $d = array_column(Venta::detalle($V1), null, 'id_producto')[$A];
    igual('PLA-0001', $d['codigo']); igual('Producto PLA-0001', $d['nombre']); igual('25.00', $d['precio_unitario']);
    Database::get()->exec("UPDATE productos SET nombre='Producto PLA-0001', codigo='PLA-0001', precio_venta=25.00 WHERE id_producto=$A");
});

prueba('anular venta tras compra POSTERIOR: re-ingresa costo_total exacto; doble anulación y motivo corto rechazados', function () use ($A, $B, &$V1) {
    Compra::crear(1, 1, '', [['id' => $A, 'cantidad' => 7, 'costo' => '13.37']], uuid4(), 'F-777');     // cambia el CPP
    lanza(fn() => Venta::anular($V1, 'no', 1), 'motivo');
    $a0 = stock_de($A); $b0 = stock_de($B);
    Venta::anular($V1, 'Cliente devolvió la mercadería', 1);
    igual($a0 + 3, stock_de($A)); igual($b0 + 2, stock_de($B));
    $kA = Database::get()->query("SELECT * FROM kardex WHERE id_producto=$A AND tipo='ANULACION_VENTA' ORDER BY id_kardex DESC LIMIT 1")->fetch();
    igual('30.00', $kA['valor_total'], 'exactamente el costo_total original aunque el CPP ya cambió');
    igual('ANULADA', Venta::obtener($V1)['estado']);
    lanza(fn() => Venta::anular($V1, 'otra vez anulada', 1), 'ya fue anulada');
    igual($a0 + 3, stock_de($A), 'la doble anulación no movió stock');
});

prueba('movimientos de caja: validaciones (motivo >= 3, monto > 0, no retirar más de lo que hay)', function () {
    lanza(fn() => Caja::movimiento(1, 'GASTO', 500, 'x'), 'motivo');
    lanza(fn() => Caja::movimiento(1, 'GASTO', 0, 'Limpieza'), 'monto');
    lanza(fn() => Caja::movimiento(1, 'RETIRO', 99999999, 'Retiro enorme'), 'supera');
    lanza(fn() => Caja::movimiento(1, 'ROBO', 100, 'Retiro'), 'no válido');
});

$cajaId = 0;
prueba('ARQUEO exacto: inicial + ventas activas + ingresos − retiros − gastos − devoluciones', function () use (&$cajaId) {
    $cajaId = (int)Caja::abierta()['id_caja'];
    Caja::movimiento(1, 'INGRESO', 2000, 'Cambio extra');
    Caja::movimiento(1, 'RETIRO', 3000, 'Depósito banco');
    Caja::movimiento(1, 'GASTO', 550, 'Bolsas y papel');
    Caja::movimiento(1, 'DEVOLUCION', 1000, 'Devolución parcial');
    // inicial 50.00 + ventas activas (V2 25.00 + V3 12.50; V1 y la idempotente están ANULADAS) = 87.50
    // + 20.00 − 30.00 − 5.50 − 10.00 = 62.00
    $r = Caja::resumen($cajaId);
    igual(5000, $r['inicial']); igual(3750, $r['ventas']); igual(6200, $r['esperado'], 'esperado');
    $c = Caja::cerrar(1, 6200);
    igual(0, $c['diferencia']); igual('CERRADA', Database::get()->query("SELECT estado FROM cajas WHERE id_caja=$cajaId")->fetchColumn());
    igual('62.00', Database::get()->query("SELECT efectivo_esperado FROM cajas WHERE id_caja=$cajaId")->fetchColumn());
});

prueba('anular venta de caja CERRADA exige caja abierta y registra DEVOLUCION (FK id_venta)', function () use (&$V2, &$cajaId) {
    lanza(fn() => Venta::anular($V2, 'Error de digitación en el precio', 1), 'abrir la caja');
    igual('ACTIVA', Venta::obtener($V2)['estado'], 'sigue activa tras el rechazo');
    $caja2 = Caja::abrir(1, 10000);
    Venta::anular($V2, 'Error de digitación en el precio', 1);
    $m = Caja::movimientos($caja2);
    igual(1, count($m)); igual('DEVOLUCION', $m[0]['tipo']); igual('25.00', $m[0]['monto']); igual($V2, $m[0]['id_venta']);
    igual(7500, Caja::resumen($caja2)['esperado'], 'caja 2: 100.00 − 25.00');
    igual('62.00', Database::get()->query("SELECT efectivo_esperado FROM cajas WHERE id_caja=$cajaId")->fetchColumn(), 'el arqueo guardado de la caja 1 (ya cerrada) no cambia');
});

prueba('anulación de compra: valida stock restante; si se vendió parte, se rechaza', function () use ($cat) {
    $C = nuevo_producto($cat, 'PLA-0003', '1.000', '20.00');
    $c1 = Compra::crear(1, 1, '', [['id' => $C, 'cantidad' => 5, 'costo' => '8.00']], uuid4(), 'F-C1')['id_compra'];
    Compra::anular($c1, 'Se registró con el proveedor equivocado', 1);
    igual(0, stock_de($C)); igual('0.00', ultimo_kardex($C)['saldo_monetario']); igual('ANULACION_COMPRA', ultimo_kardex($C)['tipo']);
    lanza(fn() => Compra::anular($c1, 'Segunda anulación', 1), 'ya fue anulada');
    $c2 = Compra::crear(1, 1, '', [['id' => $C, 'cantidad' => 5, 'costo' => '8.00']], uuid4(), 'F-C2')['id_compra'];
    Venta::crear(2, [['id' => $C, 'cantidad' => 3]], 6000, uuid4());
    lanza(fn() => Compra::anular($c2, 'Anulación tardía de prueba', 1), 'ya se vendió');
    igual(2, stock_de($C), 'rollback completo: el stock no cambió');
    lanza(fn() => Compra::anular($c2, 'abc', 1), 'motivo');
});

prueba('Categoria: no permite baja con stock ni cambiar unidad con movimientos (ERR-15)', function () use ($cat) {
    lanza(fn() => Categoria::baja($cat), 'existencias');
    lanza(fn() => Categoria::actualizar($cat, ['nombre' => 'Plata', 'unidad_peso' => 'kilates', 'stock_minimo' => 0, 'peso_minimo' => 0]), 'unidad de peso');
    Categoria::actualizar($cat, ['nombre' => 'Plata', 'unidad_peso' => 'gramos', 'stock_minimo' => 3, 'peso_minimo' => 0]);   // mismo unidad: permitido
});

prueba('Producto: nace con stock=0; no se da de baja con stock>0; código correlativo', function () use ($cat, $A) {
    $id = Producto::crear(['codigo' => 'PLA-0099', 'nombre' => 'Nuevo', 'descripcion' => '', 'id_categoria' => $cat, 'peso' => '1.500', 'precio_venta' => '9.00']);
    igual(0, stock_de($id));
    lanza(fn() => Producto::quitar($A), 'existencias');
    Producto::quitar($id); Producto::reactivar($id);
    igual('PLA-0100', Producto::siguienteCodigo($cat));
    $cols = array_keys(Producto::listar('', 0, true, false)[0]);
    afirma(!in_array('costo', $cols, true), 'el cajero no recibe costos');
});

exit(resumen());
