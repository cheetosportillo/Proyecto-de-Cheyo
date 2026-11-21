<?php
/** Caja única: apertura, movimientos de efectivo y cierre/arqueo. Todo el dinero en centavos enteros. */
class Caja
{
    public static function abierta(): ?array
    {
        return Database::get()->query("SELECT * FROM cajas WHERE estado = 'ABIERTA' LIMIT 1")->fetch() ?: null;
    }

    /** Caja abierta bloqueada con FOR UPDATE (N14): serializa cobros, anulaciones y movimientos. */
    public static function abiertaBloqueada(PDO $pdo): ?array
    {
        return $pdo->query("SELECT * FROM cajas WHERE estado = 'ABIERTA' LIMIT 1 FOR UPDATE")->fetch() ?: null;
    }

    public static function abrir(int $idUsuario, int $montoInicialCentavos): int
    {
        if ($montoInicialCentavos < 0) throw new RuntimeException('El monto inicial no puede ser negativo.');
        try {
            Database::get()->prepare("INSERT INTO cajas (id_usuario_apertura, monto_inicial, estado) VALUES (?,?, 'ABIERTA')")
                ->execute([$idUsuario, de_centavos($montoInicialCentavos)]);
        } catch (PDOException $e) {
            if ((int)($e->errorInfo[1] ?? 0) === 1062) throw new RuntimeException('Ya hay una caja abierta. Ciérrala antes de abrir otra.');
            throw $e;
        }
        $id = (int)Database::get()->lastInsertId();
        Bitacora::registrar($idUsuario, 'CAJA_ABIERTA', "Caja #$id con " . de_centavos($montoInicialCentavos));
        return $id;
    }

    /**
     * Arqueo: inicial + ventas ACTIVAS + ingresos − retiros − gastos − devoluciones.
     * @return array<string,int> todo en centavos
     */
    public static function resumen(int $idCaja, bool $lecturaActual = false): array
    {
        $pdo = Database::get();
        $fu = $lecturaActual ? ' FOR UPDATE' : '';   // dentro de una transacción: dato actual, no snapshot
        $st = $pdo->prepare('SELECT monto_inicial FROM cajas WHERE id_caja = ?' . $fu);
        $st->execute([$idCaja]);
        $inicial = a_centavos($st->fetchColumn());

        $st = $pdo->prepare("SELECT COALESCE(SUM(total),0) FROM ventas WHERE id_caja = ? AND estado = 'ACTIVA'" . $fu);
        $st->execute([$idCaja]);
        $ventas = a_centavos($st->fetchColumn());

        $st = $pdo->prepare('SELECT tipo, COALESCE(SUM(monto),0) FROM caja_movimientos WHERE id_caja = ? GROUP BY tipo' . $fu);
        $st->execute([$idCaja]);
        $m = ['INGRESO' => 0, 'RETIRO' => 0, 'GASTO' => 0, 'DEVOLUCION' => 0];
        foreach ($st->fetchAll(PDO::FETCH_NUM) as [$tipo, $suma]) $m[$tipo] = a_centavos($suma);

        return ['inicial' => $inicial, 'ventas' => $ventas, 'ingresos' => $m['INGRESO'], 'retiros' => $m['RETIRO'],
                'gastos' => $m['GASTO'], 'devoluciones' => $m['DEVOLUCION'],
                'esperado' => $inicial + $ventas + $m['INGRESO'] - $m['RETIRO'] - $m['GASTO'] - $m['DEVOLUCION']];
    }

