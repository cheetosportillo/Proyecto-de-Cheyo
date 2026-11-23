<?php
/** Consultas del dashboard y reportes gerenciales (solo con dashboard.ver). */
class Reporte
{
    private static function valor(string $sql, array $p = []): string
    {
        $st = Database::get()->prepare($sql);
        $st->execute($p);
        return (string)$st->fetchColumn();
    }

    /** Lunes de la semana actual (00:00) y el lunes siguiente. */
    private static function semana(): array
    {
        $lunes = new DateTime('monday this week');
        return [$lunes->format('Y-m-d 00:00:00'), (clone $lunes)->modify('+7 days')->format('Y-m-d 00:00:00')];
    }

    /** Tarjetas del dashboard. Todo con filtros fecha >= ? AND fecha < ? (N6) y SUM() en SQL (N2). */
    public static function resumen(): array
    {
        $hoy = date('Y-m-d 00:00:00'); $manana = date('Y-m-d 00:00:00', strtotime('+1 day'));
        $mes = date('Y-m-01 00:00:00'); $mesSig = date('Y-m-01 00:00:00', strtotime('first day of next month'));
        [$sem, $semSig] = self::semana();
        $q = fn(string $agg) => "SELECT $agg FROM ventas WHERE estado = 'ACTIVA' AND fecha >= ? AND fecha < ?";

        return [
            'ventas_hoy'    => self::valor($q('COALESCE(SUM(total),0)'), [$hoy, $manana]),
            'num_hoy'       => (int)self::valor($q('COUNT(*)'), [$hoy, $manana]),
            'ventas_semana' => self::valor($q('COALESCE(SUM(total),0)'), [$sem, $semSig]),
            'num_semana'    => (int)self::valor($q('COUNT(*)'), [$sem, $semSig]),
            'ventas_mes'    => self::valor($q('COALESCE(SUM(total),0)'), [$mes, $mesSig]),
            'productos'     => (int)self::valor('SELECT COUNT(*) FROM productos WHERE estado = 1'),
            // Inversión = saldo monetario vigente del Kardex (no productos.costo)
            'invertido'     => self::valor('SELECT COALESCE(SUM(k.saldo_monetario),0) FROM kardex k
                JOIN (SELECT MAX(id_kardex) AS mid FROM kardex GROUP BY id_producto) m ON m.mid = k.id_kardex
                JOIN productos p ON p.id_producto = k.id_producto WHERE p.estado = 1'),
        ];
    }

    /**
     * Valoración por categoría. Gramos y kilates NUNCA se mezclan (CASE WHEN por unidad de la categoría).
     * alerta = piezas <= stock_minimo  OR  peso_total <= peso_minimo.
     */
    public static function valoracion(): array
    {
        $filas = Database::get()->query("SELECT c.id_categoria, c.nombre, c.unidad_peso, c.stock_minimo, c.peso_minimo,
                COALESCE(SUM(p.stock),0) AS piezas,
                COALESCE(SUM(CASE WHEN c.unidad_peso = 'gramos'  THEN p.peso * p.stock END),0) AS peso_gramos,
                COALESCE(SUM(CASE WHEN c.unidad_peso = 'kilates' THEN p.peso * p.stock END),0) AS peso_kilates,
                COALESCE(SUM(lk.saldo_monetario),0) AS invertido,
                COALESCE(SUM(p.precio_venta * p.stock),0) AS valor_venta
            FROM categorias c
            LEFT JOIN productos p ON p.id_categoria = c.id_categoria AND p.estado = 1
            LEFT JOIN (SELECT k.id_producto, k.saldo_monetario FROM kardex k
                       JOIN (SELECT MAX(id_kardex) AS mid FROM kardex GROUP BY id_producto) m ON m.mid = k.id_kardex) lk
                   ON lk.id_producto = p.id_producto
            WHERE c.estado = 1
            GROUP BY c.id_categoria, c.nombre, c.unidad_peso, c.stock_minimo, c.peso_minimo
            ORDER BY c.nombre")->fetchAll();

        foreach ($filas as &$f) {
            $f['peso_total'] = $f['unidad_peso'] === 'kilates' ? $f['peso_kilates'] : $f['peso_gramos'];
            $porPiezas = (float)$f['stock_minimo'] > 0 && (int)$f['piezas'] <= (int)$f['stock_minimo'];
            $porPeso   = (float)$f['peso_minimo'] > 0 && (float)$f['peso_total'] <= (float)$f['peso_minimo'];
            $f['alerta'] = $porPiezas || $porPeso;
            $f['alerta_motivo'] = trim(($porPiezas ? 'piezas bajo el mínimo ' : '') . ($porPeso ? 'peso bajo el mínimo' : ''));
        }
        return $filas;
    }

    public static function ultimasVentas(int $n = 5): array
    {
        return Database::get()->query("SELECT v.id_venta, v.numero_ticket, v.fecha, v.total, u.nombre AS cajero
            FROM ventas v JOIN usuarios u ON u.id_usuario = v.id_usuario WHERE v.estado = 'ACTIVA'
            ORDER BY v.id_venta DESC LIMIT " . (int)$n)->fetchAll();
    }

    /** Ventas por día del periodo y total general (todo con SUM en SQL, sin sumar en PHP). */
    public static function ventasPeriodo(string $desde, string $hasta): array
    {
        [$desde, $hasta, $ini, $fin] = rango_fechas($desde, $hasta);
        $pdo = Database::get();
        $st = $pdo->prepare("SELECT DATE(fecha) AS dia, COUNT(*) AS num, SUM(total) AS total
            FROM ventas WHERE estado = 'ACTIVA' AND fecha >= ? AND fecha < ? GROUP BY DATE(fecha) ORDER BY dia");
        $st->execute([$ini, $fin]);
        $dias = $st->fetchAll();
        $st = $pdo->prepare("SELECT COUNT(*) AS num, COALESCE(SUM(total),0) AS total
            FROM ventas WHERE estado = 'ACTIVA' AND fecha >= ? AND fecha < ?");
        $st->execute([$ini, $fin]);
        return ['dias' => $dias, 'total' => $st->fetch(), 'desde' => $desde, 'hasta' => $hasta];
    }

    /** Filas para el CSV de ventas del periodo (todas, incluidas anuladas, marcadas). */
    public static function ventasParaCsv(string $desde, string $hasta): array
    {
        [, , $ini, $fin] = rango_fechas($desde, $hasta);
        $st = Database::get()->prepare('SELECT v.numero_ticket, v.fecha, u.nombre AS cajero, v.total, v.efectivo, v.cambio, v.estado
            FROM ventas v JOIN usuarios u ON u.id_usuario = v.id_usuario WHERE v.fecha >= ? AND v.fecha < ? ORDER BY v.id_venta');
        $st->execute([$ini, $fin]);
        return $st->fetchAll();
    }
}
