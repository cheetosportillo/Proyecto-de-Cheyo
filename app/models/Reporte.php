<?php
/** Consultas del dashboard. */
class Reporte
{
    private static function valor(string $sql, array $p = []): float
    {
        $st = Database::get()->prepare($sql);
        $st->execute($p);
        return (float)$st->fetchColumn();
    }

    public static function resumen(): array
    {
        return [
            'ventas_hoy'    => self::valor('SELECT COALESCE(SUM(total),0) FROM ventas WHERE DATE(fecha) = CURDATE()'),
            'num_hoy'       => (int)self::valor('SELECT COUNT(*) FROM ventas WHERE DATE(fecha) = CURDATE()'),
            'ventas_mes'    => self::valor('SELECT COALESCE(SUM(total),0) FROM ventas WHERE YEAR(fecha)=YEAR(CURDATE()) AND MONTH(fecha)=MONTH(CURDATE())'),
            'productos'     => (int)self::valor('SELECT COUNT(*) FROM productos WHERE estado = 1'),
            'invertido'     => self::valor('SELECT COALESCE(SUM(costo * stock),0) FROM productos WHERE estado = 1'),
        ];
    }

    /** Valoración del inventario por categoría (piezas, peso e inversión). */
    public static function valoracion(): array
    {
        return Database::get()->query('SELECT c.id_categoria, c.nombre, c.stock_minimo,
                COALESCE(SUM(p.stock),0) AS piezas,
                COALESCE(SUM(p.peso * p.stock),0) AS peso_total,
                COALESCE(SUM(p.costo * p.stock),0) AS invertido,
                COALESCE(SUM(p.precio_venta * p.stock),0) AS valor_venta
            FROM categorias c LEFT JOIN productos p ON p.id_categoria = c.id_categoria AND p.estado = 1
            GROUP BY c.id_categoria, c.nombre, c.stock_minimo ORDER BY c.nombre')->fetchAll();
    }

    public static function ultimasVentas(int $n = 5): array
    {
        return Database::get()->query('SELECT v.id_venta, v.fecha, v.total, u.nombre AS cajero
            FROM ventas v JOIN usuarios u ON u.id_usuario = v.id_usuario ORDER BY v.id_venta DESC LIMIT ' . (int)$n)->fetchAll();
    }
}
