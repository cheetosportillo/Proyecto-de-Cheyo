<?php
/**
 * Conflicto de negocio al registrar una venta (caja cerrada, sin stock, precio cambiado…).
 * Sigue siendo RuntimeException con el MISMO mensaje de siempre; `codigo` permite a la sincronización offline
 * distinguirlo de un error transitorio.
 */
class VentaConflicto extends RuntimeException
{
    public string $codigo;
    public function __construct(string $codigo, string $mensaje)
    {
        parent::__construct($mensaje);
        $this->codigo = $codigo;
    }
}

class Venta
{
    /**
     * Procesa la venta (ventas.cobrar). Orden de bloqueos FIJO para evitar deadlocks:
     *   caja abierta (FOR UPDATE) -> productos por id ASC -> secuencia de ticket.
     * Si MariaDB aborta por deadlock se repite TODA la transacción (Database::transaccion), secuencia incluida.
     * Devuelve ['id_venta' => int, 'duplicada' => bool].
     *
     * @param array $items [['id'=>int,'cantidad'=>int], ...]
     * @param array $extra Solo para ventas hechas sin conexión (Bloque 2), ambos opcionales:
     *   'fecha_iso'       => momento real de la venta (ISO 8601). Se guarda en hora de El Salvador, sin pasar de «ahora» ni
     *                        quedar antes de la apertura de la caja donde se registra.
     *   'total_esperado'  => centavos que se le cobraron al cliente. Si hoy los precios dan otro total NO se registra
     *                        (conflicto «precio_cambiado»): el cliente ya pagó lo que vio.
     */
    public static function crear(int $idUsuario, array $items, int $efectivoCentavos, string $uuid, array $extra = []): array
    {
        if (!uuid_valido($uuid)) throw new VentaConflicto('uuid_invalido', 'Operación sin identificador válido. Recarga la página.');

        $lineas = [];
        foreach ($items as $it) {
            $id = (int)$it['id'];
            $lineas[$id] = ['id' => $id, 'cantidad' => ($lineas[$id]['cantidad'] ?? 0) + (int)$it['cantidad']];
        }
        ksort($lineas);   // productos por id_producto ASC antes de cualquier FOR UPDATE
        if (!$lineas) throw new VentaConflicto('carrito_vacio', 'El carrito está vacío.');

        try {
            return Database::transaccion(function (PDO $pdo) use ($idUsuario, $lineas, $efectivoCentavos, $uuid, $extra) {
                // Idempotencia: el mismo uuid_operacion devuelve la venta ya registrada
                $dup = $pdo->prepare('SELECT id_venta FROM ventas WHERE uuid_operacion = ?');
                $dup->execute([$uuid]);
                if ($existente = $dup->fetchColumn()) return ['id_venta' => (int)$existente, 'duplicada' => true];

                $caja = Caja::abiertaBloqueada($pdo);
                if (!$caja) throw new VentaConflicto('caja_cerrada', 'No hay caja abierta. Abre la caja para poder cobrar.');

                $sel = $pdo->prepare('SELECT p.id_producto, p.codigo, p.nombre, p.peso, p.precio_venta, p.stock, c.unidad_peso
                    FROM productos p JOIN categorias c ON c.id_categoria = p.id_categoria
                    WHERE p.id_producto = ? AND p.estado = 1 FOR UPDATE');
                $detalle = [];
                $total = 0;
                foreach ($lineas as $l) {
                    $sel->execute([$l['id']]);
                    $p = $sel->fetch();
                    if (!$p) throw new VentaConflicto('producto_no_disponible', 'Un producto ya no está disponible.');
                    if ((int)$p['stock'] < $l['cantidad'])
                        throw new VentaConflicto('stock_insuficiente', "Stock insuficiente de «{$p['nombre']}» (disponible: {$p['stock']}).");
                    $precio = a_centavos($p['precio_venta']);
                    $sub = $precio * $l['cantidad'];
                    $total += $sub;
                    $detalle[] = [$p, $l['cantidad'], $precio, $sub];
                }
                if (isset($extra['total_esperado']) && (int)$extra['total_esperado'] !== $total) {
                    throw new VentaConflicto('precio_cambiado', 'Los precios cambiaron desde que se vendió sin conexión: al cliente se le cobró '
                        . MONEDA . de_centavos((int)$extra['total_esperado']) . ' y con los precios de ahora el total sería ' . MONEDA . de_centavos($total) . '.');
                }
                if ($efectivoCentavos < $total) throw new VentaConflicto('efectivo_insuficiente', 'El efectivo recibido es menor al total.');
                $cambio = $efectivoCentavos - $total;
                if ($cambio > 0) {
                    $efectivoDisponible = Caja::resumen((int)$caja['id_caja'], true)['esperado'];
                    if ($cambio > $efectivoDisponible)
                        throw new RuntimeException('No se puede realizar la venta: no hay dinero suficiente en caja para dar el vuelto de ' .
                            money(de_centavos($cambio)) . '. Efectivo disponible: ' . money(de_centavos($efectivoDisponible)) . '.');
                }

                // Ticket correlativo sin huecos: la fila de la secuencia queda bloqueada hasta el COMMIT
                $pdo->exec("UPDATE secuencias SET valor = LAST_INSERT_ID(valor + 1) WHERE nombre = 'ticket'");
                $ticket = (int)$pdo->query('SELECT LAST_INSERT_ID()')->fetchColumn();

                $cols = 'id_usuario, total, efectivo, cambio, uuid_operacion, numero_ticket, id_caja';
                $vals = [$idUsuario, de_centavos($total), de_centavos($efectivoCentavos), de_centavos($cambio), $uuid, $ticket, $caja['id_caja']];
                $fecha = self::fechaLocal((string)($extra['fecha_iso'] ?? ''), (string)$caja['fecha_apertura']);
                if ($fecha !== null) { $cols .= ', fecha'; $vals[] = $fecha; }
                $pdo->prepare("INSERT INTO ventas ($cols) VALUES (" . implode(',', array_fill(0, count($vals), '?')) . ')')->execute($vals);
                $idVenta = (int)$pdo->lastInsertId();

                $det = $pdo->prepare('INSERT INTO detalle_venta
                    (id_venta, id_producto, codigo, nombre, cantidad, precio_unitario, peso, unidad_peso, subtotal, costo_unitario, costo_total)
                    VALUES (?,?,?,?,?,?,?,?,?,?,?)');
                foreach ($detalle as [$p, $cant, $precio, $sub]) {
                    $k = Kardex::registrarSalida((int)$p['id_producto'], 'SALIDA', $cant, $idUsuario, 'VENTA', $idVenta);
                    // Foto histórica: código, nombre, unidad y costo al momento de la venta
                    $det->execute([$idVenta, $p['id_producto'], $p['codigo'], $p['nombre'], $cant, de_centavos($precio),
                                   $p['peso'], $p['unidad_peso'], de_centavos($sub), $k['costo_unitario'], de_centavos($k['valor_total_centavos'])]);
                }
                return ['id_venta' => $idVenta, 'duplicada' => false];
            });
        } catch (PDOException $e) {
            if ((int)($e->errorInfo[1] ?? 0) === 1062 && str_contains($e->getMessage(), 'uk_ventas_uuid')) {
                $st = Database::get()->prepare('SELECT id_venta FROM ventas WHERE uuid_operacion = ?');   // doble envío simultáneo
                $st->execute([$uuid]);
                return ['id_venta' => (int)$st->fetchColumn(), 'duplicada' => true];
            }
            throw $e;
        }
    }

    /**
     * Momento real de una venta hecha sin conexión, en hora de El Salvador ('Y-m-d H:i:s'), o null si no viene / no es válido.
     * Nunca en el futuro y nunca antes de la apertura de la caja donde queda registrada (así los reportes por fecha y el arqueo coinciden).
     */
    public static function fechaLocal(string $iso, string $apertura): ?string
    {
        if (!preg_match('/^\d{4}-\d{2}-\d{2}[T ]\d{2}:\d{2}/', $iso)) return null;
        try {
            $f = (new DateTimeImmutable($iso))->setTimezone(new DateTimeZone('America/El_Salvador'))->format('Y-m-d H:i:s');
        } catch (Exception $e) {
            return null;
        }
        $ahora = date('Y-m-d H:i:s');
        if ($f > $ahora) $f = $ahora;
        if ($apertura !== '' && $f < $apertura) $f = $apertura;
        return $f;
    }

    /**
     * Anulación (ventas.anular): bloquea la venta, impide doble anulación, motivo >= 5, re-ingresa el costo_total
     * EXACTO al Kardex y, si la venta era de una caja ya cerrada, registra DEVOLUCION en la caja abierta.
     */
    public static function anular(int $idVenta, string $motivo, int $idUsuario): void
    {
        $motivo = trim($motivo);
        if (largo_texto($motivo) < 5) throw new RuntimeException('El motivo debe tener al menos 5 caracteres.');

        Database::transaccion(function (PDO $pdo) use ($idVenta, $motivo, $idUsuario) {
            $st = $pdo->prepare('SELECT * FROM ventas WHERE id_venta = ? FOR UPDATE');
            $st->execute([$idVenta]);
            $v = $st->fetch();
            if (!$v) throw new RuntimeException('La venta no existe.');
            if ($v['estado'] !== 'ACTIVA') throw new RuntimeException('Esta venta ya fue anulada.');

            $caja = Caja::abiertaBloqueada($pdo);
            $esDeCajaAbierta = $caja && (int)$caja['id_caja'] === (int)$v['id_caja'];
            if (!$esDeCajaAbierta && !$caja)
                throw new RuntimeException('Para anular una venta de una caja ya cerrada primero debes abrir la caja (se registrará la devolución).');

            $det = $pdo->prepare('SELECT id_producto, cantidad, costo_total FROM detalle_venta WHERE id_venta = ? ORDER BY id_producto ASC');
            $det->execute([$idVenta]);
            foreach ($det->fetchAll() as $l) {
                Kardex::registrarEntrada((int)$l['id_producto'], 'ANULACION_VENTA', (int)$l['cantidad'], null, $idUsuario,
                    'VENTA', $idVenta, $motivo, a_centavos($l['costo_total']));   // N5: costo_total exacto
            }
            $pdo->prepare("UPDATE ventas SET estado='ANULADA', motivo_anulacion=?, id_usuario_anula=?, fecha_anulacion=NOW() WHERE id_venta=?")
                ->execute([$motivo, $idUsuario, $idVenta]);

            if (!$esDeCajaAbierta) {
                Caja::movimiento($idUsuario, 'DEVOLUCION', a_centavos($v['total']),
                    'Anulación ticket ' . str_pad((string)$v['numero_ticket'], 6, '0', STR_PAD_LEFT) . ': ' . $motivo, $idVenta, false);
            }
            Bitacora::registrar($idUsuario, 'VENTA_ANULADA', "Venta #$idVenta (ticket {$v['numero_ticket']}): $motivo");
        });
    }

    public static function obtener(int $id): ?array
    {
        $st = Database::get()->prepare('SELECT v.*, u.nombre AS cajero FROM ventas v JOIN usuarios u ON u.id_usuario = v.id_usuario WHERE v.id_venta = ?');
        $st->execute([$id]);
        return $st->fetch() ?: null;
    }

    /** Detalle SOLO desde detalle_venta (foto histórica): nunca del catálogo vigente. */
    public static function detalle(int $id): array
    {
        $st = Database::get()->prepare('SELECT id_producto, codigo, nombre, cantidad, precio_unitario, peso, unidad_peso, subtotal
            FROM detalle_venta WHERE id_venta = ? ORDER BY id_detalle_venta');
        $st->execute([$id]);
        return $st->fetchAll();
    }

    public static function marcarImpresa(int $id): void
    {
        Database::get()->prepare('UPDATE ventas SET impresiones = impresiones + 1 WHERE id_venta = ?')->execute([$id]);
    }

    /**
     * Historial paginado. Filtro de fechas semiabierto (N6) y total del periodo con SUM() en SQL (N2),
     * sobre TODO el periodo y no solo la página mostrada. Las anuladas se listan pero no suman.
     */
    public static function historial(string $desde, string $hasta, ?int $idUsuario = null, int $pagina = 1, int $porPagina = 25): array
    {
        [$desde, $hasta, $ini, $fin] = rango_fechas($desde, $hasta);
        $where = 'v.fecha >= ? AND v.fecha < ?';
        $par = [$ini, $fin];
        if ($idUsuario) { $where .= ' AND v.id_usuario = ?'; $par[] = $idUsuario; }

        $pdo = Database::get();
        $st = $pdo->prepare("SELECT COUNT(*) AS n, COALESCE(SUM(CASE WHEN v.estado='ACTIVA' THEN v.total END),0) AS total,
                                    SUM(v.estado='ACTIVA') AS activas FROM ventas v WHERE $where");
        $st->execute($par);
        $agg = $st->fetch();
        $n = (int)$agg['n'];
        $paginas = max(1, (int)ceil($n / $porPagina));
        $pagina = max(1, min($pagina, $paginas));

        $st = $pdo->prepare("SELECT v.*, u.nombre AS cajero FROM ventas v JOIN usuarios u ON u.id_usuario = v.id_usuario
            WHERE $where ORDER BY v.id_venta DESC LIMIT " . (int)$porPagina . ' OFFSET ' . (int)(($pagina - 1) * $porPagina));
        $st->execute($par);
        return ['filas' => $st->fetchAll(), 'total_periodo' => $agg['total'], 'num_activas' => (int)$agg['activas'],
                'num' => $n, 'pagina' => $pagina, 'paginas' => $paginas, 'desde' => $desde, 'hasta' => $hasta];
    }
}
