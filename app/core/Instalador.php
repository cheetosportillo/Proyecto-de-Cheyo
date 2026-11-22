<?php
/**
 * Lógica del instalador web (Bloque 1.2, ampliada en 1.3). No depende de Database.php ni de la sesión:
 * funciona antes de que exista config/config.local.php. Compatible con PHP 8.0.
 *
 * Diseño para XAMPP *y* hosting compartido (InfinityFree):
 *  - CREATE DATABASE solo se intenta si la base NO existe y la persona lo permite.
 *    Si el hosting ya la entrega creada, se conecta directo y no se ejecuta nada.
 *  - CREATE USER / GRANT son opcionales y solo aplican cuando MariaDB está en este mismo equipo.
 *    Si la cuenta no tiene permiso (hosting compartido), se omiten con un aviso y la app usa
 *    la cuenta que entregó el hosting.
 *  - Los triggers son opcionales: si el hosting no los permite, solo se avisa.
 */
final class Instalador
{
    public const VERSION_ESQUEMA = '1.3.0';
    public const USUARIO_APP     = 'onix_app';

    /** 1 GRANT por tabla (principio de mínimo privilegio). La app nunca hace DELETE ni TRUNCATE. */
    public const PRIVILEGIOS = [
        'bitacora'          => 'SELECT, INSERT',
        'caja_movimientos'  => 'SELECT, INSERT',
        'cajas'             => 'SELECT, INSERT, UPDATE',
        'categorias'        => 'SELECT, INSERT, UPDATE',
        'compras'           => 'SELECT, INSERT, UPDATE (`estado`, `motivo_anulacion`, `id_usuario_anula`, `fecha_anulacion`)',
        'configuracion'     => 'SELECT, INSERT, UPDATE',
        'detalle_compra'    => 'SELECT, INSERT',
        'detalle_venta'     => 'SELECT, INSERT',
        'kardex'            => 'SELECT, INSERT',
        'permisos'          => 'SELECT',
        'productos'         => 'SELECT, INSERT, UPDATE',
        'proveedores'       => 'SELECT, INSERT, UPDATE',
        'recuperacion_intentos' => 'SELECT, INSERT, UPDATE',
        'rol_permiso'       => 'SELECT, INSERT, UPDATE',
        'roles'             => 'SELECT',
        'schema_migrations' => 'SELECT',
        'secuencias'        => 'SELECT, UPDATE',
        'usuarios'          => 'SELECT, INSERT, UPDATE',
        'ventas'            => 'SELECT, INSERT, UPDATE (`estado`, `motivo_anulacion`, `id_usuario_anula`, `fecha_anulacion`, `impresiones`)',
    ];

    /**
     * Columnas que se agregaron DESPUÉS de la 1.2 a tablas que ya existen. schema.sql no puede agregarlas
     * (solo CREATE TABLE IF NOT EXISTS, sin ALTER), así que las agrega migrar() solo si faltan.
     * Deben coincidir con schema.sql (mismo orden: van al final de la tabla).
     */
    public const COLUMNAS_NUEVAS = [
        'usuarios' => [
            'email'              => 'ADD COLUMN `email` varchar(150) DEFAULT NULL',
            'reset_token_hash'   => 'ADD COLUMN `reset_token_hash` varchar(255) DEFAULT NULL',
            'reset_token_expira' => 'ADD COLUMN `reset_token_expira` datetime DEFAULT NULL',
        ],
    ];

    /* ------------------------------------------------------------------ rutas */

    public static function raiz(): string          { return dirname(__DIR__, 2); }
    public static function archivoConfig(): string { return self::raiz() . '/config/config.local.php'; }
    public static function archivoLock(): string   { return self::raiz() . '/config/instalado.lock'; }
    public static function archivoToken(): string  { return self::raiz() . '/config/install.token'; }

    /* ------------------------------------------------------- acceso al instalador */

    /** Local = REMOTE_ADDR de loopback y ninguna cabecera de proxy (un proxy en el mismo equipo no cuenta como local). */
    public static function esLocal(): bool
    {
        $ip = (string)($_SERVER['REMOTE_ADDR'] ?? '');
        if ($ip !== '127.0.0.1' && $ip !== '::1') return false;
        foreach (['HTTP_X_FORWARDED_FOR', 'HTTP_FORWARDED', 'HTTP_X_REAL_IP', 'HTTP_CLIENT_IP'] as $h) {
            if (!empty($_SERVER[$h])) return false;
        }
        return true;
    }

