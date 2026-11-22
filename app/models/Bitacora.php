<?php
class Bitacora
{
    public static function registrar(?int $idUsuario, string $accion, string $detalle = ''): void
    {
        Database::get()->prepare('INSERT INTO bitacora (id_usuario, accion, detalle) VALUES (?,?,?)')
            ->execute([$idUsuario, substr($accion, 0, 60), substr($detalle, 0, 500)]);
    }

    /* ===== Visor (Bloque 1.4): SOLO lectura. La bitácora es inmutable: aquí no hay UPDATE ni DELETE. ===== */

    public const POR_PAGINA = 50;
    public const MAX_EXPORTAR = 50000;

    /**
     * Normaliza y valida los filtros de la URL. Lo inválido se ignora (queda vacío).
     * @return array{desde:string,hasta:string,usuario:string,accion:string}
     */
    public static function filtros(array $in): array
    {
        $fecha = function ($v): string {
            $v = is_string($v) ? trim($v) : '';
            if (!preg_match('/^(\d{4})-(\d{2})-(\d{2})$/', $v, $m) || !checkdate((int)$m[2], (int)$m[3], (int)$m[1])) return '';
            return $v;
        };
        $f = [
            'desde'   => $fecha($in['desde'] ?? ''),
            'hasta'   => $fecha($in['hasta'] ?? ''),
            'usuario' => '',
            'accion'  => '',
        ];
        if ($f['desde'] !== '' && $f['hasta'] !== '' && $f['desde'] > $f['hasta']) {
            [$f['desde'], $f['hasta']] = [$f['hasta'], $f['desde']];
        }
        $u = is_string($in['usuario'] ?? null) ? trim($in['usuario']) : '';
        if ($u === 'sistema' || preg_match('/^\d{1,10}$/', $u)) $f['usuario'] = $u;
        $a = is_string($in['accion'] ?? null) ? trim($in['accion']) : '';
        if (preg_match('/^[A-Za-z0-9_]{1,60}$/', $a)) $f['accion'] = $a;
        return $f;
    }

    /** @return array{0:string,1:array} [cláusula WHERE (con "WHERE"), parámetros] */
    private static function donde(array $f): array
    {
        $w = [];
        $p = [];
        if ($f['desde'] !== '') { $w[] = 'b.fecha >= ?'; $p[] = $f['desde'] . ' 00:00:00'; }
        if ($f['hasta'] !== '') { $w[] = 'b.fecha < DATE_ADD(?, INTERVAL 1 DAY)'; $p[] = $f['hasta']; }
        if ($f['usuario'] === 'sistema') { $w[] = 'b.id_usuario IS NULL'; }
        elseif ($f['usuario'] !== '') { $w[] = 'b.id_usuario = ?'; $p[] = (int)$f['usuario']; }
        if ($f['accion'] !== '') { $w[] = 'b.accion = ?'; $p[] = $f['accion']; }
        return [$w ? ' WHERE ' . implode(' AND ', $w) : '', $p];
    }

    public static function contar(array $f): int
    {
        [$w, $p] = self::donde($f);
        $st = Database::get()->prepare('SELECT COUNT(*) FROM bitacora b' . $w);
        $st->execute($p);
        return (int)$st->fetchColumn();
    }

    /** Página $pagina (desde 1), más recientes primero. */
    public static function buscar(array $f, int $pagina, int $por = self::POR_PAGINA): array
    {
        [$w, $p] = self::donde($f);
        $st = Database::get()->prepare(
            'SELECT b.id_bitacora, b.fecha, b.id_usuario, b.accion, b.detalle, u.nombre AS u_nombre, u.usuario AS u_usuario
               FROM bitacora b LEFT JOIN usuarios u ON u.id_usuario = b.id_usuario' . $w . '
              ORDER BY b.id_bitacora DESC LIMIT ? OFFSET ?'
        );
        $i = 1;
        foreach ($p as $v) $st->bindValue($i++, $v, is_int($v) ? PDO::PARAM_INT : PDO::PARAM_STR);
        $st->bindValue($i++, max(1, $por), PDO::PARAM_INT);
        $st->bindValue($i, max(0, ($pagina - 1) * $por), PDO::PARAM_INT);
        $st->execute();
        return $st->fetchAll();
    }

    /**
     * Recorre las filas filtradas (más recientes primero) en bloques de 1000 sin cargarlas todas en memoria.
     * Devuelve cuántas entregó (máximo $max).
     */
    public static function recorrer(array $f, int $max, callable $fn): int
    {
        [$w, $p] = self::donde($f);
        $w .= ($w === '' ? ' WHERE ' : ' AND ') . 'b.id_bitacora < ?';
        $pdo = Database::get();
        $st = $pdo->prepare(
            'SELECT b.id_bitacora, b.fecha, b.id_usuario, b.accion, b.detalle, u.nombre AS u_nombre, u.usuario AS u_usuario
               FROM bitacora b LEFT JOIN usuarios u ON u.id_usuario = b.id_usuario' . $w . '
              ORDER BY b.id_bitacora DESC LIMIT ?'
        );
        $ultimo = PHP_INT_MAX;
        $dado = 0;
        while ($dado < $max) {
            $i = 1;
            foreach ($p as $v) $st->bindValue($i++, $v, is_int($v) ? PDO::PARAM_INT : PDO::PARAM_STR);
            $st->bindValue($i++, $ultimo, PDO::PARAM_INT);
            $st->bindValue($i, min(1000, $max - $dado), PDO::PARAM_INT);
            $st->execute();
            $filas = $st->fetchAll();
            if (!$filas) break;
            foreach ($filas as $fila) { $fn($fila); $dado++; $ultimo = (int)$fila['id_bitacora']; }
        }
        return $dado;
    }

    /** @return string[] acciones distintas que existen en la bitácora */
    public static function acciones(): array
    {
        return Database::get()->query('SELECT DISTINCT accion FROM bitacora ORDER BY accion')->fetchAll(PDO::FETCH_COLUMN);
    }

    public static function usuarios(): array
    {
        return Database::get()->query('SELECT id_usuario, nombre, usuario FROM usuarios ORDER BY nombre')->fetchAll();
    }
}
