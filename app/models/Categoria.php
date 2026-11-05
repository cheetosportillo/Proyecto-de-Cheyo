<?php
class Categoria
{
    public static function listar(): array
    {
        return Database::get()->query('SELECT * FROM categorias ORDER BY nombre')->fetchAll();
    }
}
