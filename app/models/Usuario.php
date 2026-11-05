<?php
class Usuario
{
    public static function porUsuario(string $u): ?array
    {
        $st = Database::get()->prepare('SELECT * FROM usuarios WHERE usuario = ? LIMIT 1');
        $st->execute([$u]);
        return $st->fetch() ?: null;
    }

    public static function listar(): array
    {
        return Database::get()->query('SELECT id_usuario, nombre, usuario, rol, estado, created_at FROM usuarios ORDER BY nombre')->fetchAll();
    }

    public static function existe(string $usuario, int $excepto = 0): bool
    {
        $st = Database::get()->prepare('SELECT 1 FROM usuarios WHERE usuario = ? AND id_usuario <> ?');
        $st->execute([$usuario, $excepto]);
        return (bool)$st->fetchColumn();
    }

    public static function crear(array $d): void
    {
        $st = Database::get()->prepare('INSERT INTO usuarios (nombre, usuario, password, rol, estado) VALUES (?,?,?,?,1)');
        $st->execute([$d['nombre'], $d['usuario'], password_hash($d['password'], PASSWORD_DEFAULT), $d['rol']]);
    }

    public static function actualizar(int $id, array $d): void
    {
        if (!empty($d['password'])) {
            $st = Database::get()->prepare('UPDATE usuarios SET nombre=?, usuario=?, rol=?, password=? WHERE id_usuario=?');
            $st->execute([$d['nombre'], $d['usuario'], $d['rol'], password_hash($d['password'], PASSWORD_DEFAULT), $id]);
        } else {
            $st = Database::get()->prepare('UPDATE usuarios SET nombre=?, usuario=?, rol=? WHERE id_usuario=?');
            $st->execute([$d['nombre'], $d['usuario'], $d['rol'], $id]);
        }
    }

    public static function cambiarEstado(int $id, int $estado): void
    {
        Database::get()->prepare('UPDATE usuarios SET estado=? WHERE id_usuario=?')->execute([$estado, $id]);
    }
}
