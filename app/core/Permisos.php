<?php
/**
 * RBAC (Bloque 1.3). Compatible con la Parte 2: el resto del código solo usa Permisos::tiene('modulo.accion').
 *
 *  - usuarios.rol sigue siendo el ENUM 'duena' / 'cajero'. La Dueña tiene acceso total por código ('*').
 *  - Los permisos del cajero viven en rol_permiso (concedido 0/1) y la Dueña los edita desde Usuarios.
 *  - Al iniciar sesión (y en cada petición, ver Controller::requireLogin) los permisos del rol se copian a
 *    $_SESSION['usuario']['permisos']. Si esa lista no existe (tests/CLI, o base aún sin migrar) se usa DEFECTO,
 *    que es exactamente el mapa que tenía la Parte 2.
 *  - RESERVADOS nunca se otorgan a un cajero, aunque alguien los marque en la BD.
 */
final class Permisos
{
    /** Solo la Dueña. */
    public const RESERVADOS = ['usuarios.gestionar', 'configuracion.gestionar', 'respaldos.gestionar', 'bitacora.ver'];
    // respaldos.gestionar y bitacora.ver (Bloque 1.4) viven solo aquí: no hay filas en `permisos`, así que no hacen falta
    // migraciones y la Dueña las tiene siempre (tiene() devuelve true para 'duena' con cualquier clave).

    private const DEFECTO = [
        'duena'  => ['*'],
        'cajero' => ['ventas.cobrar', 'ventas.reimprimir', 'inventario.consultar', 'caja.operar'],
    ];

    public static function tiene(string $permiso): bool
    {
        $rol = (string)($_SESSION['usuario']['rol'] ?? '');
        if ($rol === 'duena') return true;
        $lista = $_SESSION['usuario']['permisos'] ?? (self::DEFECTO[$rol] ?? []);
        if (!is_array($lista)) return false;
        if (in_array($permiso, self::RESERVADOS, true)) return false;
        return in_array('*', $lista, true) || in_array($permiso, $lista, true);
    }

    /**
     * Permisos vigentes de un rol, desde la BD. Sin tablas (base sin migrar) o sin filas para ese rol,
     * cae a DEFECTO para no dejar a nadie sin acceso por un esquema incompleto.
     *
     * @return string[]
     */
    public static function delRol(string $rol): array
    {
        if ($rol === 'duena') return ['*'];
        try {
            $st = Database::get()->prepare('SELECT permiso, concedido FROM rol_permiso WHERE rol = ?');
            $st->execute([$rol]);
            $filas = $st->fetchAll();
        } catch (Throwable $e) {
            return self::DEFECTO[$rol] ?? [];
        }
        if ($filas === []) return self::DEFECTO[$rol] ?? [];
        $r = [];
        foreach ($filas as $f) {
            if ((int)$f['concedido'] === 1 && !in_array($f['permiso'], self::RESERVADOS, true)) $r[] = $f['permiso'];
        }
        return $r;
    }

    /** @return array<int,array{clave:string,modulo:string,descripcion:string}> */
    public static function catalogo(): array
    {
        return Database::get()->query('SELECT clave, modulo, descripcion FROM permisos ORDER BY modulo, clave')->fetchAll();
    }

    /**
     * Fija EXACTAMENTE los permisos del rol (los no listados quedan en 0). Devuelve los otorgados.
     * No existe DELETE: se actualiza `concedido`.
     *
     * @param  string[] $claves
     * @return string[]
     * @throws InvalidArgumentException con mensaje apto para mostrar
     */
    public static function asignar(string $rol, array $claves): array
    {
        if ($rol === 'duena') throw new InvalidArgumentException('Los permisos de la Dueña no se editan: siempre tiene acceso total.');
        $st = Database::get()->prepare('SELECT 1 FROM roles WHERE rol = ?');
        $st->execute([$rol]);
        if (!$st->fetchColumn()) throw new InvalidArgumentException('Rol desconocido.');

        $validas = array_column(self::catalogo(), 'clave');
        $otorgar = [];
        foreach ($claves as $c) {
            if (!is_string($c) || !in_array($c, $validas, true)) throw new InvalidArgumentException('Permiso desconocido.');
            if (in_array($c, self::RESERVADOS, true)) throw new InvalidArgumentException('Ese permiso solo lo tiene la Dueña.');
            $otorgar[$c] = true;
        }
        Database::transaccion(function (PDO $pdo) use ($rol, $validas, $otorgar) {
            $ins = $pdo->prepare('INSERT INTO rol_permiso (rol, permiso, concedido) VALUES (?,?,?)
                                  ON DUPLICATE KEY UPDATE concedido = VALUES(concedido)');
            foreach ($validas as $c) {
                if (in_array($c, self::RESERVADOS, true)) continue;
                $ins->execute([$rol, $c, isset($otorgar[$c]) ? 1 : 0]);
            }
        });
        return array_keys($otorgar);
    }
}