    /**
     * Token de instalación para accesos remotos (hosting). Se crea en config/install.token la primera vez;
     * quien puede leer ese archivo (Administrador de archivos / FTP) demuestra ser dueño del servidor.
     */
    public static function token(): ?string
    {
        $f = self::archivoToken();
        if (is_file($f)) {
            $t = trim((string)@file_get_contents($f));
            return $t !== '' ? $t : null;
        }
        $t = bin2hex(random_bytes(16));
        return @file_put_contents($f, $t . "\n", LOCK_EX) === false ? null : $t;
    }

    public static function tokenValido(string $dado): bool
    {
        $t = self::token();
        $dado = trim($dado);
        return $t !== null && $dado !== '' && hash_equals($t, $dado);
    }

    /* ------------------------------------------------------------------ estado */

    /**
     * 'listo'  -> ya instalado (hay candado o ya existen usuarios; en ese caso se crea el candado).
     * 'cuenta' -> esquema cargado y config lista, falta crear la cuenta de la Dueña.
     * 'bd'     -> falta configurar/cargar la base de datos.
     */
    public static function estado(): string
    {
        if (is_file(self::archivoLock())) return 'listo';
        $cfg = self::leerConfig();
        if ($cfg === null) return 'bd';
        try {
            $pdo = self::conectar($cfg, true);
            $n = (int)$pdo->query('SELECT COUNT(*) FROM usuarios')->fetchColumn();
        } catch (Throwable $e) {
            return 'bd';   // sin conexión o sin tabla usuarios
        }
        if ($n > 0) {
            self::escribirLock();   // instalación previa (p. ej. tu BD de desarrollo): se cierra el instalador
            return 'listo';
        }
        return 'cuenta';
    }

    public static function escribirLock(): void
    {
        @file_put_contents(self::archivoLock(), 'Instalado ' . date('c') . ' · esquema ' . self::VERSION_ESQUEMA . "\n", LOCK_EX);
        @unlink(self::archivoToken());
    }

    public static function leerConfig(): ?array
    {
        $f = self::archivoConfig();
        if (!is_file($f)) return null;
        try {
            $c = (static function (string $archivo) { return include $archivo; })($f);
        } catch (Throwable $e) {
            return null;
        }
        if (!is_array($c)) return null;
        return [
            'host' => (string)($c['host'] ?? '127.0.0.1'),
            'port' => (string)($c['port'] ?? '3306'),
            'name' => (string)($c['name'] ?? 'joyeria_onix'),
            'user' => (string)($c['user'] ?? 'root'),
            'pass' => (string)($c['pass'] ?? ''),
            'entorno' => (string)($c['entorno'] ?? ''),
        ];
    }

    /* -------------------------------------------------------------- conexión */

    public static function conectar(array $c, bool $conBd = true): PDO
    {
        $dsn = 'mysql:host=' . $c['host'] . ';port=' . $c['port']
             . ($conBd ? ';dbname=' . $c['name'] : '') . ';charset=utf8mb4';
        return new PDO($dsn, $c['user'], $c['pass'], [
            PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES   => false,
            PDO::ATTR_TIMEOUT            => 8,
            PDO::MYSQL_ATTR_INIT_COMMAND => "SET time_zone = '-06:00'",   // igual que Database.php (El Salvador)
        ]);
    }

    private static function codigo(Throwable $e): int
    {
        if ($e instanceof PDOException && isset($e->errorInfo[1])) return (int)$e->errorInfo[1];
        if (preg_match('/\[(\d{3,4})\]/', $e->getMessage(), $m)) return (int)$m[1];
        return 0;
    }

    private static function mensajeCorto(Throwable $e): string
    {
        return substr((string)preg_replace('/\s+/', ' ', $e->getMessage()), 0, 220);
    }

    private static function mensajeConexion(Throwable $e): string
    {
        switch (self::codigo($e)) {
            case 1045: return 'Usuario o contraseña de la base de datos incorrectos.';
            case 1044: return 'Ese usuario no tiene acceso a la base indicada. En hosting compartido copia el nombre EXACTO de la base que muestra tu panel.';
            case 2002: case 2003: case 2006:
                return 'No se pudo contactar al servidor de base de datos. Revisa el servidor y el puerto (en XAMPP usa 127.0.0.1 y confirma el puerto en el panel de XAMPP: 3306 o 3307).';
            default:   return 'No se pudo conectar a la base de datos: ' . self::mensajeCorto($e);
        }
    }

