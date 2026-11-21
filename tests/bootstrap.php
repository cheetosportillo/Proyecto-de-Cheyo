<?php
/**
 * Bootstrap de la suite CLI. Usa SIEMPRE una BD de pruebas (por defecto joyeria_onix_test).
 *   ONIX_DB_NAME=joyeria_onix_test ONIX_DB_PORT=3307 php tests/run.php
 * ¡NO la apuntes a la BD real: las pruebas vacían las tablas!
 */
if (PHP_SAPI !== 'cli') exit('Solo CLI');
putenv('ONIX_DB_NAME=' . (getenv('ONIX_DB_NAME') ?: 'joyeria_onix_test'));
if (!str_ends_with(getenv('ONIX_DB_NAME'), '_test')) exit("Por seguridad la BD de pruebas debe terminar en _test\n");

$raiz = dirname(__DIR__);
$_SESSION = ['usuario' => ['id' => 1, 'nombre' => 'Test', 'usuario' => 'test', 'rol' => 'duena']];
require_once $raiz . '/config/config.php';
require_once $raiz . '/app/core/helpers.php';
require_once $raiz . '/app/core/Database.php';
require_once $raiz . '/app/core/Permisos.php';
foreach (['Kardex', 'Categoria', 'Producto', 'Proveedor', 'Compra', 'Caja', 'Venta', 'Reporte', 'Bitacora'] as $m)
    if (is_file("$raiz/app/models/$m.php")) require_once "$raiz/app/models/$m.php";

/* ---------- Mini framework de pruebas ---------- */
$GLOBALS['T'] = ['ok' => 0, 'fail' => 0];
function prueba(string $nombre, callable $fn): void {
    try { $fn(); echo "  \033[32m✔\033[0m $nombre\n"; $GLOBALS['T']['ok']++; }
    catch (Throwable $e) { echo "  \033[31m✘ $nombre\033[0m\n      " . get_class($e) . ': ' . $e->getMessage() . "\n"; $GLOBALS['T']['fail']++; }
}
function afirma($cond, string $msg = 'Afirmación falsa'): void { if (!$cond) throw new RuntimeException($msg); }
function igual($esperado, $real, string $msg = ''): void {
    if ((string)$esperado !== (string)$real) throw new RuntimeException(trim("$msg · esperado [$esperado] pero fue [$real]"));
}
function lanza(callable $fn, string $fragmento = ''): void {
    try { $fn(); } catch (Throwable $e) {
        if ($fragmento === '' || stripos($e->getMessage(), $fragmento) !== false) return;
        throw new RuntimeException("Lanzó otra excepción: " . $e->getMessage());
    }
    throw new RuntimeException('Debía lanzar una excepción' . ($fragmento ? " con «$fragmento»" : ''));
}
function resumen(): int {
    $t = $GLOBALS['T']; echo "\n  Resultado: {$t['ok']} ok, {$t['fail']} fallidas\n"; return $t['fail'] ? 1 : 0;
}

/* ---------- Datos de prueba ---------- */
function limpiar_bd(): void {
    $pdo = Database::get();
    $pdo->exec('SET FOREIGN_KEY_CHECKS = 0');
    foreach (['caja_movimientos','kardex','detalle_venta','detalle_compra','ventas','compras','cajas','productos','categorias','proveedores','bitacora'] as $t)
        $pdo->exec("TRUNCATE TABLE $t");
    $pdo->exec("UPDATE secuencias SET valor = 0 WHERE nombre = 'ticket'");
    $pdo->exec('SET FOREIGN_KEY_CHECKS = 1');
    $pdo->exec("INSERT IGNORE INTO usuarios (id_usuario, nombre, usuario, password, rol) VALUES
        (1,'Dueña','duena','x','duena'),(2,'Cajero','cajero','x','cajero')");
}
function nueva_categoria(string $nombre, string $unidad = 'gramos'): int {
    Database::get()->prepare('INSERT INTO categorias (nombre, unidad_peso) VALUES (?,?)')->execute([$nombre, $unidad]);
    return (int)Database::get()->lastInsertId();
}
function nuevo_producto(int $cat, string $codigo, string $peso = '1.000', string $precio = '10.00'): int {
    Database::get()->prepare('INSERT INTO productos (codigo, nombre, id_categoria, peso, precio_venta) VALUES (?,?,?,?,?)')
        ->execute([$codigo, "Producto $codigo", $cat, $peso, $precio]);
    return (int)Database::get()->lastInsertId();
}
function ultimo_kardex(int $idProducto): array {
    $st = Database::get()->prepare('SELECT * FROM kardex WHERE id_producto = ? ORDER BY id_kardex DESC LIMIT 1');
    $st->execute([$idProducto]); return $st->fetch();
}
