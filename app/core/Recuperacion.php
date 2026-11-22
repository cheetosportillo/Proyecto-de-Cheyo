<?php
/**
 * Código de recuperación OFFLINE de la Dueña (sin correo ni internet).
 *
 *  - Formato visible:  ONIX-XXXX-XXXX-XXXX   (12 caracteres aleatorios, ~60 bits)
 *  - Alfabeto de 32 símbolos SIN caracteres ambiguos (sin 0/O ni 1/I): se puede dictar o imprimir.
 *  - En la BD solo se guarda password_hash() del código normalizado (usuarios.codigo_recuperacion_hash).
 *  - El código en claro se muestra UNA sola vez (al instalar) y no se registra en ningún log.
 *
 * Bloque 1.3: AuthController::restablecerLlave() usa verificar() con límite de intentos, rota la llave con
 * generar() y registra el evento en bitácora. El código de 8 caracteres para el correo está más abajo.
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

    /* ---------- Código temporal que se envía por CORREO (Bloque 1.3) ----------
     * 8 caracteres del mismo alfabeto sin ambiguos, formato "ABCD-EFGH". Vence en 15 minutos y se guarda
     * solo como hash en usuarios.reset_token_hash. Es distinto de la llave maestra offline (ONIX-XXXX-XXXX-XXXX). */

    private const LARGO_CORREO = 8;

    /** @return array{0:string,1:string} [código para enviar "ABCD-EFGH", hash para guardar] */
    public static function generarCodigoCorreo(): array
    {
        $bytes = random_bytes(self::LARGO_CORREO);
        $crudo = '';
        for ($i = 0; $i < self::LARGO_CORREO; $i++) {
            $crudo .= self::ALFABETO[ord($bytes[$i]) & 31];
        }
        return [implode('-', str_split($crudo, 4)), password_hash($crudo, PASSWORD_DEFAULT)];
    }

    /** Acepta minúsculas, espacios y guion. Devuelve los 8 caracteres canónicos o null. */
    public static function normalizarCodigoCorreo(string $codigo): ?string
    {
        $c = strtoupper((string)preg_replace('/[^A-Za-z0-9]/', '', $codigo));
        return preg_match('/^[A-HJ-NP-Z2-9]{8}$/', $c) === 1 ? $c : null;
    }

    public static function verificarCodigoCorreo(string $codigo, ?string $hash): bool
    {
        if ($hash === null || $hash === '') return false;
        $n = self::normalizarCodigoCorreo($codigo);
        return $n !== null && password_verify($n, $hash);
    }
}