    /* ------------------------------------------------------------------ SQL */

    /**
     * Divide un script SQL en sentencias (entiende DELIMITER, sin depender del cliente mysql).
     * Reglas del script: una sentencia termina cuando una línea termina con el delimitador vigente;
     * las líneas que empiezan con "--" o "#" fuera de una sentencia se ignoran.
     */
    public static function dividirSql(string $sql): array
    {
        $sentencias = [];
        $delim = ';';
        $buf = '';
        foreach (preg_split('/\R/', $sql) as $linea) {
            $t = trim($linea);
            if ($buf === '') {
                if ($t === '' || strncmp($t, '--', 2) === 0 || $t[0] === '#') continue;
                if (stripos($t, 'DELIMITER ') === 0) {
                    $delim = trim(substr($t, 10));
                    continue;
                }
            }
            $buf .= $linea . "\n";
            $largo = strlen($delim);
            if ($largo > 0 && substr(rtrim($linea), -$largo) === $delim) {
                $s = trim(substr(rtrim($buf), 0, -$largo));
                if ($s !== '') $sentencias[] = $s;
                $buf = '';
            }
        }
        if (trim($buf) !== '') $sentencias[] = trim($buf);
        return $sentencias;
    }

    /** Ejecuta un .sql sentencia por sentencia con PDO::exec. Devuelve cuántas ejecutó. */
    public static function ejecutarArchivo(PDO $pdo, string $ruta): int
    {
        $sql = @file_get_contents($ruta);
        if ($sql === false) throw new RuntimeException('No se encontró el archivo ' . basename($ruta) . ' en la carpeta del sistema.');
        if (strncmp($sql, "\xEF\xBB\xBF", 3) === 0) $sql = substr($sql, 3);   // BOM
        $n = 0;
        foreach (self::dividirSql($sql) as $s) {
            try {
                $pdo->exec($s);
                $n++;
            } catch (PDOException $e) {
                $ini = substr((string)preg_replace('/\s+/', ' ', $s), 0, 70);
                throw new RuntimeException('Error en ' . basename($ruta) . ' cerca de «' . $ini . '…»: ' . self::mensajeCorto($e), 0, $e);
            }
        }
        return $n;
    }

    /** @return array{0:int,1:array<int,string>} [triggers creados, errores] */
    public static function instalarTriggers(PDO $pdo): array
    {
        $sql = @file_get_contents(self::raiz() . '/schema_triggers.sql');
        if ($sql === false) return [0, ['No se encontró schema_triggers.sql']];
        if (strncmp($sql, "\xEF\xBB\xBF", 3) === 0) $sql = substr($sql, 3);
        $creados = 0;
        $errores = [];
        foreach (self::dividirSql($sql) as $s) {
            $esCrear = stripos(ltrim($s), 'CREATE TRIGGER') === 0;
            try {
                $pdo->exec($s);
                if ($esCrear) $creados++;
            } catch (PDOException $e) {
                if ($esCrear) {
                    $nombre = preg_match('/CREATE TRIGGER\s+`?(\w+)/i', $s, $m) ? $m[1] : 'trigger';
                    $errores[] = $nombre . ' (' . self::codigo($e) . ')';
                }
            }
        }
        return [$creados, $errores];
    }

    /* ------------------------------------------------- migración 1.2 -> 1.3 */

