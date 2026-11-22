<?php
/**
 * Límite de intentos persistente (tabla recuperacion_intentos), por clave y con ventana fija.
 * No depende de la sesión ni de la IP: borrar cookies no lo evita, y detrás de un proxy (hosting) no
 * bloquea a todos por compartir dirección. Sin DELETE: limpiar() deja el contador en 0.
 */
class Intentos
{
    /** ¿Ya se alcanzaron $max intentos dentro de la ventana vigente (en segundos)? */
    public static function excedido(string $clave, int $max, int $ventana): bool
    {
        $st = Database::get()->prepare(
            'SELECT intentos FROM recuperacion_intentos
              WHERE clave = ? AND inicio >= DATE_SUB(NOW(), INTERVAL ' . (int)$ventana . ' SECOND)'
        );
        $st->execute([self::clave($clave)]);
        $n = $st->fetchColumn();
        return $n !== false && (int)$n >= $max;
    }

    /** Suma 1 (atómico). Si la ventana anterior ya venció, empieza una nueva. */
    public static function sumar(string $clave, int $ventana): void
    {
        $v = (int)$ventana;
        // MySQL/MariaDB evalúan las asignaciones de izquierda a derecha: `intentos` ve el `inicio` viejo.
        Database::get()->prepare(
            'INSERT INTO recuperacion_intentos (clave, intentos, inicio) VALUES (?, 1, NOW())
             ON DUPLICATE KEY UPDATE
               intentos = IF(inicio < DATE_SUB(NOW(), INTERVAL ' . $v . ' SECOND), 1, intentos + 1),
               inicio   = IF(inicio < DATE_SUB(NOW(), INTERVAL ' . $v . ' SECOND), NOW(), inicio)'
        )->execute([self::clave($clave)]);
    }

    public static function limpiar(string $clave): void
    {
        Database::get()->prepare('UPDATE recuperacion_intentos SET intentos = 0 WHERE clave = ?')->execute([self::clave($clave)]);
    }

    private static function clave(string $c): string
    {
        $c = strtolower(trim($c));
        return strlen($c) <= 120 ? $c : substr($c, 0, 80) . ':' . md5($c);
    }
}
