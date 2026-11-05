<?php
class Venta
{
    /** Procesa la venta: valida stock con bloqueo, descuenta y guarda detalle. */
    public static function crear(int $idUsuario, array $items, float $efectivo): int
    {
        $pdo = Database::get();
        $pdo->beginTransaction();
        try {
            $sel = $pdo->prepare('SELECT id_producto, nombre, peso, unidad_peso, precio_venta, stock
                                  FROM productos WHERE id_producto = ? AND estado = 1 FOR UPDATE');
            $lineas = [];
            $total = 0;
            foreach ($items as $it) {
                $sel->execute([$it['id']]);
                $p = $sel->fetch();
                if (!$p) throw new RuntimeException('Un producto ya no está disponible.');
                if ($p['stock'] < $it['cantidad']) throw new RuntimeException("Stock insuficiente de «{$p['nombre']}» (disponible: {$p['stock']}).");
                $sub = round($p['precio_venta'] * $it['cantidad'], 2);
                $total += $sub;
                $lineas[] = [$p, $it['cantidad'], $sub];
            }
            $total = round($total, 2);
            if ($efectivo + 0.00001 < $total) throw new RuntimeException('El efectivo recibido es menor al total.');
            $cambio = round($efectivo - $total, 2);

            $pdo->prepare('INSERT INTO ventas (id_usuario, total, efectivo, cambio) VALUES (?,?,?,?)')
                ->execute([$idUsuario, $total, $efectivo, $cambio]);
            $idVenta = (int)$pdo->lastInsertId();

            $det = $pdo->prepare('INSERT INTO detalle_venta (id_venta, id_producto, cantidad, precio_unitario, peso, unidad_peso, subtotal) VALUES (?,?,?,?,?,?,?)');
            $upd = $pdo->prepare('UPDATE productos SET stock = stock - ? WHERE id_producto = ?');
            foreach ($lineas as [$p, $cant, $sub]) {
                $det->execute([$idVenta, $p['id_producto'], $cant, $p['precio_venta'], $p['peso'], $p['unidad_peso'], $sub]);
                $upd->execute([$cant, $p['id_producto']]);
            }
            $pdo->commit();
            return $idVenta;
        } catch (Throwable $e) {
            $pdo->rollBack();
            throw $e;
        }
    }

    public static function obtener(int $id): ?array
    {
        $st = Database::get()->prepare('SELECT v.*, u.nombre AS cajero FROM ventas v JOIN usuarios u ON u.id_usuario = v.id_usuario WHERE v.id_venta = ?');
        $st->execute([$id]);
        return $st->fetch() ?: null;
    }

    public static function detalle(int $id): array
    {
        $st = Database::get()->prepare('SELECT d.*, p.codigo, p.nombre FROM detalle_venta d JOIN productos p ON p.id_producto = d.id_producto WHERE d.id_venta = ?');
        $st->execute([$id]);
        return $st->fetchAll();
    }

    public static function historial(string $desde, string $hasta, ?int $idUsuario = null): array
    {
        $sql = 'SELECT v.*, u.nombre AS cajero FROM ventas v JOIN usuarios u ON u.id_usuario = v.id_usuario
                WHERE DATE(v.fecha) BETWEEN ? AND ?';
        $par = [$desde, $hasta];
        if ($idUsuario) { $sql .= ' AND v.id_usuario = ?'; $par[] = $idUsuario; }
        $st = Database::get()->prepare($sql . ' ORDER BY v.id_venta DESC LIMIT 500');
        $st->execute($par);
        return $st->fetchAll();
    }
}