    /**
     * Agrega las columnas de COLUMNAS_NUEVAS que falten (idempotente: segunda ejecución no hace nada).
     * Requiere una cuenta con ALTER (root en XAMPP; el usuario restringido «onix_app» NO la tiene).
     * Las tablas nuevas ya las crea schema.sql (CREATE TABLE IF NOT EXISTS).
     *
     * @return string[] qué se hizo (vacío si ya estaba al día)
     */
    public static function migrar(PDO $pdo): array
    {
        $hecho = [];
        $bd = (string)$pdo->query('SELECT DATABASE()')->fetchColumn();
        foreach (self::COLUMNAS_NUEVAS as $tabla => $cols) {
            $st = $pdo->prepare('SELECT COLUMN_NAME FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = ? AND TABLE_NAME = ?');
            $st->execute([$bd, $tabla]);
            $existentes = array_map('strtolower', $st->fetchAll(PDO::FETCH_COLUMN));
            if ($existentes === []) continue;   // la tabla no existe: la crea schema.sql
            foreach ($cols as $col => $ddl) {
                if (in_array(strtolower($col), $existentes, true)) continue;
                try {
                    $pdo->exec('ALTER TABLE `' . $tabla . '` ' . $ddl);
                } catch (PDOException $e) {
                    throw new RuntimeException('No se pudo agregar ' . $tabla . '.' . $col . ' (error ' . self::codigo($e)
                        . '). Ejecútalo con una cuenta que tenga ALTER (en XAMPP: root). ' . self::mensajeCorto($e), 0, $e);
                }
                $hecho[] = 'Columna ' . $tabla . '.' . $col . ' agregada.';
            }
        }
        return $hecho;
    }

    /**
     * Si el usuario restringido «onix_app» ya existe en este servidor, le (re)aplica los GRANT de PRIVILEGIOS
     * (así recibe las tablas nuevas). Mejor esfuerzo: sin permiso o sin ese usuario, devuelve null sin fallar.
     */
    public static function reaplicarPrivilegios(PDO $pdo, string $bd): ?string
    {
        if (!preg_match('/^[A-Za-z0-9_]{1,64}$/', $bd)) return null;
        try {
            $st = $pdo->prepare('SELECT COUNT(*) FROM mysql.user WHERE User = ?');
            $st->execute([self::USUARIO_APP]);
            if ((int)$st->fetchColumn() === 0) return null;
            foreach (['localhost', '127.0.0.1'] as $h) {
                $q = $pdo->prepare('SELECT COUNT(*) FROM mysql.user WHERE User = ? AND Host = ?');
                $q->execute([self::USUARIO_APP, $h]);
                if ((int)$q->fetchColumn() === 0) continue;
                $u = "'" . self::USUARIO_APP . "'@'" . $h . "'";
                foreach (self::PRIVILEGIOS as $tabla => $privs) {
                    $pdo->exec('GRANT ' . $privs . ' ON `' . $bd . '`.`' . $tabla . '` TO ' . $u);
                }
            }
        } catch (PDOException $e) {
            return null;
        }
        return 'Permisos de «' . self::USUARIO_APP . '» actualizados para las tablas nuevas.';
    }

    /* --------------------------------------------------- paso 1: base de datos */

    /** Valida y normaliza los datos de conexión del formulario. Lanza RuntimeException con mensaje para la persona. */
    public static function validarConexion(array $in): array
    {
        $host = trim((string)($in['host'] ?? ''));
        $port = trim((string)($in['port'] ?? '3306'));
        $name = trim((string)($in['name'] ?? ''));
        $user = trim((string)($in['user'] ?? ''));
        $pass = (string)($in['pass'] ?? '');

        if (!preg_match('/^[A-Za-z0-9._-]{1,255}$/', $host)) throw new RuntimeException('Servidor no válido (solo letras, números, punto y guion).');
        if (!ctype_digit($port) || (int)$port < 1 || (int)$port > 65535) throw new RuntimeException('Puerto no válido.');
        if (!preg_match('/^[A-Za-z0-9_]{1,64}$/', $name)) throw new RuntimeException('Nombre de base no válido (solo letras, números y guion bajo, máx. 64).');
        if ($user === '' || strlen($user) > 80) throw new RuntimeException('Indica el usuario de la base de datos.');
        return ['host' => $host, 'port' => $port, 'name' => $name, 'user' => $user, 'pass' => $pass];
    }

