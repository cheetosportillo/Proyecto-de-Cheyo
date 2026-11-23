<?php
require_once __DIR__ . '/bootstrap.php';
echo "\n▶ Reportes · Permisos · Historial\n";
limpiar_bd();
$pdo = Database::get();

prueba('gramos y kilates NUNCA se mezclan en la valoración', function () {
    $g = nueva_categoria('Oro', 'gramos'); $k = nueva_categoria('Gemas', 'kilates');
    $p1 = nuevo_producto($g, 'ORO-1', '10.000'); $p2 = nuevo_producto($k, 'GEM-1', '2.500');
    Kardex::registrarEntrada($p1, 'ENTRADA', 3, 10000, 1); Kardex::registrarEntrada($p2, 'ENTRADA', 4, 5000, 1);
    $v = array_column(Reporte::valoracion(), null, 'nombre');
    igual('30.000', $v['Oro']['peso_gramos']);  igual('0.000', $v['Oro']['peso_kilates']);
    igual('0.000', $v['Gemas']['peso_gramos']); igual('10.000', $v['Gemas']['peso_kilates']);
    igual('300.00', $v['Oro']['invertido'], 'inversión desde el saldo del Kardex');
});

prueba('alerta de stock: piezas <= stock_minimo OR peso_total <= peso_minimo', function () {
    $c = nueva_categoria('Cobre', 'gramos'); $p = nuevo_producto($c, 'COB-1', '5.000');
    Kardex::registrarEntrada($p, 'ENTRADA', 10, 100, 1);
    $id = fn() => array_column(Reporte::valoracion(), null, 'nombre')['Cobre'];
    afirma(!$id()['alerta'], 'sin mínimos configurados no alerta');
    Database::get()->exec("UPDATE categorias SET stock_minimo = 10 WHERE nombre='Cobre'");
    afirma($id()['alerta'], 'piezas (10) <= mínimo (10)');
    Database::get()->exec("UPDATE categorias SET stock_minimo = 2, peso_minimo = 50 WHERE nombre='Cobre'");
    afirma($id()['alerta'], 'peso total 50 <= peso mínimo 50 aunque las piezas estén bien');
    igual('peso bajo el mínimo', $id()['alerta_motivo']);
    Database::get()->exec("UPDATE categorias SET stock_minimo = 2, peso_minimo = 49 WHERE nombre='Cobre'");
    afirma(!$id()['alerta']);
});

prueba('historial: el cajero solo ve sus ventas; con dashboard.ver se ve todo', function () use ($pdo) {
    $c = nueva_categoria('Plata', 'gramos'); $p = nuevo_producto($c, 'PLA-1', '1.000', '10.00');
    Kardex::registrarEntrada($p, 'ENTRADA', 20, 500, 1); Caja::abrir(1, 0);
    $u = fn() => bin2hex(random_bytes(4)) . '-' . '0000-4000-8000-' . bin2hex(random_bytes(6));
    Venta::crear(1, [['id' => $p, 'cantidad' => 1]], 1000, $u()); Venta::crear(2, [['id' => $p, 'cantidad' => 2]], 2000, $u());
    $hoy = date('Y-m-d');
    igual(1, Venta::historial($hoy, $hoy, 2)['num'], 'cajero (id 2) ve solo la suya');
    igual('20.00', Venta::historial($hoy, $hoy, 2)['total_periodo']);
    igual(2, Venta::historial($hoy, $hoy, null)['num'], 'la dueña ve todas');
    // permisos del shim
    $_SESSION['usuario']['rol'] = 'cajero';
    afirma(Permisos::tiene('ventas.cobrar') && Permisos::tiene('inventario.consultar') && Permisos::tiene('caja.operar'));
    afirma(!Permisos::tiene('dashboard.ver') && !Permisos::tiene('kardex.ver') && !Permisos::tiene('ventas.anular') && !Permisos::tiene('inventario.gestionar') && !Permisos::tiene('compras.gestionar'));
    $_SESSION['usuario']['rol'] = 'duena';
    afirma(Permisos::tiene('dashboard.ver'));
});

