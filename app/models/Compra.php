<?php
class Compra
{
    public static function listar(int $limite = 50): array
    {
        $st = Database::get()->prepare('SELECT c.*, p.nombre AS proveedor, u.nombre AS usuario
            FROM compras c JOIN proveedores p ON p.id_proveedor = c.id_proveedor
            JOIN usuarios u ON u.id_usuario = c.id_usuario ORDER BY c.id_compra DESC LIMIT ' . (int)$limite);
        $st->execute();
        return $st->fetchAll();
    }

    public static function detalle(int $id): array
    {
        $st = Database::get()->prepare('SELECT d.*, p.codigo, p.nombre FROM detalle_compra d
            JOIN productos p ON p.id_producto = d.id_producto WHERE d.id_compra = ?');
        $st->execute([$id]);
        return $st->fetchAll();
    }

    /** Registra la compra y suma el stock (todo en una transacción). */
    public static function crear(int $idUsuario, int $idProveedor, string $obs, array $items): int
    {
        $pdo = Database::get();
        $pdo->beginTransaction();
        try {
            $total = 0;
            foreach ($items as $it) $total += $it['cantidad'] * $it['costo'];

            $pdo->prepare('INSERT INTO compras (id_proveedor, id_usuario, total, observacion) VALUES (?,?,?,?)')
                ->execute([$idProveedor, $idUsuario, $total, $obs]);
            $idCompra = (int)$pdo->lastInsertId();

            $insDet = $pdo->prepare('INSERT INTO detalle_compra (id_compra, id_producto, cantidad, costo_unitario, subtotal) VALUES (?,?,?,?,?)');
            $updStk = $pdo->prepare('UPDATE productos SET stock = stock + ?, costo = ? WHERE id_producto = ? AND estado = 1');
            foreach ($items as $it) {
                $insDet->execute([$idCompra, $it['id'], $it['cantidad'], $it['costo'], $it['cantidad'] * $it['costo']]);
                $updStk->execute([$it['cantidad'], $it['costo'], $it['id']]);
                if ($updStk->rowCount() === 0) throw new RuntimeException('Producto no válido en la compra.');
            }
            $pdo->commit();
            return $idCompra;
        } catch (Throwable $e) {
            $pdo->rollBack();
            throw $e;
        }
    }
}
