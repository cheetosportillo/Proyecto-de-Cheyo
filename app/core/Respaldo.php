<?php
require_once __DIR__ . '/Instalador.php';

/**
 * Respaldos .sql en PHP puro vía PDO (sin mysqldump: funciona igual en XAMPP y en hosting compartido).
 *
 *  - Instantánea consistente (START TRANSACTION WITH CONSISTENT SNAPSHOT): se puede seguir vendiendo mientras se respalda.
 *  - Por cada tabla: DROP TABLE IF EXISTS + CREATE TABLE + INSERT por lotes. Las columnas generadas (cajas.abierta_unica)
 *    se omiten. Cada valor va escapado en UNA línea, así que Instalador::ejecutarArchivo() también puede restaurarlo.
 *  - NO incluye triggers (la cuenta restringida no los puede leer): tras restaurar en una base vacía, vuelve a
 *    instalarlos con schema_triggers.sql (ver docs/INSTALACION.md).
 *  - Se escribe a un .part y solo se renombra al terminar: nunca queda un respaldo a medias con nombre válido.
 *  - Carpeta backups/ (ONIX_BACKUP_DIR la cambia): ignorada por git y con .htaccess «Require all denied».
 */
final class Respaldo
{
    public const PATRON = '/^respaldo_[A-Za-z0-9_]{1,64}_\d{8}_\d{6}_[a-f0-9]{6}\.sql$/';
    public const MARCA_FIN = '-- FIN DEL RESPALDO';

    private const FILAS_POR_INSERT  = 100;
    private const BYTES_POR_INSERT  = 262144;
    /** Datos efímeros: solo se respalda su estructura. */
    private const SIN_DATOS = ['recuperacion_intentos'];

    public static function dir(): string
    {
        $d = getenv('ONIX_BACKUP_DIR');
        return ($d !== false && $d !== '') ? rtrim($d, '/\\') : dirname(__DIR__, 2) . '/backups';
    }

    /** Crea la carpeta y sus protecciones si faltan. @throws RuntimeException */
    public static function prepararDir(): string
    {
        $d = self::dir();
        if (!is_dir($d) && !@mkdir($d, 0750, true) && !is_dir($d)) {
            throw new RuntimeException('No se pudo crear la carpeta backups/ (revisa los permisos de la carpeta del sistema).');
        }
        if (!is_writable($d)) throw new RuntimeException('La carpeta backups/ no tiene permiso de escritura.');
        if (!is_file($d . '/.htaccess')) {
            @file_put_contents($d . '/.htaccess', "# Respaldos: NUNCA deben servirse por web.\n<IfModule mod_authz_core.c>\n    Require all denied\n</IfModule>\n"
                . "<IfModule !mod_authz_core.c>\n    Order allow,deny\n    Deny from all\n</IfModule>\n");
        }
        if (!is_file($d . '/index.html')) @file_put_contents($d . '/index.html', '');
        return $d;
    }

    /** Qué cuenta se usará, sin conectar (para mostrarlo en pantalla). */
    public static function cuentaConfigurada(): string
    {
        $c = Instalador::leerConfigCrudo();
        return (is_array($c) && !empty($c['backup_user']))
            ? (string)$c['backup_user'] . ' (solo lectura)'
            : DB_USER . ' (cuenta de la aplicación)';
    }

    /**
     * Conexión para respaldar: la cuenta de solo lectura «onix_backup» si existe y funciona; si no, la de la app.
     * @return array{0:PDO,1:string} [conexión, descripción de la cuenta usada]
     */
    public static function conexion(): array
    {
        $c = Instalador::leerConfigCrudo();
        if (is_array($c) && !empty($c['backup_user']) && isset($c['backup_pass'])
            && (string)($c['name'] ?? '') === DB_NAME && (string)($c['host'] ?? '') === DB_HOST && (string)($c['port'] ?? '3306') === (string)DB_PORT) {
            try {
                $pdo = new PDO('mysql:host=' . DB_HOST . ';port=' . DB_PORT . ';dbname=' . DB_NAME . ';charset=' . DB_CHARSET,
                    (string)$c['backup_user'], (string)$c['backup_pass'], [
                        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                        PDO::ATTR_EMULATE_PREPARES => false,
                    ]);
                return [$pdo, (string)$c['backup_user'] . ' (solo lectura)'];
            } catch (PDOException $e) {
                error_log('[respaldo] onix_backup no disponible, se usa la cuenta de la app: ' . $e->getMessage());
                return [Database::get(), 'cuenta de la aplicación (onix_backup no respondió)'];
            }
        }
        return [Database::get(), 'cuenta de la aplicación'];
    }

