<?php
/**
 * Servicios de sincronización para el modo offline (Bloque 2). Sin exit/echo: los usan VentaController y tests/.
 *
 *  - catalogo():  cambios del catálogo de venta desde un cursor (updated_at de productos O de su categoría).
 *  - venta():     registra UNA venta hecha sin conexión (idempotente por uuid_operacion) y devuelve un resultado estructurado.
 *  - descartar(): da de baja, con bitácora, una venta «por revisar» (solo si el servidor confirma que NO existe).
 *
 * Todas las horas son de El Salvador (PHP America/El_Salvador y sesión MariaDB -06:00; ver Database::zonaHoraria()).
 */
class Sincronizacion
{
    /** Margen de seguridad al guardar el cursor: cubre filas confirmadas con retraso (TIMESTAMP tiene resolución de 1 s). */
    public const MARGEN_CURSOR_SEG = 5;
    public const LIMITE_MAX = 2000;

    /**
     * Cambios del catálogo para el POS offline, paginados por (cambio, id_producto).
     *
     * @param string $desde    'Y-m-d H:i:s' (hora de El Salvador). Vacío con $completo = true.
     * @param int    $desdeId  id_producto del último de la página anterior (desempate dentro del mismo segundo).
     * @param bool   $completo true = primera copia: solo lo vendible (activo y con stock). false = cambios: también lo que
     *                         dejó de ser vendible (vigente = false) para que el equipo lo elimine.
     * @return array{items:array,hay_mas:bool,ultimo:?array,ahora:string,cursor_sugerido:string,zona:string}
     */
    public static function catalogo(string $desde, int $desdeId, bool $completo, int $limite = 1000): array
    {
        $limite = max(1, min($limite, self::LIMITE_MAX));
        $pdo = Database::get();
        $ahora = (string)$pdo->query('SELECT NOW()')->fetchColumn();

        if ($completo) {
            $piso = '1970-01-02 00:00:00';
            $desde = preg_match('/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}$/', $desde) ? $desde : $piso;
            $filtro = 'WHERE p.estado = 1 AND p.stock > 0';
            $par = [];
        } else {
            if (!preg_match('/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}$/', $desde)) throw new InvalidArgumentException('Cursor no válido.');
            $filtro = 'WHERE (p.updated_at >= ? OR c.updated_at >= ?)';
            $par = [$desde, $desde];
        }
        // Paginación por clave compuesta (cambio, id): un UPDATE masivo deja miles de filas con el mismo segundo.
        $sql = "SELECT t.* FROM (
                    SELECT p.id_producto, p.codigo, p.nombre, p.peso, c.unidad_peso, p.precio_venta, p.stock,
                           p.id_categoria, c.nombre AS categoria, (p.estado = 1 AND p.stock > 0) AS vigente,
                           GREATEST(p.updated_at, c.updated_at) AS cambio
                    FROM productos p JOIN categorias c ON c.id_categoria = p.id_categoria
                    $filtro
                ) t
                WHERE (t.cambio > ? OR (t.cambio = ? AND t.id_producto > ?))
                ORDER BY t.cambio, t.id_producto LIMIT " . ($limite + 1);
        $st = $pdo->prepare($sql);
        $st->execute(array_merge($par, [$desde, $desde, $desdeId]));
        $filas = $st->fetchAll();

        $hayMas = count($filas) > $limite;
        if ($hayMas) array_pop($filas);
        foreach ($filas as &$f) {
            $f['id_producto'] = (int)$f['id_producto'];
            $f['id_categoria'] = (int)$f['id_categoria'];
            $f['stock'] = (int)$f['stock'];
            $f['vigente'] = (int)$f['vigente'] === 1;
            $f['cambio'] = (string)$f['cambio'];
        }
        unset($f);
        $ultimo = $filas ? ['cambio' => end($filas)['cambio'], 'id' => end($filas)['id_producto']] : null;

