<?php
/** Conexión PDO única (singleton) + transacciones con reintento ante deadlock. */
class Database
{
    private static ?PDO $pdo = null;

    public static function get(): PDO
    {
        if (self::$pdo === null) {
            $dsn = 'mysql:host=' . DB_HOST . ';port=' . DB_PORT . ';dbname=' . DB_NAME . ';charset=' . DB_CHARSET;
            self::$pdo = new PDO($dsn, DB_USER, DB_PASS, [
                PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                PDO::ATTR_EMULATE_PREPARES   => false,
                // ERR-07: NOW()/CURDATE() de MariaDB también en hora de El Salvador (UTC-6).
                PDO::MYSQL_ATTR_INIT_COMMAND => "SET time_zone = '-06:00'",
            ]);
        }
        return self::$pdo;
    }

    /**
     * Zona horaria efectiva (Bloque 2). PHP, la sesión de MariaDB y NOW() deben coincidir SIEMPRE en hora de El Salvador:
     * de eso dependen los tickets, el cursor updated_at del catálogo offline y la fecha original de las ventas sin conexión.
     * @return array{php:string,sesion:string,php_offset:string,diferencia_seg:int}
     */
    public static function zonaHoraria(): array
    {
        $r = self::get()->query('SELECT @@session.time_zone AS tz, NOW() AS ahora')->fetch();
        return ['php' => date_default_timezone_get(), 'sesion' => (string)$r['tz'], 'php_offset' => date('P'),
                'diferencia_seg' => abs(strtotime((string)$r['ahora']) - time())];
    }

    /**
     * ¿La base ya tiene lo que exige el Bloque 1.3? (columnas nuevas de usuarios + tablas nuevas).
     * Si no se puede conectar lanza la excepción de PDO; solo devuelve false cuando falta estructura.
     */
    public static function esquemaAlDia(): bool
    {
        $pdo = self::get();
        try {
            $pdo->query('SELECT email, reset_token_hash, reset_token_expira FROM usuarios LIMIT 0')->closeCursor();
            foreach (['configuracion', 'roles', 'permisos', 'rol_permiso', 'recuperacion_intentos'] as $t) {
                $pdo->query('SELECT 1 FROM `' . $t . '` LIMIT 0')->closeCursor();
            }
            return true;
        } catch (PDOException $e) {
            return false;
        }
    }

    /**
     * Ejecuta $fn dentro de una transacción. Si MariaDB aborta por deadlock (1213) o
     * timeout de bloqueo (1205), repite TODA la transacción (incluida la secuencia de ticket).
     * Si ya hay una transacción abierta, simplemente ejecuta $fn dentro de ella.
     */
    public static function transaccion(callable $fn, int $reintentos = 5)
    {
        $pdo = self::get();
        if ($pdo->inTransaction()) return $fn($pdo);

        for ($intento = 1; ; $intento++) {
            $pdo->beginTransaction();
            try {
                $r = $fn($pdo);
                $pdo->commit();
                return $r;
            } catch (Throwable $e) {
                if ($pdo->inTransaction()) $pdo->rollBack();
                $codigo = $e instanceof PDOException ? (int)($e->errorInfo[1] ?? 0) : 0;
                if (in_array($codigo, [1213, 1205], true) && $intento < $reintentos) {
                    usleep(random_int(10, 60) * 1000 * $intento); // espera aleatoria creciente
                    continue;
                }
                throw $e;
            }
        }
    }
}