    /**
     * Genera un respaldo completo en backups/.
     * @return array{archivo:string,bytes:int,tablas:int,filas:int}
     * @throws RuntimeException con mensaje apto para mostrar
     */
    public static function generar(PDO $pdo): array
    {
        if ($pdo->inTransaction()) throw new RuntimeException('Hay una transacción abierta; inténtalo de nuevo.');
        $dir = self::prepararDir();
        @set_time_limit(0);

        $lock = @fopen($dir . '/.lock', 'c');
        if (!$lock || !flock($lock, LOCK_EX | LOCK_NB)) {
            if ($lock) fclose($lock);
            throw new RuntimeException('Ya hay un respaldo en curso. Espera a que termine.');
        }
        $tmp = null;
        $fp = null;
        try {
            $bd = (string)$pdo->query('SELECT DATABASE()')->fetchColumn();
            $nombre = sprintf('respaldo_%s_%s_%s.sql', preg_replace('/[^A-Za-z0-9_]/', '', $bd) ?: 'bd', date('Ymd_His'), bin2hex(random_bytes(3)));
            $final = $dir . '/' . $nombre;
            $tmp = $final . '.part';
            $fp = @fopen($tmp, 'wb');
            if (!$fp) throw new RuntimeException('No se pudo crear el archivo de respaldo (permisos o disco lleno).');
            @chmod($tmp, 0640);

            [$nt, $nf] = self::volcar($pdo, $fp, $bd);
            if (!fclose($fp)) { $fp = null; throw new RuntimeException('No se pudo terminar de escribir el respaldo (¿disco lleno?).'); }
            $fp = null;
            if (!@rename($tmp, $final)) throw new RuntimeException('No se pudo guardar el respaldo en backups/.');
            $tmp = null;
            return ['archivo' => $nombre, 'bytes' => (int)filesize($final), 'tablas' => $nt, 'filas' => $nf];
        } catch (Throwable $e) {
            if (is_resource($fp)) fclose($fp);
            if ($tmp !== null) @unlink($tmp);
            if ($e instanceof RuntimeException) throw $e;
            error_log('[respaldo] ' . $e);
            throw new RuntimeException('No se pudo generar el respaldo (' . ($e instanceof PDOException ? 'error de base de datos ' . $e->getCode() : get_class($e)) . ').', 0, $e);
        } finally {
            flock($lock, LOCK_UN);
            fclose($lock);
        }
    }

    /** @return array{0:int,1:int} [tablas, filas] */
    private static function volcar(PDO $pdo, $fp, string $bd): array
    {
        $pdo->exec("SET SESSION time_zone = '-06:00'");
        $pdo->exec('SET SESSION TRANSACTION ISOLATION LEVEL REPEATABLE READ');
        $pdo->exec('START TRANSACTION WITH CONSISTENT SNAPSHOT');
        $tablas = 0;
        $filas = 0;
        try {
            $lista = $pdo->query("SHOW FULL TABLES WHERE Table_type = 'BASE TABLE'")->fetchAll(PDO::FETCH_NUM);
            self::escribir($fp, "-- Joyería Onix · respaldo de la base de datos\n"
                . '-- Base: ' . $bd . "\n"
                . '-- Generado: ' . date('Y-m-d H:i:s') . " (hora de El Salvador)\n"
                . '-- Esquema: ' . Instalador::VERSION_ESQUEMA . "\n"
                . "-- Contiene datos sensibles (hashes de contraseñas, correos, ventas): guárdalo fuera del equipo de la tienda.\n"
                . "-- No incluye triggers: tras restaurar en una base vacía ejecuta schema_triggers.sql (ver docs/INSTALACION.md).\n"
                . "-- La contraseña SMTP va cifrada con config/app.key: respalda también ese archivo.\n\n"
                . "SET NAMES utf8mb4;\nSET FOREIGN_KEY_CHECKS=0;\nSET UNIQUE_CHECKS=0;\nSET SQL_MODE='NO_AUTO_VALUE_ON_ZERO';\nSET time_zone='-06:00';\n\n");
            foreach ($lista as $fila) {
                $t = (string)$fila[0];
                $tq = '`' . str_replace('`', '``', $t) . '`';
                $crear = $pdo->query('SHOW CREATE TABLE ' . $tq)->fetch(PDO::FETCH_NUM);
                self::escribir($fp, '-- Tabla ' . $t . "\nDROP TABLE IF EXISTS " . $tq . ";\n" . $crear[1] . ";\n\n");
                $tablas++;
                if (!in_array($t, self::SIN_DATOS, true)) $filas += self::volcarDatos($pdo, $fp, $t, $tq);
            }
            self::escribir($fp, "SET FOREIGN_KEY_CHECKS=1;\nSET UNIQUE_CHECKS=1;\n\n" . self::MARCA_FIN . ' · tablas: ' . $tablas . ' · filas: ' . $filas . "\n");
        } finally {
            try { $pdo->exec('ROLLBACK'); } catch (Throwable $e) { /* solo lecturas */ }
        }
        return [$tablas, $filas];
    }

