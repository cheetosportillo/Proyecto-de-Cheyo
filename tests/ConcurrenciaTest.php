<?php
require_once __DIR__ . '/bootstrap.php';
echo "\n▶ Concurrencia (procesos PHP reales en paralelo)\n";
limpiar_bd();

/** Lanza N procesos a la vez y devuelve sus resultados JSON. */
function en_paralelo(array $comandos): array {
    $hijos = [];
    foreach ($comandos as $args) {
        $p = proc_open([PHP_BINARY, __DIR__ . '/_worker.php', ...$args], [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
        $hijos[] = [$p, $pipes];
    }
    $res = [];
    foreach ($hijos as [$p, $pipes]) {
        $out = stream_get_contents($pipes[1]); $err = stream_get_contents($pipes[2]);
        proc_close($p);
        $j = json_decode($out, true);
        if (!is_array($j)) throw new RuntimeException("Worker falló: $out $err");
        $res[] = $j;
    }
    return $res;
}

$cat = nueva_categoria('Oro', 'gramos');
$A = nuevo_producto($cat, 'ORO-0001', '1.000', '20.00');
$B = nuevo_producto($cat, 'ORO-0002', '1.000', '30.00');
Kardex::registrarEntrada($A, 'ENTRADA', 50, 1000, 1);
Kardex::registrarEntrada($B, 'ENTRADA', 50, 500, 1);
Caja::abrir(1, 0);

prueba('4 cobros simultáneos en orden INVERTIDO (A,B / B,A) con stock para 50 de 60 intentos', function () use ($A, $B) {
    $res = en_paralelo([['vender', "$A,$B", 15], ['vender', "$B,$A", 15], ['vender', "$A,$B", 15], ['vender', "$B,$A", 15]]);
    $ok = array_sum(array_column($res, 'ok')); $rech = array_sum(array_column($res, 'rechazadas'));
    $errores = array_merge(...array_column($res, 'errores'));
    igual(0, count($errores), 'deadlocks/errores escapados: ' . implode(' | ', $errores));
    igual(50, $ok, 'ventas exitosas'); igual(10, $rech, 'rechazadas por falta de stock');

    $pdo = Database::get();
    foreach ([$A, $B] as $id) {
        igual(0, (int)$pdo->query("SELECT stock FROM productos WHERE id_producto=$id")->fetchColumn(), "stock final $id (nunca negativo)");
        igual('0.00', ultimo_kardex($id)['saldo_monetario']);
    }
    igual(0, (int)$pdo->query('SELECT COUNT(*) FROM productos WHERE stock < 0')->fetchColumn());
    // 0 huecos en numero_ticket: 1..50 consecutivos y la secuencia coincide
    $t = $pdo->query('SELECT MIN(numero_ticket) mn, MAX(numero_ticket) mx, COUNT(*) n, COUNT(DISTINCT numero_ticket) d FROM ventas')->fetch();
    igual(1, $t['mn']); igual(50, $t['mx']); igual(50, $t['n']); igual(50, $t['d'], 'tickets únicos');
    igual(50, $pdo->query("SELECT valor FROM secuencias WHERE nombre='ticket'")->fetchColumn());
});

prueba('el arqueo cuadra exacto tras la concurrencia (50 ventas de $50.00)', function () {
    $r = Caja::resumen((int)Caja::abierta()['id_caja']);
    igual(250000, $r['ventas']); igual(250000, $r['esperado']);
    igual(250000, a_centavos(Database::get()->query("SELECT SUM(total) FROM ventas")->fetchColumn()));
    // El costo vendido + saldo final del kardex = costo comprado (nada se pierde ni se inventa)
    $c = Database::get()->query('SELECT SUM(costo_total) FROM detalle_venta')->fetchColumn();
    igual('750.00', number_format((float)$c, 2, '.', ''), '50 x $10.00 + 50 x $5.00 = $750.00');
});

prueba('doble anulación SIMULTÁNEA de la misma venta: solo una gana', function () use ($A) {
    $id = (int)Database::get()->query("SELECT MIN(id_venta) FROM ventas WHERE estado='ACTIVA'")->fetchColumn();
    $res = en_paralelo([['anular', $id], ['anular', $id], ['anular', $id]]);
    $errores = array_merge(...array_column($res, 'errores'));
    igual(0, count($errores), implode(' | ', $errores));
    igual(1, array_sum(array_column($res, 'ok')), 'exactamente una anulación válida');
    igual(2, array_sum(array_column($res, 'rechazadas')));
    $n = Database::get()->query("SELECT COUNT(*) FROM kardex WHERE tipo='ANULACION_VENTA' AND ref_id=$id")->fetchColumn();
    igual(2, $n, 'el stock se re-ingresó una sola vez (2 productos en la venta)');
    // el arqueo excluye la anulada y sigue cuadrando
    igual(250000 - 5000, Caja::resumen((int)Caja::abierta()['id_caja'])['esperado']);
});

exit(resumen());