    /**
     * Conecta (creando la base solo si hace falta y está permitido), carga schema.sql, intenta los triggers,
     * crea opcionalmente el usuario restringido y escribe config/config.local.php.
     *
     * @return array{pasos:array,avisos:array,credenciales:?array,config_escrita:bool,config_manual:?string}
     */
    public static function instalarBd(array $in): array
    {
        $c        = self::validarConexion($in);
        $crearBd  = !empty($in['crear_bd']);
        $crearUsr = !empty($in['crear_usuario']);
        $entorno  = (($in['entorno'] ?? '') === 'hosting') ? 'hosting' : 'xampp';
        $pasos = [];
        $avisos = [];

        // 1) Conexión. Primero directo a la base: en hosting compartido ya viene creada y NO se ejecuta CREATE DATABASE.
        try {
            $pdo = self::conectar($c, true);
            $pasos[] = 'Conectado a la base «' . $c['name'] . '» (ya existía: no se intentó crearla).';
        } catch (PDOException $e) {
            if (self::codigo($e) !== 1049) throw new RuntimeException(self::mensajeConexion($e), 0, $e);
            if (!$crearBd) {
                throw new RuntimeException('La base «' . $c['name'] . '» no existe. Activa «Crear la base si no existe» o créala desde el panel de tu hosting.');
            }
            try {
                $srv = self::conectar($c, false);
                $srv->exec('CREATE DATABASE `' . $c['name'] . '` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci');
            } catch (PDOException $e2) {
                throw new RuntimeException('No se pudo crear la base «' . $c['name'] . '»: ' . self::mensajeConexion($e2)
                    . ' Si estás en hosting compartido, créala en el panel del hosting y vuelve a intentar.', 0, $e2);
            }
            $pdo = self::conectar($c, true);
            $pasos[] = 'Base «' . $c['name'] . '» creada.';
        }

        // 2) Versión del servidor (columnas generadas y DEFAULT current_timestamp() exigen MariaDB 10.2+ / MySQL 5.7+).
        $ver = (string)$pdo->query('SELECT VERSION()')->fetchColumn();
        $esMaria = stripos($ver, 'mariadb') !== false;
        $limpia = (string)preg_replace('/^5\.5\.5-/', '', $ver);
        $num = preg_match('/\d+\.\d+(\.\d+)?/', $limpia, $m) ? $m[0] : '0';
        if (version_compare($num, $esMaria ? '10.2' : '5.7', '<')) {
            throw new RuntimeException('Versión de base de datos no soportada (' . $ver . '). Se requiere MariaDB 10.2+ o MySQL 5.7+.');
        }
        $pasos[] = 'Servidor de base de datos: ' . $ver . '.';

        // 3) Esquema (CREATE TABLE IF NOT EXISTS: seguro de repetir).
        $n = self::ejecutarArchivo($pdo, self::raiz() . '/schema.sql');
        $pasos[] = 'Esquema cargado (' . $n . ' sentencias, versión ' . self::VERSION_ESQUEMA . ').';

        // 3b) Base creada con una versión anterior: agrega las columnas nuevas (no-op en una instalación nueva).
        foreach (self::migrar($pdo) as $m) $pasos[] = $m;

        // 4) Triggers: mejor esfuerzo.
        [$creados, $errTrig] = self::instalarTriggers($pdo);
        if ($errTrig === []) {
            $pasos[] = 'Protecciones de la base activadas (' . $creados . ' triggers: kardex/bitácora inmutables y venta solo con caja abierta).';
        } else {
            $avisos[] = 'No se pudieron crear algunos triggers (' . implode(', ', $errTrig) . '). Es común en hosting compartido. '
                . 'El sistema funciona igual: esas reglas ya se validan en la aplicación.';
        }

        // 5) Usuario restringido (opcional; solo si MariaDB está en este equipo y la cuenta tiene permiso).
        $cfgApp = $c;
        $cred = null;
        if ($crearUsr) {
            $local = in_array(strtolower($c['host']), ['localhost', '127.0.0.1'], true);
            if (!$local) {
                $avisos[] = 'Usuario restringido omitido: solo se crea cuando MariaDB está en este mismo equipo. La app usará la cuenta indicada.';
            } elseif ($c['user'] === self::USUARIO_APP) {
                $pasos[] = 'Ya estás usando el usuario restringido «' . self::USUARIO_APP . '»: no se crea otro.';
            } else {
                $pass = bin2hex(random_bytes(24));
                try {
                    self::crearUsuarioApp($pdo, $c['name'], $pass);
                    $problema = self::verificarUsuarioApp($c, $pass);
                    if ($problema === null) {
                        $cfgApp['user'] = self::USUARIO_APP;
                        $cfgApp['pass'] = $pass;
                        $cred = ['usuario' => self::USUARIO_APP, 'clave' => $pass];
                        $pasos[] = 'Usuario restringido «' . self::USUARIO_APP . '» creado y verificado (1 GRANT por tabla; sin DELETE/TRUNCATE).';
                    } else {
                        self::eliminarUsuarioApp($pdo);
                        $avisos[] = 'Se revirtió el usuario restringido: ' . $problema . ' La app usará la cuenta indicada.';
                    }
                } catch (PDOException $e) {
                    $avisos[] = 'Usuario restringido omitido: la cuenta «' . $c['user'] . '» no puede crear usuarios ni otorgar permisos (error '
                        . self::codigo($e) . '; normal en hosting compartido). La app usará la cuenta indicada.';
                }
            }
        } else {
            $pasos[] = 'Usuario restringido: omitido por elección (la app usará la cuenta indicada).';
            $actualizado = self::reaplicarPrivilegios($pdo, $c['name']);   // reinstalación sobre una base que ya tenía onix_app
            if ($actualizado !== null) $pasos[] = $actualizado;
        }

        // 6) config/config.local.php
        $cfg = [
            'host' => $cfgApp['host'], 'port' => $cfgApp['port'], 'name' => $cfgApp['name'],
            'user' => $cfgApp['user'], 'pass' => $cfgApp['pass'],
            'entorno' => $entorno, 'instalado_en' => date('c'),
        ];
        $contenido = self::contenidoConfig($cfg);
        $escrita = self::escribirConfig($contenido);
        if ($escrita) {
            $pasos[] = 'Configuración guardada en config/config.local.php.';
        } else {
            $avisos[] = 'No se pudo escribir config/config.local.php (permisos de la carpeta). Crea ese archivo a mano con el contenido mostrado.';
        }

        return [
            'pasos' => $pasos, 'avisos' => $avisos, 'credenciales' => $cred,
            'config_escrita' => $escrita, 'config_manual' => $escrita ? null : $contenido,
        ];
    }