    private static function volcarDatos(PDO $pdo, $fp, string $t, string $tq): int
    {
        $sel = [];
        $bin = [];
        foreach ($pdo->query('SHOW COLUMNS FROM ' . $tq)->fetchAll() as $c) {
            if (stripos((string)$c['Extra'], 'GENERATED') !== false) continue;   // se recalcula sola
            $sel[] = '`' . str_replace('`', '``', $c['Field']) . '`';
            $bin[] = (bool)preg_match('/blob|binary/i', (string)$c['Type']);
        }
        if (!$sel) return 0;
        $cols = implode(', ', $sel);
        $lote = [];
        $bytes = 0;
        $total = 0;
        $vaciar = function () use (&$lote, &$bytes, $fp, $tq, $cols): void {
            if (!$lote) return;
            self::escribir($fp, 'INSERT INTO ' . $tq . ' (' . $cols . ") VALUES\n" . implode(",\n", $lote) . ";\n");
            $lote = [];
            $bytes = 0;
        };

        $pdo->setAttribute(PDO::MYSQL_ATTR_USE_BUFFERED_QUERY, false);   // no cargar tablas enormes en memoria
        $st = null;
        try {
            $st = $pdo->query('SELECT ' . $cols . ' FROM ' . $tq);
            while (($f = $st->fetch(PDO::FETCH_NUM)) !== false) {
                $v = [];
                foreach ($f as $i => $x) {
                    if ($x === null) $v[] = 'NULL';
                    elseif ($bin[$i]) $v[] = $x === '' ? "''" : '0x' . bin2hex((string)$x);
                    else $v[] = $pdo->quote((string)$x);   // escapa \n, \r, comillas: cada fila queda en una sola línea
                }
                $r = '(' . implode(',', $v) . ')';
                $lote[] = $r;
                $bytes += strlen($r);
                $total++;
                if (count($lote) >= self::FILAS_POR_INSERT || $bytes >= self::BYTES_POR_INSERT) $vaciar();
            }
            $vaciar();
        } finally {
            if ($st) $st->closeCursor();
            $pdo->setAttribute(PDO::MYSQL_ATTR_USE_BUFFERED_QUERY, true);
        }
        self::escribir($fp, "\n");
        return $total;
    }

    private static function escribir($fp, string $s): void
    {
        if ($s === '') return;
        $n = @fwrite($fp, $s);
        if ($n === false || $n !== strlen($s)) throw new RuntimeException('No se pudo escribir el respaldo (¿disco lleno?).');
    }

    /* --------------------------------------------------------------- listado */

    /** @return array<int,array{archivo:string,bytes:int,fecha:int,completo:bool}> más recientes primero */
    public static function listar(): array
    {
        $d = self::dir();
        if (!is_dir($d)) return [];
        $r = [];
        foreach (scandir($d) ?: [] as $f) {
            if (!preg_match(self::PATRON, $f)) continue;
            $ruta = $d . '/' . $f;
            if (!is_file($ruta)) continue;
            $r[] = ['archivo' => $f, 'bytes' => (int)filesize($ruta), 'fecha' => (int)filemtime($ruta), 'completo' => self::esCompleto($ruta)];
        }
        usort($r, fn($a, $b) => [$b['fecha'], $b['archivo']] <=> [$a['fecha'], $a['archivo']]);
        return $r;
    }

    /** Ruta absoluta de un respaldo listado, o null (nombre inválido o inexistente: sin recorrer rutas). */
    public static function ruta(string $archivo): ?string
    {
        if (!preg_match(self::PATRON, $archivo)) return null;
        $p = self::dir() . '/' . $archivo;
        return is_file($p) ? $p : null;
    }

    /** ¿Termina con la marca de fin? (un archivo cortado no la tiene) */
    public static function esCompleto(string $ruta): bool
    {
        $fp = @fopen($ruta, 'rb');
        if (!$fp) return false;
        $tam = (int)filesize($ruta);
        if ($tam > 300) fseek($fp, -300, SEEK_END);
        $cola = (string)fread($fp, 300);
        fclose($fp);
        return str_contains($cola, self::MARCA_FIN);
    }
}
