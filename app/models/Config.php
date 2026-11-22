<?php
/**
 * Configuración clave/valor (tabla `configuracion`). Nombre de clase `Config` = el que ya espera
 * ventas/ticket.php (Config::obtener). Si la tabla no existe todavía (base sin migrar) devuelve el valor por defecto.
 *
 * La contraseña SMTP se guarda CIFRADA (AES-256-GCM) con una clave aleatoria en config/app.key
 * (ignorado por git y bloqueado por config/.htaccess). Así, un respaldo de la base de datos por sí solo
 * no revela la contraseña del correo. Respalda app.key junto con la BD: si se pierde, hay que volver a
 * escribir la contraseña SMTP en Configuración.
 */
class Config
{
    public const SECRETAS = ['smtp_password'];

    private static ?array $cache = null;

    /** @return array<string,string> clave => valor tal cual está guardado (las secretas, cifradas) */
    public static function todas(bool $recargar = false): array
    {
        if (self::$cache === null || $recargar) {
            self::$cache = [];
            try {
                foreach (Database::get()->query('SELECT clave, valor FROM configuracion')->fetchAll() as $f) {
                    self::$cache[$f['clave']] = (string)$f['valor'];
                }
            } catch (Throwable $e) {
                // tabla ausente: se usan los valores por defecto de quien llama
            }
        }
        return self::$cache;
    }

    /** Valor ya descifrado si es secreta. Vacío o ausente -> $defecto. */
    public static function obtener(string $clave, $defecto = null)
    {
        $t = self::todas();
        if (!isset($t[$clave]) || $t[$clave] === '') return $defecto;
        if (in_array($clave, self::SECRETAS, true)) {
            $claro = self::descifrar($t[$clave]);
            return $claro === null || $claro === '' ? $defecto : $claro;
        }
        return $t[$clave];
    }

    /** ¿Hay una contraseña guardada que todavía se pueda descifrar? (para mostrar «guardada» sin revelarla) */
    public static function tieneSecreto(string $clave): bool
    {
        return self::obtener($clave, '') !== '';
    }

    /**
     * Guarda varios pares en una transacción. Las claves secretas se cifran antes de guardar.
     * @param array<string,string> $pares
     * @throws RuntimeException si no se puede cifrar (sin openssl o sin permiso para crear config/app.key)
     */
    public static function guardar(array $pares): void
    {
        $filas = [];
        foreach ($pares as $k => $v) {
            $v = (string)$v;
            if (in_array($k, self::SECRETAS, true) && $v !== '') $v = self::cifrar($v);
            $filas[(string)$k] = $v;
        }
        Database::transaccion(function (PDO $pdo) use ($filas) {
            $st = $pdo->prepare('INSERT INTO configuracion (clave, valor) VALUES (?, ?) ON DUPLICATE KEY UPDATE valor = VALUES(valor)');
            foreach ($filas as $k => $v) $st->execute([$k, $v]);
        });
        self::$cache = null;
    }

    /* ---------------------------------------------------------------- cifrado */

    public static function archivoClave(): string { return dirname(__DIR__, 2) . '/config/app.key'; }

    /** Clave binaria de 32 bytes (se crea la primera vez). null si no se puede leer ni crear. */
    private static function clave(): ?string
    {
        $f = self::archivoClave();
        if (!is_file($f)) {
            $hex = bin2hex(random_bytes(32));
            if (@file_put_contents($f, $hex . "\n", LOCK_EX) === false) return null;
            @chmod($f, 0600);
        }
        $hex = trim((string)@file_get_contents($f));
        $bin = strlen($hex) === 64 && ctype_xdigit($hex) ? hex2bin($hex) : false;
        return $bin === false ? null : $bin;
    }

    private static function cifrar(string $claro): string
    {
        if (!function_exists('openssl_encrypt')) throw new RuntimeException('Falta la extensión openssl de PHP: no se puede guardar la contraseña de forma segura.');
        $k = self::clave();
        if ($k === null) throw new RuntimeException('No se pudo crear config/app.key (permisos de la carpeta config/). Sin esa clave no se guarda la contraseña SMTP.');
        $iv = random_bytes(12);
        $tag = '';
        $ct = openssl_encrypt($claro, 'aes-256-gcm', $k, OPENSSL_RAW_DATA, $iv, $tag);
        if ($ct === false || strlen($tag) !== 16) throw new RuntimeException('No se pudo cifrar la contraseña SMTP.');
        return 'v1:' . base64_encode($iv . $tag . $ct);
    }

    /** null si el valor no es un cifrado válido o la clave cambió/se perdió. */
    private static function descifrar(string $valor): ?string
    {
        if (strncmp($valor, 'v1:', 3) !== 0 || !function_exists('openssl_decrypt')) return null;
        $raw = base64_decode(substr($valor, 3), true);
        if ($raw === false || strlen($raw) < 29) return null;
        $k = self::clave();
        if ($k === null) return null;
        $claro = openssl_decrypt(substr($raw, 28), 'aes-256-gcm', $k, OPENSSL_RAW_DATA, substr($raw, 0, 12), substr($raw, 12, 16));
        return $claro === false ? null : $claro;
    }

    /** Solo para pruebas: ciclo cifrar/descifrar. */
    public static function _cifrarParaPrueba(string $claro): string { return self::cifrar($claro); }
    public static function _descifrarParaPrueba(string $valor): ?string { return self::descifrar($valor); }
}