    public static function contenidoConfig(array $cfg): string
    {
        return "<?php\n// Generado por el instalador web de Joyería Onix (" . date('Y-m-d H:i') . ").\n"
             . "// Contiene credenciales: está en .gitignore y config/.htaccess lo bloquea desde el navegador.\n"
             . 'return ' . var_export($cfg, true) . ";\n";
    }

    public static function escribirConfig(string $contenido): bool
    {
        $ruta = self::archivoConfig();
        $tmp = $ruta . '.tmp' . bin2hex(random_bytes(4));
        if (@file_put_contents($tmp, $contenido, LOCK_EX) === false) return false;
        if (!@rename($tmp, $ruta)) {
            $ok = @file_put_contents($ruta, $contenido, LOCK_EX) !== false;
            @unlink($tmp);
            if (!$ok) return false;
        }
        if (function_exists('opcache_invalidate')) @opcache_invalidate($ruta, true);
        return true;
    }

    /* ------------------------------------------------- usuario restringido (N4) */

    private static function crearUsuarioApp(PDO $pdo, string $bd, string $clave): void
    {
        // $bd pasó por validarConexion() (solo [A-Za-z0-9_]) y $clave es hexadecimal: seguros de interpolar.
        foreach (['localhost', '127.0.0.1'] as $h) {
            $u = "'" . self::USUARIO_APP . "'@'" . $h . "'";
            $pdo->exec('DROP USER IF EXISTS ' . $u);
            $pdo->exec('CREATE USER ' . $u . " IDENTIFIED BY '" . $clave . "'");
            foreach (self::PRIVILEGIOS as $tabla => $privs) {
                $pdo->exec('GRANT ' . $privs . ' ON `' . $bd . '`.`' . $tabla . '` TO ' . $u);
            }
        }
    }

    private static function eliminarUsuarioApp(PDO $pdo): void
    {
        foreach (['localhost', '127.0.0.1'] as $h) {
            try { $pdo->exec("DROP USER IF EXISTS '" . self::USUARIO_APP . "'@'" . $h . "'"); } catch (PDOException $e) { /* nada que revertir */ }
        }
    }