    /**
     * Movimiento de efectivo en la caja abierta. Motivo >= 3 caracteres.
     * $validarSaldo=false lo usa la anulación de ventas (la devolución es obligatoria aunque falte efectivo).
     */
    public static function movimiento(int $idUsuario, string $tipo, int $montoCentavos, string $motivo,
                                      ?int $idVenta = null, bool $validarSaldo = true): int
    {
        if (!in_array($tipo, ['INGRESO', 'RETIRO', 'GASTO', 'DEVOLUCION'], true)) throw new RuntimeException('Tipo de movimiento no válido.');
        $motivo = trim($motivo);
        if (largo_texto($motivo) < 3) throw new RuntimeException('El motivo debe tener al menos 3 caracteres.');
        if ($montoCentavos <= 0) throw new RuntimeException('El monto debe ser mayor a 0.');

        return Database::transaccion(function (PDO $pdo) use ($idUsuario, $tipo, $montoCentavos, $motivo, $idVenta, $validarSaldo) {
            $caja = self::abiertaBloqueada($pdo);
            if (!$caja) throw new RuntimeException('No hay una caja abierta.');
            if ($validarSaldo && $tipo !== 'INGRESO') {
                $esperado = self::resumen((int)$caja['id_caja'], true)['esperado'];
                if ($montoCentavos > $esperado)
                    throw new RuntimeException('El monto supera el efectivo disponible en caja (' . money(de_centavos($esperado)) . ').');
            }
            $pdo->prepare('INSERT INTO caja_movimientos (id_caja, tipo, monto, motivo, id_venta, id_usuario) VALUES (?,?,?,?,?,?)')
                ->execute([$caja['id_caja'], $tipo, de_centavos($montoCentavos), $motivo, $idVenta, $idUsuario]);
            return (int)$pdo->lastInsertId();
        });
    }

    /** Cierre y arqueo. Al terminar dispara Backup::generarAutomatico() (Parte 1) si existe. */
    public static function cerrar(int $idUsuario, int $contadoCentavos): array
    {
        if ($contadoCentavos < 0) throw new RuntimeException('El efectivo contado no puede ser negativo.');

        $r = Database::transaccion(function (PDO $pdo) use ($idUsuario, $contadoCentavos) {
            $caja = self::abiertaBloqueada($pdo);
            if (!$caja) throw new RuntimeException('No hay una caja abierta.');
            $res = self::resumen((int)$caja['id_caja'], true);
            $dif = $contadoCentavos - $res['esperado'];
            $pdo->prepare("UPDATE cajas SET estado='CERRADA', fecha_cierre=NOW(), id_usuario_cierre=?, efectivo_esperado=?, efectivo_contado=?, diferencia=? WHERE id_caja=?")
                ->execute([$idUsuario, de_centavos($res['esperado']), de_centavos($contadoCentavos), de_centavos($dif), $caja['id_caja']]);
            Bitacora::registrar($idUsuario, 'CAJA_CERRADA', "Caja #{$caja['id_caja']} esperado " . de_centavos($res['esperado']) . ' contado ' . de_centavos($contadoCentavos));
            return ['id_caja' => (int)$caja['id_caja'], 'resumen' => $res, 'contado' => $contadoCentavos, 'diferencia' => $dif];
        });

        $r['backup'] = false;
        if (class_exists('Backup') && method_exists('Backup', 'generarAutomatico')) {
            try { Backup::generarAutomatico(); $r['backup'] = true; }
            catch (Throwable $e) { error_log('Backup automático falló: ' . $e->getMessage()); }
        }
        return $r;
    }

    public static function movimientos(int $idCaja): array
    {
        $st = Database::get()->prepare('SELECT m.*, u.nombre AS usuario FROM caja_movimientos m JOIN usuarios u ON u.id_usuario = m.id_usuario
            WHERE m.id_caja = ? ORDER BY m.id_movimiento DESC');
        $st->execute([$idCaja]);
        return $st->fetchAll();
    }

    public static function historial(int $limite = 15): array
    {
        return Database::get()->query('SELECT c.*, u.nombre AS usuario FROM cajas c JOIN usuarios u ON u.id_usuario = c.id_usuario_apertura
            ORDER BY c.id_caja DESC LIMIT ' . (int)$limite)->fetchAll();
    }
}
