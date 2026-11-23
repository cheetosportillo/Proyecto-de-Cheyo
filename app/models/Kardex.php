<?php
/**
 * Motor contable Kardex · Costo Promedio Ponderado (CPP).
 *
 * Reglas (Plan v9):
 *  - K2/K4: toda la aritmética es en CENTAVOS ENTEROS. Nunca floats ni productos.costo como base.
 *  - CPP = saldo_monetario / saldo_cantidad, guardado a 4 decimales.
 *  - K3: AJUSTE_ENTRADA y AJUSTE_SALIDA se valoran al CPP vigente.
 *  - N5: ANULACION_VENTA puede re-ingresar EXACTAMENTE detalle_venta.costo_total.
 *  - ERR-14: al sacar la última unidad, valor_total = saldo anterior y saldo monetario = 0.00 exacto.
 *  - Cada fila guarda la foto de unidad_peso (gramos / kilates) de la categoría en ese momento.
 *
 * Cada operación bloquea la fila del producto (FOR UPDATE). Quien llame a varios productos debe
 * hacerlo ordenado por id_producto ASC para evitar deadlocks.
 */
class Kardex
{
    public const ENTRADAS = ['SALDO_INICIAL', 'ENTRADA', 'AJUSTE_ENTRADA', 'ANULACION_VENTA'];
    public const SALIDAS  = ['SALIDA', 'AJUSTE_SALIDA', 'ANULACION_COMPRA'];

    /* ------------------------------------------------------------------ */
    /*  Lectura interna                                                    */
    /* ------------------------------------------------------------------ */