    /** null = todo bien; texto = qué falló. Prueba lo que el código realmente hace (FOR UPDATE) y lo que NO debe poder hacer. */
    private static function verificarUsuarioApp(array $c, string $clave): ?string
    {
        $cu = $c;
        $cu['user'] = self::USUARIO_APP;
        $cu['pass'] = $clave;
        try {
            $p = self::conectar($cu, true);
        } catch (PDOException $e) {
            return 'no se pudo iniciar sesión con «' . self::USUARIO_APP . '» (' . self::mensajeCorto($e) . ').';
        }
        foreach (['cajas', 'productos', 'ventas', 'compras', 'kardex', 'caja_movimientos'] as $t) {
            try {
                $p->beginTransaction();
                $p->query('SELECT 1 FROM `' . $t . '` LIMIT 1 FOR UPDATE')->fetchAll();
                $p->rollBack();
            } catch (PDOException $e) {
                if ($p->inTransaction()) $p->rollBack();
                return 'tu servidor no permite SELECT … FOR UPDATE sobre «' . $t . '» con los permisos mínimos (' . self::codigo($e) . ').';
            }
        }
        try {
            $p->exec('DELETE FROM `kardex` WHERE 1 = 0');
            return 'el usuario restringido todavía puede borrar en kardex (los permisos no se aplicaron como se esperaba).';
        } catch (PDOException $e) {
            // esperado: sin privilegio DELETE
        }
        return null;
    }

    /* -------------------------------------------- paso 2: cuenta de la Dueña */

    /**
     * Crea la cuenta real de la Dueña dentro de una transacción que exige COUNT(usuarios) = 0,
     * genera su código de recuperación offline (solo se guarda el hash) y cierra el instalador.
     *
     * @return array{codigo:string,usuario:string,nombre:string}
     */
    public static function crearDuena(array $in): array
    {
        $nombre   = trim((string)($in['nombre'] ?? ''));
        $usuario  = trim((string)($in['usuario'] ?? ''));
        $pass     = (string)($in['password'] ?? '');
        $confirma = (string)($in['password2'] ?? '');
        $email    = trim((string)($in['email'] ?? ''));

        if ($nombre === '' || (int)preg_match_all('/./us', $nombre) > 100) throw new RuntimeException('Escribe el nombre completo de la Dueña (máx. 100 caracteres).');
        if (!preg_match('/^[A-Za-z0-9._-]{3,30}$/', $usuario)) throw new RuntimeException('El usuario debe tener 3-30 caracteres (letras, números, . _ -).');
        if (strlen($pass) < 8) throw new RuntimeException('La contraseña debe tener al menos 8 caracteres.');
        if (strlen($pass) > 72) throw new RuntimeException('La contraseña no puede superar 72 bytes.');
        if (!hash_equals($pass, $confirma)) throw new RuntimeException('Las contraseñas no coinciden.');
        if ($email !== '' && (strlen($email) > 150 || !filter_var($email, FILTER_VALIDATE_EMAIL))) {
            throw new RuntimeException('El correo no es válido (déjalo vacío si no quieres registrarlo ahora).');
        }

        $cfg = self::leerConfig();
        if ($cfg === null) throw new RuntimeException('Falta config/config.local.php: completa primero el paso de la base de datos.');
        try {
            $pdo = self::conectar($cfg, true);
        } catch (PDOException $e) {
            throw new RuntimeException(self::mensajeConexion($e), 0, $e);
        }

        [$codigo, $hashCodigo] = Recuperacion::generar();
        $hashPass = password_hash($pass, PASSWORD_DEFAULT);

        $pdo->beginTransaction();
        try {
            $n = (int)$pdo->query('SELECT COUNT(*) FROM usuarios FOR UPDATE')->fetchColumn();
            if ($n > 0) throw new RuntimeException('El sistema ya tiene usuarios: la instalación ya se completó.');

            $pdo->prepare("INSERT INTO usuarios (nombre, usuario, password, email, rol, estado, codigo_recuperacion_hash, debe_cambiar_password)
                           VALUES (?,?,?,?,'duena',1,?,0)")->execute([$nombre, $usuario, $hashPass, $email !== '' ? $email : null, $hashCodigo]);
            $idUsuario = (int)$pdo->lastInsertId();

            $pdo->prepare('INSERT INTO bitacora (id_usuario, accion, detalle) VALUES (?,?,?)')
                ->execute([$idUsuario, 'INSTALACION', 'Instalación completada. Cuenta de la Dueña creada: ' . $usuario . '. Esquema ' . self::VERSION_ESQUEMA . '.']);
            $pdo->commit();
        } catch (Throwable $e) {
            if ($pdo->inTransaction()) $pdo->rollBack();
            throw $e;
        }

        self::escribirLock();
        return ['codigo' => $codigo, 'usuario' => $usuario, 'nombre' => $nombre];
    }
}
