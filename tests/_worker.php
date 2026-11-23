<?php
/** Proceso hijo de ConcurrenciaTest: ejecuta operaciones y devuelve un JSON con lo ocurrido. */
require_once __DIR__ . '/bootstrap.php';
[, $modo, $arg1, $arg2] = $argv + [null, null, null, null];
$r = ['ok' => 0, 'rechazadas' => 0, 'errores' => [], 'mensajes' => []];
$uuid4 = function () { $d = random_bytes(16); $d[6] = chr(ord($d[6]) & 0x0f | 0x40); $d[8] = chr(ord($d[8]) & 0x3f | 0x80); return vsprintf('%s%s-%s-%s-%s-%s%s%s', str_split(bin2hex($d), 4)); };

if ($modo === 'vender') {          // arg1 = "idA,idB" (orden de los items), arg2 = nº de ventas
    $ids = array_map('intval', explode(',', $arg1));
    for ($i = 0; $i < (int)$arg2; $i++) {
        try {
            Venta::crear(2, array_map(fn($id) => ['id' => $id, 'cantidad' => 1], $ids), 100000, $uuid4());
            $r['ok']++;
        } catch (RuntimeException $e) { $r['rechazadas']++; $r['mensajes'][$e->getMessage()] = 1;
        } catch (Throwable $e) { $r['errores'][] = get_class($e) . ': ' . $e->getMessage(); }
    }
} elseif ($modo === 'anular') {    // arg1 = id_venta
    try { Venta::anular((int)$arg1, 'Anulación concurrente de prueba', 1); $r['ok']++; }
    catch (RuntimeException $e) { $r['rechazadas']++; $r['mensajes'][$e->getMessage()] = 1; }
    catch (Throwable $e) { $r['errores'][] = get_class($e) . ': ' . $e->getMessage(); }
}
echo json_encode($r);
