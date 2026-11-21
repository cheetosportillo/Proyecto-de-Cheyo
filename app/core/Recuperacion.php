<?php
/**
 * Código de recuperación OFFLINE de la Dueña (sin correo ni internet).
 *
 *  - Formato visible:  ONIX-XXXX-XXXX-XXXX   (12 caracteres aleatorios, ~60 bits)
 *  - Alfabeto de 32 símbolos SIN caracteres ambiguos (sin 0/O ni 1/I): se puede dictar o imprimir.
 *  - En la BD solo se guarda password_hash() del código normalizado (usuarios.codigo_recuperacion_hash).
 *  - El código en claro se muestra UNA sola vez (al instalar) y no se registra en ningún log.
 *
 * Los demás flujos (usar el código para restablecer la clave, rotarlo, forzar debe_cambiar_password)
 * pertenecen al Bloque 1.3 y deben usar verificar() para que el formato sea siempre el mismo.
 * Compatible con PHP 8.0.
 */
final class Recuperacion
{
    private const ALFABETO = 'ABCDEFGHJKLMNPQRSTUVWXYZ23456789';   // 32 símbolos: ord() & 31 no tiene sesgo
    private const LARGO    = 12;

    /** @return array{0:string,1:string} [código para mostrar "ONIX-XXXX-XXXX-XXXX", hash para guardar] */
    public static function generar(): array
    {
        $bytes = random_bytes(self::LARGO);
        $crudo = '';
        for ($i = 0; $i < self::LARGO; $i++) {
            $crudo .= self::ALFABETO[ord($bytes[$i]) & 31];
        }
        return [self::formatear($crudo), password_hash($crudo, PASSWORD_DEFAULT)];
    }

    /** "ABCD1234EFGH" -> "ONIX-ABCD-1234-EFGH" */
    public static function formatear(string $crudo): string
    {
        return 'ONIX-' . implode('-', str_split($crudo, 4));
    }

    /**
     * Acepta minúsculas, espacios, guiones y el prefijo ONIX opcional.
     * Devuelve los 12 caracteres canónicos, o null si no tienen el formato correcto.
     */
    public static function normalizar(string $codigo): ?string
    {
        $c = strtoupper((string)preg_replace('/[^A-Za-z0-9]/', '', $codigo));
        if (strlen($c) === self::LARGO + 4 && strncmp($c, 'ONIX', 4) === 0) {
            $c = substr($c, 4);
        }
        return preg_match('/^[A-HJ-NP-Z2-9]{12}$/', $c) === 1 ? $c : null;
    }

    public static function verificar(string $codigo, ?string $hash): bool
    {
        if ($hash === null || $hash === '') return false;
        $n = self::normalizar($codigo);
        return $n !== null && password_verify($n, $hash);
    }
}
