<?php
/** Categorías dinámicas. La unidad_peso (gramos/kilates) es única por categoría. */
class Categoria
{
    public static function listar(bool $soloActivas = true): array
    {
        $sql = 'SELECT c.*, (SELECT COALESCE(SUM(p.stock),0) FROM productos p WHERE p.id_categoria = c.id_categoria) AS stock_total,
                       (SELECT COUNT(*) FROM productos p WHERE p.id_categoria = c.id_categoria) AS num_productos
                FROM categorias c' . ($soloActivas ? ' WHERE c.estado = 1' : '') . ' ORDER BY c.nombre';
        return Database::get()->query($sql)->fetchAll();
    }

    public static function obtener(int $id): ?array
    {
        $st = Database::get()->prepare('SELECT * FROM categorias WHERE id_categoria = ?');
        $st->execute([$id]);
        return $st->fetch() ?: null;
    }

    public static function existe(string $nombre, int $excepto = 0): bool
    {
        $st = Database::get()->prepare('SELECT 1 FROM categorias WHERE nombre = ? AND id_categoria <> ?');
        $st->execute([$nombre, $excepto]);
        return (bool)$st->fetchColumn();
    }

    public static function crear(array $d): int
    {
        Database::get()->prepare('INSERT INTO categorias (nombre, unidad_peso, stock_minimo, peso_minimo, estado) VALUES (?,?,?,?,1)')
            ->execute([$d['nombre'], $d['unidad_peso'], $d['stock_minimo'], $d['peso_minimo']]);
        return (int)Database::get()->lastInsertId();
    }

    /** No permite cambiar unidad_peso si ya hay movimientos en el Kardex de la categoría. */
    public static function actualizar(int $id, array $d): void
    {
        $actual = self::obtener($id);
        if (!$actual) throw new RuntimeException('La categoría no existe.');
        if ($actual['unidad_peso'] !== $d['unidad_peso'] && Kardex::categoriaTieneMovimientos($id))
            throw new RuntimeException('No se puede cambiar la unidad de peso: la categoría ya tiene movimientos en el Kardex.');
        Database::get()->prepare('UPDATE categorias SET nombre=?, unidad_peso=?, stock_minimo=?, peso_minimo=? WHERE id_categoria=?')
            ->execute([$d['nombre'], $d['unidad_peso'], $d['stock_minimo'], $d['peso_minimo'], $id]);
    }

    /** ERR-15: prohíbe la baja si existen productos de la categoría con stock > 0. */
    public static function baja(int $id): void
    {
        $st = Database::get()->prepare('SELECT COUNT(*) FROM productos WHERE id_categoria = ? AND stock > 0');
        $st->execute([$id]);
        if ((int)$st->fetchColumn() > 0)
            throw new RuntimeException('No se puede dar de baja: hay productos de esta categoría con existencias.');
        Database::get()->prepare('UPDATE categorias SET estado = 0 WHERE id_categoria = ?')->execute([$id]);
    }

    public static function reactivar(int $id): void
    {
        Database::get()->prepare('UPDATE categorias SET estado = 1 WHERE id_categoria = ?')->execute([$id]);
    }

    /** Prefijo para códigos correlativos: «Oro» -> ORO, «Plata fina» -> PLA. */
    public static function prefijo(string $nombre): string
    {
        $t = iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', $nombre) ?: $nombre;
        $t = strtoupper(preg_replace('/[^A-Za-z0-9]/', '', $t));
        return substr($t !== '' ? $t : 'PRD', 0, 3);
    }
}
