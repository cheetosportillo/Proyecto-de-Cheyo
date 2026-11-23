<?php
require_once __DIR__ . '/bootstrap.php';
echo "\n▶ Kardex CPP\n";
limpiar_bd();
$cat = nueva_categoria('Oro', 'gramos');

prueba('(a) 3 piezas por $10.00 en total -> CPP $3.3333', function () use ($cat) {
    $id = nuevo_producto($cat, 'ORO-A', '2.500');
    // compra de 3 piezas con valor total exacto de $10.00 (SALDO_INICIAL con valor exacto)
    Kardex::registrarEntrada($id, 'ENTRADA', 3, 0, 1, null, null, null, 1000);
    $k = ultimo_kardex($id);
    igual('3.3333', $k['cpp'], 'CPP');
    igual('10.00', $k['saldo_monetario']);
    igual('3', $k['saldo_cantidad']);
    igual('7.500', $k['saldo_peso'], 'peso 3 dec');
    igual('gramos', $k['unidad_peso'], 'foto de unidad');
});

prueba('(b) vaciar el stock deja saldo_monetario $0.00 exacto (ERR-14) sin perder centavos', function () use ($cat) {
    $id = nuevo_producto($cat, 'ORO-B', '1.000');
    Kardex::registrarEntrada($id, 'ENTRADA', 3, 0, 1, null, null, null, 1000);
    $a = Kardex::registrarSalida($id, 'SALIDA', 1, 1);
    $b = Kardex::registrarSalida($id, 'SALIDA', 1, 1);
    $c = Kardex::registrarSalida($id, 'SALIDA', 1, 1);
    igual(1000, $a['valor_total_centavos'] + $b['valor_total_centavos'] + $c['valor_total_centavos'], 'las 3 salidas suman exactamente $10.00');
    $k = ultimo_kardex($id);
    igual('0.00', $k['saldo_monetario']); igual('0', $k['saldo_cantidad']); igual('0.000', $k['saldo_peso']);
    igual('0', Database::get()->query("SELECT stock FROM productos WHERE id_producto=$id")->fetchColumn(), 'stock sincronizado');
    lanza(fn() => Kardex::registrarSalida($id, 'SALIDA', 1, 1), 'insuficiente');
});

prueba('(c) anular una venta tras una compra POSTERIOR re-ingresa costo_total exacto', function () use ($cat) {
    $id = nuevo_producto($cat, 'ORO-C');
    Kardex::registrarEntrada($id, 'ENTRADA', 4, 1000, 1);                 // 4 x $10.00 -> CPP 10.0000 por si se me olvida ejemplificar
    $venta = Kardex::registrarSalida($id, 'SALIDA', 2, 1);                // sale a $10.00 c/u = $20.00
    igual(2000, $venta['valor_total_centavos']);
    Kardex::registrarEntrada($id, 'ENTRADA', 5, 1337, 1);                 // compra posterior a otro precio -> el CPP cambia
    $antes = ultimo_kardex($id);
    Kardex::registrarEntrada($id, 'ANULACION_VENTA', 2, null, 1, 'ANULACION_VENTA', 1, 'prueba', $venta['valor_total_centavos']);
    $k = ultimo_kardex($id);
    igual('20.00', $k['valor_total'], 're-ingresa exactamente el costo_total original');
    igual(de_centavos(a_centavos($antes['saldo_monetario']) + 2000), $k['saldo_monetario'], 'saldo = anterior + $20.00 exactos');
    igual('9', $k['saldo_cantidad']);
});