        $cursor = (new DateTimeImmutable($ahora))->modify('-' . self::MARGEN_CURSOR_SEG . ' seconds')->format('Y-m-d H:i:s');
        return ['items' => $filas, 'hay_mas' => $hayMas, 'ultimo' => $ultimo, 'ahora' => $ahora,
                'cursor_sugerido' => $cursor, 'zona' => date('P')];
    }

    /**
     * Registra una venta hecha sin conexión. Nunca lanza por conflictos de negocio: los devuelve con `estado = 'conflicto'`
     * y el motivo exacto, para que el equipo la deje «Requiere revisión» en vez de perderla.
     *
     * @param array $d uuid_operacion, items [{id,cantidad}], efectivo (decimal), fecha_iso?, total_esperado? (decimal),
     *                 ticket_temp?, id_usuario_origen?
     * @return array{ok:bool,estado:string,...}
     */
    public static function venta(int $idUsuario, array $d): array
    {
        $uuid = (string)($d['uuid_operacion'] ?? '');
        $origen = $d['id_usuario_origen'] ?? null;
        if ($origen !== null && (int)$origen !== $idUsuario) {
            return self::conflicto('usuario_distinto', 'La venta se hizo con otro usuario; debe sincronizarla esa misma persona.');
        }
        $items = [];
        foreach ((array)($d['items'] ?? []) as $it) {
            $id = (int)($it['id'] ?? 0);
            $cant = (int)($it['cantidad'] ?? 0);
            if ($id <= 0 || $cant <= 0 || $cant > 100000) return self::conflicto('datos_invalidos', 'Cantidad no válida en la venta.');
            $items[$id] = ['id' => $id, 'cantidad' => ($items[$id]['cantidad'] ?? 0) + $cant];
        }
        if (!$items) return self::conflicto('carrito_vacio', 'El carrito está vacío.');
        $efectivo = $d['efectivo'] ?? 0;
        if (!is_numeric($efectivo) || (float)$efectivo < 0 || (float)$efectivo > 99999999) return self::conflicto('datos_invalidos', 'Efectivo no válido.');
        $extra = [];
        if (isset($d['fecha_iso'])) $extra['fecha_iso'] = (string)$d['fecha_iso'];
        if (isset($d['total_esperado']) && is_numeric($d['total_esperado'])) $extra['total_esperado'] = a_centavos($d['total_esperado']);
        $temp = substr(preg_replace('/[^A-Za-z0-9-]/', '', (string)($d['ticket_temp'] ?? '')), 0, 20);

        try {
            // La bitácora va dentro de la misma transacción que la venta (Database::transaccion anida sin problema).
            $r = Database::transaccion(function () use ($idUsuario, $items, $efectivo, $uuid, $extra, $temp) {
                $r = Venta::crear($idUsuario, array_values($items), a_centavos($efectivo), $uuid, $extra);
                if (!$r['duplicada']) {
                    $v = Venta::obtener($r['id_venta']);
                    Bitacora::registrar($idUsuario, 'VENTA_OFFLINE_SINCRONIZADA',
                        "Venta #{$r['id_venta']} (ticket {$v['numero_ticket']}) hecha sin conexión el " . ($extra['fecha_iso'] ?? 's/f')
                        . ($temp !== '' ? ", comprobante provisional $temp" : '') . '; registrada ' . date('Y-m-d H:i:s') . '.');
                }
                return $r;
            });
        } catch (VentaConflicto $e) {
            return self::conflicto($e->codigo, $e->getMessage());
        } catch (RuntimeException $e) {
            return self::conflicto('rechazada', $e->getMessage());
        }
        $v = Venta::obtener($r['id_venta']);
        return ['ok' => true, 'estado' => $r['duplicada'] ? 'duplicada' : 'registrada', 'id_venta' => $r['id_venta'],
                'numero_ticket' => (int)$v['numero_ticket'], 'total' => (float)$v['total'], 'cambio' => (float)$v['cambio'],
                'fecha' => $v['fecha'], 'ticket' => url('venta', 'ticket', ['id' => $r['id_venta']])];
    }

    private static function conflicto(string $codigo, string $msg): array
    {
        return ['ok' => false, 'estado' => 'conflicto', 'codigo' => $codigo, 'msg' => $msg];
    }

    /**
     * Descarte de una venta «por revisar». Si el uuid SÍ existe en el servidor no se descarta (devuelve ya_registrada).
     * Motivo >= 5 caracteres; queda en bitácora con los datos de la venta perdida.
     */
    public static function descartar(int $idUsuario, array $d): array
    {
        $uuid = (string)($d['uuid_operacion'] ?? '');
        if (!uuid_valido($uuid)) return ['ok' => false, 'msg' => 'Identificador de venta no válido.'];
        $motivo = trim((string)($d['motivo'] ?? ''));
        if (largo_texto($motivo) < 5) return ['ok' => false, 'msg' => 'El motivo debe tener al menos 5 caracteres.'];

        $st = Database::get()->prepare('SELECT id_venta, numero_ticket FROM ventas WHERE uuid_operacion = ?');
        $st->execute([$uuid]);
        if ($v = $st->fetch()) {
            return ['ok' => false, 'estado' => 'ya_registrada', 'id_venta' => (int)$v['id_venta'], 'numero_ticket' => (int)$v['numero_ticket'],
                    'ticket' => url('venta', 'ticket', ['id' => (int)$v['id_venta']]),
                    'msg' => 'Esa venta sí está registrada en el servidor (ticket #' . str_pad((string)$v['numero_ticket'], 6, '0', STR_PAD_LEFT) . '); no se descarta.'];
        }
        $tot = is_numeric($d['total'] ?? null) ? de_centavos(a_centavos($d['total'])) : '?';
        $temp = substr(preg_replace('/[^A-Za-z0-9-]/', '', (string)($d['ticket_temp'] ?? '')), 0, 20);
        $causa = substr(trim((string)($d['detalle_motivo'] ?? '')), 0, 160);
        Bitacora::registrar($idUsuario, 'VENTA_OFFLINE_DESCARTADA',
            "Se descartó la venta sin conexión $temp (total $tot, hecha " . substr((string)($d['fecha_iso'] ?? ''), 0, 25) . ", uuid $uuid). "
            . "Motivo: $motivo." . ($causa !== '' ? " Conflicto original: $causa" : ''));
        return ['ok' => true, 'estado' => 'descartada', 'msg' => 'Venta descartada y registrada en la bitácora.'];
    }
}
