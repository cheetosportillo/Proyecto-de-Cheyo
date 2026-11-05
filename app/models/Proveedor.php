<?php
class Proveedor
{
    public static function listar(bool $soloActivos = true): array
    {
        $sql = 'SELECT * FROM proveedores' . ($soloActivos ? ' WHERE estado = 1' : '') . ' ORDER BY nombre';
        return Database::get()->query($sql)->fetchAll();
    }

    public static function crear(array $d): void
    {
        Database::get()->prepare('INSERT INTO proveedores (nombre, telefono, correo, direccion, estado) VALUES (?,?,?,?,1)')
            ->execute([$d['nombre'], $d['telefono'], $d['correo'], $d['direccion']]);
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
}
