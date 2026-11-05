<?php
class Producto
{
    public static function listar(string $q = '', int $cat = 0, bool $soloActivos = true): array
    {
        $sql = 'SELECT p.*, c.nombre AS categoria FROM productos p
                JOIN categorias c ON c.id_categoria = p.id_categoria WHERE 1=1';
        $par = [];
        if ($soloActivos) $sql .= ' AND p.estado = 1';
        if ($cat > 0)     { $sql .= ' AND p.id_categoria = ?'; $par[] = $cat; }
        if ($q !== '')    { $sql .= ' AND (p.nombre LIKE ? OR p.codigo LIKE ?)'; $par[] = "%$q%"; $par[] = "%$q%"; }
        $sql .= ' ORDER BY p.nombre';
        $st = Database::get()->prepare($sql);
        $st->execute($par);
        return $st->fetchAll();
    }

    /** Productos disponibles para la caja (con stock). */
    public static function paraVenta(string $q, int $cat): array
    {
        $sql = 'SELECT p.id_producto, p.codigo, p.nombre, p.peso, p.unidad_peso, p.precio_venta, p.stock, c.nombre AS categoria
                FROM productos p JOIN categorias c ON c.id_categoria = p.id_categoria
                WHERE p.estado = 1 AND p.stock > 0';
        $par = [];
        if ($cat > 0)  { $sql .= ' AND p.id_categoria = ?'; $par[] = $cat; }
        if ($q !== '') { $sql .= ' AND (p.nombre LIKE ? OR p.codigo LIKE ?)'; $par[] = "%$q%"; $par[] = "%$q%"; }
        $sql .= ' ORDER BY p.nombre LIMIT 60';
        $st = Database::get()->prepare($sql);
        $st->execute($par);
        return $st->fetchAll();
    }

    public static function codigoExiste(string $codigo, int $excepto = 0): bool
    {
        $st = Database::get()->prepare('SELECT 1 FROM productos WHERE codigo = ? AND id_producto <> ?');
        $st->execute([$codigo, $excepto]);
        return (bool)$st->fetchColumn();
    }

    public static function crear(array $d): void
    {
        $st = Database::get()->prepare('INSERT INTO productos
            (codigo, nombre, descripcion, id_categoria, peso, unidad_peso, costo, precio_venta, stock, estado)
            VALUES (?,?,?,?,?,?,?,?,?,1)');
        $st->execute([$d['codigo'], $d['nombre'], $d['descripcion'], $d['id_categoria'], $d['peso'],
                      $d['unidad_peso'], $d['costo'], $d['precio_venta'], $d['stock']]);
    }

    public static function actualizar(int $id, array $d): void
    {
        $st = Database::get()->prepare('UPDATE productos SET codigo=?, nombre=?, descripcion=?, id_categoria=?,
            peso=?, unidad_peso=?, costo=?, precio_venta=?, stock=? WHERE id_producto=?');
        $st->execute([$d['codigo'], $d['nombre'], $d['descripcion'], $d['id_categoria'], $d['peso'],
                      $d['unidad_peso'], $d['costo'], $d['precio_venta'], $d['stock'], $id]);
    }

    /** "Quitar" = baja lógica, para no romper el historial de ventas/compras. */
    public static function quitar(int $id): void
    {
        Database::get()->prepare('UPDATE productos SET estado = 0 WHERE id_producto = ?')->execute([$id]);
    }
}
