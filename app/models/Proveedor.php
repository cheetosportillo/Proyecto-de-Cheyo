<?php
class Proveedor
{
    public static function listar(bool $soloActivos = true): array
    {
        $sql = 'SELECT * FROM proveedores' . ($soloActivos ? ' WHERE estado = 1' : '') . ' ORDER BY nombre';
        return Database::get()->query($sql)->fetchAll();
    }

    /** Busca un proveedor con ese nombre (sin distinguir mayúsculas). Devuelve la fila (con su estado) o null. */
    public static function existe(string $nombre, int $excepto = 0): ?array
    {
        $st = Database::get()->prepare('SELECT * FROM proveedores WHERE nombre = ? AND id_proveedor <> ? LIMIT 1');
        $st->execute([$nombre, $excepto]);
        return $st->fetch() ?: null;
    }

    public static function crear(array $d): int
    {
        Database::get()->prepare('INSERT INTO proveedores (nombre, telefono, correo, direccion, estado) VALUES (?,?,?,?,1)')
            ->execute([$d['nombre'], $d['telefono'], $d['correo'], $d['direccion']]);
        return (int)Database::get()->lastInsertId();
    }

    public static function actualizar(int $id, array $d): void
    {
        Database::get()->prepare('UPDATE proveedores SET nombre=?, telefono=?, correo=?, direccion=? WHERE id_proveedor=?')
            ->execute([$d['nombre'], $d['telefono'], $d['correo'], $d['direccion'], $id]);
    }

    public static function quitar(int $id): void
    {
        Database::get()->prepare('UPDATE proveedores SET estado = 0 WHERE id_proveedor = ?')->execute([$id]);
    }

    public static function reactivar(int $id): void
    {
        Database::get()->prepare('UPDATE proveedores SET estado = 1 WHERE id_proveedor = ?')->execute([$id]);
    }
}