prueba('periodo con > 500 ventas: el total del reporte coincide con SUM() en SQL; las anuladas no suman', function () use ($pdo) {
    $pdo->exec('SET FOREIGN_KEY_CHECKS=0'); $pdo->exec('TRUNCATE TABLE detalle_venta'); $pdo->exec('TRUNCATE TABLE ventas'); $pdo->exec('SET FOREIGN_KEY_CHECKS=1');
    $caja = (int)Caja::abierta()['id_caja'];
    $ins = $pdo->prepare("INSERT INTO ventas (id_usuario,fecha,total,efectivo,cambio,numero_ticket,id_caja,estado) VALUES (?,?,?,?,0,?,?,?)");
    $esperado = 0;
    for ($i = 1; $i <= 612; $i++) {
        $total = mt_rand(100, 99999) / 100; $anulada = $i % 17 === 0;
        $ins->execute([1 + $i % 2, date('Y-m-d 10:00:00'), $total, $total, $i, $caja, $anulada ? 'ANULADA' : 'ACTIVA']);
        if (!$anulada) $esperado += a_centavos($total);
    }
    $h = Venta::historial(date('Y-m-d'), date('Y-m-d'), null, 3, 25);
    igual(612, $h['num']); igual(25, count($h['filas']), 'la página trae solo 25');
    igual(de_centavos($esperado), $h['total_periodo'], 'total de TODO el periodo, no de la página');
    igual($pdo->query("SELECT SUM(total) FROM ventas WHERE estado='ACTIVA'")->fetchColumn(), $h['total_periodo']);
    $rp = Reporte::ventasPeriodo(date('Y-m-d'), date('Y-m-d'));
    igual($h['total_periodo'], $rp['total']['total']);
    igual($h['total_periodo'], Reporte::resumen()['ventas_hoy']);
});

prueba('filtros de fecha semiabiertos: 23:59:59 entra en el día, 00:00:00 del siguiente no', function () use ($pdo) {
    $pdo->exec("UPDATE ventas SET fecha='2026-03-10 12:00:00' WHERE numero_ticket > 3");
    $pdo->exec("UPDATE ventas SET fecha='2026-03-10 23:59:59' WHERE numero_ticket = 1");
    $pdo->exec("UPDATE ventas SET fecha='2026-03-11 00:00:00' WHERE numero_ticket = 2");
    $pdo->exec("UPDATE ventas SET fecha='2026-03-09 23:59:59' WHERE numero_ticket = 3");
    $dia10 = Venta::historial('2026-03-10', '2026-03-10', null, 1, 1000);
    $tickets = array_column($dia10['filas'], 'numero_ticket');
    afirma(in_array(1, $tickets) && !in_array(2, $tickets) && !in_array(3, $tickets), 'límites del día');
});

prueba('CSV seguro: neutraliza =,+,-,@ en texto pero respeta números', function () {
    igual("'=HYPERLINK(\"x\")", csv_celda('=HYPERLINK("x")'));
    igual("'+cmd", csv_celda('+cmd')); igual("'-2+3x", csv_celda('-2+3x')); igual("'@SUM(A1)", csv_celda('@SUM(A1)'));
    igual('-15.50', csv_celda('-15.50')); igual('Anillo', csv_celda('Anillo')); igual('', csv_celda(''));
});

prueba('helpers de dinero: centavos exactos y redondeo', function () {
    igual(1234, a_centavos('12.34')); igual(1, a_centavos('0.005')); igual('12.34', de_centavos(1234)); igual('-0.05', de_centavos(-5));
    igual('3.3333', de_escala(33333, 4)); igual(3, div_redondeada(5, 2)); igual(1, div_redondeada(4, 3));
    igual(19.99 * 100, 1999.0 + 0.0 * 1); igual(1999, a_centavos(19.99), 'sin error de float');
});

exit(resumen());
