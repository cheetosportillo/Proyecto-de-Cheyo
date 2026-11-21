<?php
/**
 * SHIM de permisos para la Parte 2.
 * Si el Integrante 1 (Parte 1) ya trae su clase Permisos con tiene($permiso), BORRA este archivo
 * y su require en index.php: el resto del código solo usa Permisos::tiene().
 */
if (!class_exists('Permisos')) {
    class Permisos
    {
        private const MAPA = [
            'duena'  => ['*'],
            'cajero' => ['ventas.cobrar', 'ventas.reimprimir', 'inventario.consultar', 'caja.operar'],
        ];

        public static function tiene(string $permiso): bool
        {
            $rol = $_SESSION['usuario']['rol'] ?? '';
            $lista = self::MAPA[$rol] ?? [];
            return in_array('*', $lista, true) || in_array($permiso, $lista, true);
        }
    }
}