    /** Bloquea el producto y devuelve sus datos + unidad de la categoría. */
    private static function bloquearProducto(PDO $pdo, int $id): array
    {
        $st = $pdo->prepare('SELECT p.id_producto, p.codigo, p.nombre, p.peso, p.stock, c.unidad_peso
            FROM productos p JOIN categorias c ON c.id_categoria = p.id_categoria
            WHERE p.id_producto = ? FOR UPDATE');
        $st->execute([$id]);
        $p = $st->fetch();
        if (!$p) throw new RuntimeException('El producto no existe.');
        return $p;
    }

    /**
     * Último saldo del producto en enteros: cantidad, peso (milésimas), monetario (centavos), cpp (1/10000).
     * FOR UPDATE = lectura ACTUAL (no el snapshot de REPEATABLE READ): imprescindible bajo concurrencia, porque
     * la transacción pudo haber empezado a "ver" datos ANTES de esperar el bloqueo y el saldo estaría viejo.
     */
    private static function saldo(PDO $pdo, int $id): array
    {
        $st = $pdo->prepare('SELECT saldo_cantidad, saldo_peso, saldo_monetario, cpp FROM kardex
            WHERE id_producto = ? ORDER BY id_kardex DESC LIMIT 1 FOR UPDATE');
        $st->execute([$id]);
        $f = $st->fetch();
        if (!$f) return ['cant' => 0, 'peso' => 0, 'mon' => 0, 'cpp' => 0];
        return [
            'cant' => (int)$f['saldo_cantidad'],
            'peso' => a_milesimas($f['saldo_peso']),
            'mon'  => a_centavos($f['saldo_monetario']),
            'cpp'  => (int)round(((float)$f['cpp']) * 10000),   // 1/10000 de unidad monetaria
        ];
    }

    /** CPP en diezmilésimas: round(saldo_mon*100 / saldo_cant). Si no hay stock conserva el último CPP. */
    private static function calcularCpp(int $mon, int $cant, int $cppPrevio): int
    {
        return $cant > 0 ? div_redondeada($mon * 100, $cant) : $cppPrevio;
    }

    private static function insertar(PDO $pdo, array $p, string $tipo, int $cant, int $pesoMov, int $valor,
                                     array $nuevo, ?int $idUsuario, ?string $refTipo, ?int $refId, ?string $motivo): void
    {
        $costoUnit = $cant > 0 ? div_redondeada($valor * 100, $cant) : 0;   // 1/10000
        $pdo->prepare('INSERT INTO kardex (id_producto, tipo, cantidad, peso_mov, unidad_peso, costo_unitario, valor_total,
                saldo_cantidad, saldo_peso, saldo_monetario, cpp, ref_tipo, ref_id, motivo, id_usuario)
                VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)')
            ->execute([
                $p['id_producto'], $tipo, $cant, de_escala($pesoMov, 3), $p['unidad_peso'],
                de_escala($costoUnit, 4), de_centavos($valor),
                $nuevo['cant'], de_escala($nuevo['peso'], 3), de_centavos($nuevo['mon']), de_escala($nuevo['cpp'], 4),
                $refTipo, $refId, $motivo !== null ? substr($motivo, 0, 255) : null, $idUsuario,
            ]);
        // productos.stock se mantiene sincronizado; productos.costo es solo un reflejo informativo del CPP.
        $pdo->prepare('UPDATE productos SET stock = ?, costo = ? WHERE id_producto = ?')
            ->execute([$nuevo['cant'], de_escala($nuevo['cpp'], 4), $p['id_producto']]);
    }

    private static function resultado(int $valor, int $cant, array $nuevo): array
    {
        return [
            'valor_total_centavos'    => $valor,
            'costo_unitario'          => de_escala($cant > 0 ? div_redondeada($valor * 100, $cant) : 0, 4),
            'saldo_cantidad'          => $nuevo['cant'],
            'saldo_monetario_centavos' => $nuevo['mon'],
            'cpp'                     => de_escala($nuevo['cpp'], 4),
        ];
    }

    /* ------------------------------------------------------------------ */
    /*  ENTRADAS                                                           */
    /* ------------------------------------------------------------------ */

    /**
     * Registra SALDO_INICIAL, ENTRADA, AJUSTE_ENTRADA o ANULACION_VENTA.
     *
     * @param int|null $costoUnitarioCentavos      Costo por pieza en centavos (SALDO_INICIAL / ENTRADA). Ignorado en ajustes.
     * @param int|null $valorTotalCentavosExacto   N5: valor total exacto a re-ingresar (ANULACION_VENTA = detalle_venta.costo_total).
     */
    public static function registrarEntrada(
        int $idProducto, string $tipo, int $cantidad, ?int $costoUnitarioCentavos, ?int $idUsuario = null,
        ?string $refTipo = null, ?int $refId = null, ?string $motivo = null, ?int $valorTotalCentavosExacto = null
    ): array {
        if (!in_array($tipo, self::ENTRADAS, true)) throw new InvalidArgumentException("Tipo de entrada inválido: $tipo");
        if ($cantidad <= 0) throw new RuntimeException('La cantidad debe ser mayor a 0.');

        return Database::transaccion(function (PDO $pdo) use ($idProducto, $tipo, $cantidad, $costoUnitarioCentavos,
                                                              $idUsuario, $refTipo, $refId, $motivo, $valorTotalCentavosExacto) {
            $p = self::bloquearProducto($pdo, $idProducto);
            $s = self::saldo($pdo, $idProducto);

            if ($valorTotalCentavosExacto !== null) {
                if ($valorTotalCentavosExacto < 0) throw new RuntimeException('El valor total no puede ser negativo.');
                $valor = $valorTotalCentavosExacto;
            } elseif ($tipo === 'AJUSTE_ENTRADA') {
                // K3: valorado al CPP vigente
                if ($s['cant'] > 0)      $valor = div_redondeada($cantidad * $s['mon'], $s['cant']);
                elseif ($s['cpp'] > 0)   $valor = div_redondeada($cantidad * $s['cpp'], 100);   // último CPP conocido
                else throw new RuntimeException('No hay un costo de referencia para valorar el ajuste. Registra primero una entrada o saldo inicial.');
            } else {
                if ($costoUnitarioCentavos === null || $costoUnitarioCentavos < 0)
                    throw new RuntimeException('Falta el costo unitario de la entrada.');
                $valor = $cantidad * $costoUnitarioCentavos;
            }

            if ($tipo === 'SALDO_INICIAL' && self::tieneMovimientos($idProducto))
                throw new RuntimeException("«{$p['codigo']}» ya tiene movimientos; el saldo inicial solo se carga una vez.");

            $pesoMov = $cantidad * a_milesimas($p['peso']);
            $nuevo = [
                'cant' => $s['cant'] + $cantidad,
                'peso' => $s['peso'] + $pesoMov,
                'mon'  => $s['mon'] + $valor,
            ];
            $nuevo['cpp'] = self::calcularCpp($nuevo['mon'], $nuevo['cant'], $s['cpp']);

            self::insertar($pdo, $p, $tipo, $cantidad, $pesoMov, $valor, $nuevo, $idUsuario, $refTipo, $refId, $motivo);
            return self::resultado($valor, $cantidad, $nuevo);
        });
    }

    /* ------------------------------------------------------------------ */
    /*  SALIDAS                                                            */
    /* ------------------------------------------------------------------ */

    /** Registra SALIDA, AJUSTE_SALIDA o ANULACION_COMPRA, siempre valorada al CPP vigente. */
    public static function registrarSalida(
        int $idProducto, string $tipo, int $cantidad, ?int $idUsuario = null,
        ?string $refTipo = null, ?int $refId = null, ?string $motivo = null
    ): array {
        if (!in_array($tipo, self::SALIDAS, true)) throw new InvalidArgumentException("Tipo de salida inválido: $tipo");
        if ($cantidad <= 0) throw new RuntimeException('La cantidad debe ser mayor a 0.');

        return Database::transaccion(function (PDO $pdo) use ($idProducto, $tipo, $cantidad, $idUsuario, $refTipo, $refId, $motivo) {
            $p = self::bloquearProducto($pdo, $idProducto);
            $s = self::saldo($pdo, $idProducto);

            if ($cantidad > $s['cant'])
                throw new RuntimeException("Stock insuficiente de «{$p['nombre']}» (disponible: {$s['cant']}).");

            $nuevoStock = $s['cant'] - $cantidad;
            if ($nuevoStock === 0) {
                // ERR-14: última unidad -> sale TODO el saldo, el monetario queda en 0.00 exacto
                $valor   = $s['mon'];
                $pesoMov = $s['peso'];
                $nuevo   = ['cant' => 0, 'peso' => 0, 'mon' => 0];
            } else {
                $valor   = div_redondeada($cantidad * $s['mon'], $s['cant']);
                $pesoMov = $cantidad * a_milesimas($p['peso']);
                $nuevo   = ['cant' => $nuevoStock, 'peso' => max(0, $s['peso'] - $pesoMov), 'mon' => $s['mon'] - $valor];
            }
            $nuevo['cpp'] = self::calcularCpp($nuevo['mon'], $nuevo['cant'], $s['cpp']);

            self::insertar($pdo, $p, $tipo, $cantidad, $pesoMov, $valor, $nuevo, $idUsuario, $refTipo, $refId, $motivo);
            return self::resultado($valor, $cantidad, $nuevo);
        });
    }

    /* ------------------------------------------------------------------ */
    /*  Consultas                                                          */
    /* ------------------------------------------------------------------ */

    /** ¿El producto ya tiene movimientos? (bloquea peso, unidad y SALDO_INICIAL). */
    public static function tieneMovimientos(int $idProducto): bool
    {
        $st = Database::get()->prepare('SELECT 1 FROM kardex WHERE id_producto = ? LIMIT 1');
        $st->execute([$idProducto]);
        return (bool)$st->fetchColumn();
    }

    /** ¿Alguna pieza de la categoría tiene movimientos? (bloquea el cambio de unidad_peso). */
    public static function categoriaTieneMovimientos(int $idCategoria): bool
    {
        $st = Database::get()->prepare('SELECT 1 FROM kardex k JOIN productos p ON p.id_producto = k.id_producto
            WHERE p.id_categoria = ? LIMIT 1');
        $st->execute([$idCategoria]);
        return (bool)$st->fetchColumn();
    }

    /** Pantalla de consulta: filtros por producto, categoría y rango [desde, hasta+1d). */
    public static function consultar(int $idProducto, int $idCategoria, string $desde, string $hasta, int $limite = 2000): array
    {
        [, , $ini, $fin] = rango_fechas($desde, $hasta);
        $sql = 'SELECT k.*, p.codigo, p.nombre, c.nombre AS categoria
                FROM kardex k JOIN productos p ON p.id_producto = k.id_producto
                JOIN categorias c ON c.id_categoria = p.id_categoria
                WHERE k.fecha >= ? AND k.fecha < ?';
        $par = [$ini, $fin];
        if ($idProducto > 0)  { $sql .= ' AND k.id_producto = ?';  $par[] = $idProducto; }
        if ($idCategoria > 0) { $sql .= ' AND p.id_categoria = ?'; $par[] = $idCategoria; }
        $sql .= ' ORDER BY k.id_producto, k.id_kardex LIMIT ' . (int)$limite;
        $st = Database::get()->prepare($sql);
        $st->execute($par);
        return $st->fetchAll();
    }
}
