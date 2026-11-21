<?php
class Compra
{
    /** Listado con paginación real (devuelve filas + total + páginas). */
    public static function listar(int $pagina = 1, int $porPagina = 15): array
    {
        $pdo = Database::get();
        $total = (int)$pdo->query('SELECT COUNT(*) FROM compras')->fetchColumn();
        $paginas = max(1, (int)ceil($total / $porPagina));
        $pagina = max(1, min($pagina, $paginas));
        $st = $pdo->prepare('SELECT c.*, p.nombre AS proveedor, u.nombre AS usuario
            FROM compras c JOIN proveedores p ON p.id_proveedor = c.id_proveedor
            JOIN usuarios u ON u.id_usuario = c.id_usuario
            ORDER BY c.id_compra DESC LIMIT ' . (int)$porPagina . ' OFFSET ' . (int)(($pagina - 1) * $porPagina));
        $st->execute();
        return ['filas' => $st->fetchAll(), 'total' => $total, 'pagina' => $pagina, 'paginas' => $paginas];
    }

    public static function obtener(int $id): ?array
    {
        $st = Database::get()->prepare('SELECT * FROM compras WHERE id_compra = ?');
        $st->execute([$id]);
        return $st->fetch() ?: null;
    }

    public static function detalle(int $id): array
    {
        $st = Database::get()->prepare('SELECT d.*, p.codigo, p.nombre FROM detalle_compra d
            JOIN productos p ON p.id_producto = d.id_producto WHERE d.id_compra = ?');
        $st->execute([$id]);
        return $st->fetchAll();
    }

    /**
     * Registra la compra: idempotente por uuid_operacion, factura única por proveedor, cálculos en
     * centavos, productos ordenados por id ASC antes de bloquear, y entrada al Kardex (CPP).
     * Devuelve ['id_compra' => int, 'duplicada' => bool].
     *
     * @param array $items [['id'=>int, 'cantidad'=>int, 'costo'=>float|string (por pieza)], ...]
     */
    public static function crear(int $idUsuario, int $idProveedor, string $obs, array $items,
                                 string $uuid, ?string $factura = null): array
    {
        if (!uuid_valido($uuid)) throw new RuntimeException('Operación sin identificador válido. Recarga la página.');
        $factura = $factura !== null ? trim($factura) : null;
        if ($factura === '') $factura = null;

        // Agrupa líneas repetidas y ORDENA por id_producto ASC (evita deadlocks al bloquear)
        $lineas = [];
        foreach ($items as $it) {
            $id = (int)$it['id'];
            $cent = a_centavos($it['costo']);
            if (isset($lineas[$id]) && $lineas[$id]['costo'] !== $cent)
                throw new RuntimeException('El mismo producto aparece con costos distintos en la compra.');
            $lineas[$id] = ['id' => $id, 'cantidad' => ($lineas[$id]['cantidad'] ?? 0) + (int)$it['cantidad'], 'costo' => $cent];
        }
        ksort($lineas);

        try {
            return Database::transaccion(function (PDO $pdo) use ($idUsuario, $idProveedor, $obs, $lineas, $uuid, $factura) {
                $dup = $pdo->prepare('SELECT id_compra FROM compras WHERE uuid_operacion = ?');
                $dup->execute([$uuid]);
                if ($existente = $dup->fetchColumn()) return ['id_compra' => (int)$existente, 'duplicada' => true];

                $prov = $pdo->prepare('SELECT estado FROM proveedores WHERE id_proveedor = ?');
                $prov->execute([$idProveedor]);
                $estadoProv = $prov->fetchColumn();
                if ($estadoProv === false || (int)$estadoProv !== 1) throw new RuntimeException('El proveedor no existe o está inactivo.');

                $total = 0;
                foreach ($lineas as $l) $total += $l['cantidad'] * $l['costo'];

                $pdo->prepare('INSERT INTO compras (id_proveedor, id_usuario, total, observacion, uuid_operacion, numero_factura) VALUES (?,?,?,?,?,?)')
                    ->execute([$idProveedor, $idUsuario, de_centavos($total), $obs, $uuid, $factura]);
                $idCompra = (int)$pdo->lastInsertId();

                $ins = $pdo->prepare('INSERT INTO detalle_compra (id_compra, id_producto, cantidad, costo_unitario, subtotal) VALUES (?,?,?,?,?)');
                $est = $pdo->prepare('SELECT estado FROM productos WHERE id_producto = ?');
                foreach ($lineas as $l) {
                    $est->execute([$l['id']]);
                    $estado = $est->fetchColumn();
                    if ($estado === false || (int)$estado !== 1) throw new RuntimeException('Producto no válido en la compra.');
                    $ins->execute([$idCompra, $l['id'], $l['cantidad'], de_escala($l['costo'] * 100, 4), de_centavos($l['cantidad'] * $l['costo'])]);
                    Kardex::registrarEntrada($l['id'], 'ENTRADA', $l['cantidad'], $l['costo'], $idUsuario, 'COMPRA', $idCompra, $obs ?: null);
                }
                return ['id_compra' => $idCompra, 'duplicada' => false];
            });
        } catch (PDOException $e) {
            if ((int)($e->errorInfo[1] ?? 0) === 1062) {
                if (str_contains($e->getMessage(), 'uk_compras_factura'))
                    throw new RuntimeException('Ya existe una compra con esa factura para este proveedor.');
                if (str_contains($e->getMessage(), 'uk_compras_uuid')) {
                    // doble clic simultáneo: la otra petición ya la guardó
                    $st = Database::get()->prepare('SELECT id_compra FROM compras WHERE uuid_operacion = ?');
                    $st->execute([$uuid]);
                    return ['id_compra' => (int)$st->fetchColumn(), 'duplicada' => true];
                }
            }
            throw $e;
        }
    }

    /** ANULACION_COMPRA: solo si el stock restante alcanza para revertir cada línea. Motivo >= 5 caracteres. */
    public static function anular(int $idCompra, string $motivo, int $idUsuario): void
    {
        $motivo = trim($motivo);
        if (largo_texto($motivo) < 5) throw new RuntimeException('El motivo debe tener al menos 5 caracteres.');

        Database::transaccion(function (PDO $pdo) use ($idCompra, $motivo, $idUsuario) {
            $st = $pdo->prepare('SELECT estado FROM compras WHERE id_compra = ? FOR UPDATE');
            $st->execute([$idCompra]);
            $estado = $st->fetchColumn();
            if ($estado === false) throw new RuntimeException('La compra no existe.');
            if ($estado !== 'ACTIVA') throw new RuntimeException('Esta compra ya fue anulada.');

            $det = $pdo->prepare('SELECT id_producto, cantidad FROM detalle_compra WHERE id_compra = ? ORDER BY id_producto ASC');
            $det->execute([$idCompra]);
            foreach ($det->fetchAll() as $l) {
                try {
                    Kardex::registrarSalida((int)$l['id_producto'], 'ANULACION_COMPRA', (int)$l['cantidad'], $idUsuario, 'COMPRA', $idCompra, $motivo);
                } catch (RuntimeException $e) {
                    if (str_contains($e->getMessage(), 'Stock insuficiente'))
                        throw new RuntimeException('No se puede anular: ya se vendió parte de la mercadería de esta compra. ' . $e->getMessage());
                    throw $e;
                }
            }
            $pdo->prepare("UPDATE compras SET estado='ANULADA', motivo_anulacion=?, id_usuario_anula=?, fecha_anulacion=NOW() WHERE id_compra=?")
                ->execute([$motivo, $idUsuario, $idCompra]);
            Bitacora::registrar($idUsuario, 'COMPRA_ANULADA', "Compra #$idCompra: $motivo");
        });
    }
}