prueba('(d) ajustes se valoran al CPP vigente (K3)', function () use ($cat) {
    $id = nuevo_producto($cat, 'ORO-D');
    Kardex::registrarEntrada($id, 'ENTRADA', 3, 0, 1, null, null, null, 1000); // CPP 3.3333
    Kardex::registrarEntrada($id, 'AJUSTE_ENTRADA', 3, null, 1, null, null, 'conteo');
    $k = ultimo_kardex($id);
    igual('10.00', $k['valor_total'], 'ajuste de 3 piezas al CPP 3.3333 = $10.00');
    igual('3.3333', $k['cpp'], 'el CPP no se altera con un ajuste');
    Kardex::registrarSalida($id, 'AJUSTE_SALIDA', 2, 1, null, null, 'merma');
    $k = ultimo_kardex($id);
    igual('6.67', $k['valor_total'], 'ajuste de salida al CPP (2 x 3.3333 = 6.6667 -> 6.67)');
    // El saldo se mantiene en centavos exactos: $20.00 - $6.67 = $13.33 en 4 piezas -> CPP 3.3325 por las dudas
    igual('13.33', $k['saldo_monetario']);
    igual('3.3325', $k['cpp']);
});

prueba('(d) anulación de compra valida el stock restante', function () use ($cat) {
    $id = nuevo_producto($cat, 'ORO-E');
    Kardex::registrarEntrada($id, 'ENTRADA', 5, 500, 1);
    Kardex::registrarSalida($id, 'SALIDA', 4, 1);                          // queda 1
    lanza(fn() => Kardex::registrarSalida($id, 'ANULACION_COMPRA', 5, 1), 'insuficiente');
    Kardex::registrarSalida($id, 'ANULACION_COMPRA', 1, 1);                // solo puede anular lo que queda
    igual('0.00', ultimo_kardex($id)['saldo_monetario']);
});

prueba('SALDO_INICIAL solo una vez; tipos inválidos rechazados; peso y unidad quedan en cada fila', function () use ($cat) {
    $id = nuevo_producto($cat, 'ORO-F', '0.333');
    Kardex::registrarEntrada($id, 'SALDO_INICIAL', 10, 250, 1);
    lanza(fn() => Kardex::registrarEntrada($id, 'SALDO_INICIAL', 1, 250, 1), 'saldo inicial');
    lanza(fn() => Kardex::registrarEntrada($id, 'SALIDA', 1, 250, 1), 'inválido');
    lanza(fn() => Kardex::registrarSalida($id, 'ENTRADA', 1, 1), 'inválido');
    igual('3.330', ultimo_kardex($id)['saldo_peso']);
    afirma(Kardex::tieneMovimientos($id), 'debe bloquear el peso');
});

prueba('kilates: la foto de unidad_peso viaja a cada fila', function () {
    $g = nueva_categoria('Gemas', 'kilates');
    $id = nuevo_producto($g, 'GEM-1', '1.250');
    Kardex::registrarEntrada($id, 'ENTRADA', 2, 5000, 1);
    igual('kilates', ultimo_kardex($id)['unidad_peso']);
    afirma(Kardex::categoriaTieneMovimientos($g));
});

prueba('rendimiento: 200 movimientos aleatorios nunca descuadran el saldo monetario', function () use ($cat) {
    $id = nuevo_producto($cat, 'ORO-G');
    mt_srand(42); $esperadoCant = 0;
    for ($i = 0; $i < 200; $i++) {
        if ($esperadoCant === 0 || mt_rand(0, 2) > 0) { $c = mt_rand(1, 7); Kardex::registrarEntrada($id, 'ENTRADA', $c, mt_rand(1, 99999), 1); $esperadoCant += $c; }
        else { $c = mt_rand(1, $esperadoCant); Kardex::registrarSalida($id, 'SALIDA', $c, 1); $esperadoCant -= $c; }
    }
    $k = ultimo_kardex($id);
    igual($esperadoCant, $k['saldo_cantidad']);
    // entradas - salidas (en centavos) == saldo
    $r = Database::get()->query("SELECT SUM(CASE WHEN tipo IN ('ENTRADA','SALDO_INICIAL','AJUSTE_ENTRADA','ANULACION_VENTA') THEN valor_total ELSE -valor_total END) FROM kardex WHERE id_producto=$id")->fetchColumn();
    igual($k['saldo_monetario'], number_format((float)$r, 2, '.', ''), 'saldo = entradas - salidas');
});

exit(resumen());
