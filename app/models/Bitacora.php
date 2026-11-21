<?php
class Bitacora
{
    public static function registrar(?int $idUsuario, string $accion, string $detalle = ''): void
    {
        Database::get()->prepare('INSERT INTO bitacora (id_usuario, accion, detalle) VALUES (?,?,?)')
            ->execute([$idUsuario, substr($accion, 0, 60), substr($detalle, 0, 500)]);
    }
}
