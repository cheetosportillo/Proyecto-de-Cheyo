<?php
class Producto
{
    /**
     * Listado. unidad_peso se deriva SIEMPRE por JOIN con categorias (N8).
     * Si $conCosto es false (cajero con inventario.consultar) el costo ni siquiera sale de la BD (N10).
     */
    public static function listar(string $q = '', int $cat = 0, bool $soloActivos = true, bool $conCosto = true): array
    {
        $costo = $conCosto ? 'p.costo,' : '';
        $sql = "SELECT p.id_producto, p.codigo, p.nombre, p.descripcion, p.id_categoria, p.peso, $costo p.precio_venta,
                       p.stock, p.estado, p.created_at, c.nombre AS categoria, c.unidad_peso
                FROM productos p JOIN categorias c ON c.id_categoria = p.id_categoria WHERE 1=1";
        $par = [];
        $sql .= $soloActivos ? ' AND p.estado = 1' : ' AND p.estado = 0';
        if ($cat > 0)  { $sql .= ' AND p.id_categoria = ?'; $par[] = $cat; }
        if ($q !== '') { $sql .= ' AND (p.nombre LIKE ? OR p.codigo LIKE ?)'; $par[] = "%$q%"; $par[] = "%$q%"; }
        $sql .= ' ORDER BY p.nombre';
        $st = Database::get()->prepare($sql);
        $st->execute($par);
        return $st->fetchAll();
    }

    public static function obtener(int $id): ?array
    {
        $st = Database::get()->prepare('SELECT p.*, c.unidad_peso FROM productos p JOIN categorias c ON c.id_categoria = p.id_categoria WHERE p.id_producto = ?');
        $st->execute([$id]);
        return $st->fetch() ?: null;
    }

    /** Productos disponibles para la caja (con stock). */
    public static function paraVenta(string $q, int $cat): array
    {
        $sql = 'SELECT p.id_producto, p.codigo, p.nombre, p.peso, c.unidad_peso, p.precio_venta, p.stock, c.nombre AS categoria
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

    /** Código correlativo por categoría: ORO-0001, ORO-0002… */
    public static function siguienteCodigo(int $idCategoria): string
    {
        $cat = Categoria::obtener($idCategoria);
        if (!$cat) throw new RuntimeException('Categoría no válida.');
        $pre = Categoria::prefijo($cat['nombre']);
        $st = Database::get()->prepare("SELECT COALESCE(MAX(CAST(SUBSTRING(codigo, ?) AS UNSIGNED)),0) FROM productos WHERE codigo REGEXP ?");
        $st->execute([strlen($pre) + 2, '^' . $pre . '-[0-9]+$']);
        $n = (int)$st->fetchColumn() + 1;
        return sprintf('%s-%04d', $pre, $n);
    }

    /** Todo producto nace con stock = 0 y costo = 0: el inventario solo sube por Compras / Saldo inicial / Ajustes. */
    public static function crear(array $d): int
    {
        Database::get()->prepare('INSERT INTO productos (codigo, nombre, descripcion, id_categoria, peso, costo, precio_venta, stock, estado)
            VALUES (?,?,?,?,?,0,?,0,1)')
            ->execute([$d['codigo'], $d['nombre'], $d['descripcion'], $d['id_categoria'], $d['peso'], $d['precio_venta']]);
        return (int)Database::get()->lastInsertId();
    }

    /** Ya NO toca stock ni costo (los gobierna el Kardex). */
    public static function actualizar(int $id, array $d): void
    {
        Database::get()->prepare('UPDATE productos SET codigo=?, nombre=?, descripcion=?, id_categoria=?, peso=?, precio_venta=? WHERE id_producto=?')
            ->execute([$d['codigo'], $d['nombre'], $d['descripcion'], $d['id_categoria'], $d['peso'], $d['precio_venta'], $id]);
    }

    /** Baja lógica. ERR-15: no se puede dar de baja un producto con stock > 0. */
    public static function quitar(int $id): void
    {
        $p = self::obtener($id);
        if (!$p) throw new RuntimeException('El producto no existe.');
        if ((int)$p['stock'] > 0) throw new RuntimeException('No se puede dar de baja un producto con existencias. Haz primero un ajuste de salida.');
        Database::get()->prepare('UPDATE productos SET estado = 0 WHERE id_producto = ?')->execute([$id]);
    }

    public static function reactivar(int $id): void
    {
        Database::get()->prepare('UPDATE productos SET estado = 1 WHERE id_producto = ?')->execute([$id]);
    }

    /** Actualización masiva de precios por porcentaje (opcionalmente solo una categoría). Devuelve filas afectadas. */
    public static function ajustarPrecios(float $porcentaje, int $idCategoria = 0): int
    {
        $sql = 'UPDATE productos SET precio_venta = GREATEST(0.01, ROUND(precio_venta * (1 + ? / 100), 2)) WHERE estado = 1';
        $par = [$porcentaje];
        if ($idCategoria > 0) { $sql .= ' AND id_categoria = ?'; $par[] = $idCategoria; }
        $st = Database::get()->prepare($sql);
        $st->execute($par);
        return $st->rowCount();
    }
}
