<?php
/**
 * Cliente SMTP mínimo (sin librerías y SIN usar mail() de PHP): funciona igual en XAMPP y en hosting compartido.
 *
 *  - Cifrado: 'tls' = STARTTLS (puerto 587, el recomendado), 'ssl' = TLS implícito (465), 'none' = sin cifrar.
 *    En InfinityFree solo está abierto el puerto 587 (el 25, 465 y 2525 están bloqueados): usa 587 + 'tls'.
 *  - Autenticación AUTH LOGIN o AUTH PLAIN. Gmail y similares exigen una «contraseña de aplicación».
 *  - Todos los errores se lanzan como RuntimeException con un mensaje seguro para mostrar: nunca incluyen la
 *    contraseña ni el contenido de los comandos de autenticación.
 * Compatible con PHP 8.0.
 */
final class Mailer
{
    private const TIMEOUT = 12;

    /**
     * Configuración guardada (descifrada) lista para enviar, o null si falta lo mínimo.
     * @return array{host:string,puerto:int,cifrado:string,usuario:string,password:string,remitente:string,remitente_nombre:string,verificar_ssl:bool}|null
     */
    public static function configuracion(): ?array
    {
        $host = trim((string)Config::obtener('smtp_host', ''));
        if ($host === '') return null;
        $usuario = (string)Config::obtener('smtp_usuario', '');
        $remit = trim((string)Config::obtener('smtp_remitente', ''));
        if ($remit === '' && filter_var($usuario, FILTER_VALIDATE_EMAIL)) $remit = $usuario;
        if (!filter_var($remit, FILTER_VALIDATE_EMAIL)) return null;
        return [
            'host'             => $host,
            'puerto'           => (int)Config::obtener('smtp_puerto', 587),
            'cifrado'          => (string)Config::obtener('smtp_cifrado', 'tls'),
            'usuario'          => $usuario,
            'password'         => (string)Config::obtener('smtp_password', ''),
            'remitente'        => $remit,
            'remitente_nombre' => (string)Config::obtener('smtp_remitente_nombre', (string)Config::obtener('negocio_nombre', APP_NAME)),
            'verificar_ssl'    => (string)Config::obtener('smtp_verificar_ssl', '1') !== '0',
        ];
    }

    public static function listo(): bool { return self::configuracion() !== null; }

    /**
     * Envía un correo de texto plano UTF-8.
     * @param array|null $cfg misma forma que configuracion(); null = la guardada
     * @throws RuntimeException
     */
    public static function enviar(string $para, string $asunto, string $texto, ?array $cfg = null): void
    {
        $cfg = $cfg ?? self::configuracion();
        if ($cfg === null) throw new RuntimeException('El correo (SMTP) no está configurado.');
        if (!filter_var($para, FILTER_VALIDATE_EMAIL)) throw new RuntimeException('La dirección de destino no es válida.');
        if (!in_array($cfg['cifrado'], ['tls', 'ssl', 'none'], true)) throw new RuntimeException('Cifrado SMTP no válido.');
        if ($cfg['puerto'] < 1 || $cfg['puerto'] > 65535) throw new RuntimeException('Puerto SMTP no válido.');

        $mensaje = self::armarMensaje($cfg['remitente'], $cfg['remitente_nombre'], $para, $asunto, $texto);
        $fp = self::conectar($cfg);
        try {
            self::leerRespuesta($fp, [220], 'El servidor SMTP no respondió como se esperaba');
            $ehlo = self::ehlo($fp);

            if ($cfg['cifrado'] === 'tls') {
                self::comando($fp, 'STARTTLS', [220], 'El servidor no aceptó STARTTLS (¿puerto/cifrado equivocados?)');
                self::activarTls($fp);
                $ehlo = self::ehlo($fp);   // tras STARTTLS hay que volver a presentarse
            }
            if ($cfg['usuario'] !== '') self::autenticar($fp, $ehlo, $cfg['usuario'], $cfg['password']);

            self::comando($fp, 'MAIL FROM:<' . $cfg['remitente'] . '>', [250], 'El servidor rechazó el remitente');
            self::comando($fp, 'RCPT TO:<' . $para . '>', [250, 251], 'El servidor rechazó el destinatario');
            self::comando($fp, 'DATA', [354], 'El servidor no aceptó el mensaje');
            self::escribir($fp, $mensaje . ".\r\n");
            self::leerRespuesta($fp, [250], 'El servidor no aceptó el mensaje');
            try { self::escribir($fp, "QUIT\r\n"); } catch (Throwable $e) { /* ya se envió */ }
        } finally {
            if (is_resource($fp)) fclose($fp);
        }
    }

    /**
     * Cabeceras + cuerpo (con CRLF y "dot-stuffing"), sin el punto final. Público para poder probarlo.
     * Las cabeceras se sanean contra inyección (CR/LF) y los textos no ASCII se codifican (RFC 2047).
     */
    public static function armarMensaje(string $de, string $nombreDe, string $para, string $asunto, string $texto): string
    {
        $dominio = (string)substr(strrchr($de, '@') ?: '@localhost', 1);
        $cab = [
            'Date: ' . date('r'),
            'From: ' . self::direccion($nombreDe, $de),
            'To: <' . self::limpiarCabecera($para) . '>',
            'Subject: ' . self::cabecera($asunto),
            'Message-ID: <' . bin2hex(random_bytes(12)) . '@' . self::limpiarCabecera($dominio) . '>',
            'MIME-Version: 1.0',
            'Content-Type: text/plain; charset=UTF-8',
            'Content-Transfer-Encoding: base64',
            'X-Mailer: Joyeria Onix',
        ];
        $cuerpo = chunk_split(base64_encode($texto), 76, "\r\n");
        $todo = implode("\r\n", $cab) . "\r\n\r\n" . $cuerpo;
        return str_replace("\r\n.", "\r\n..", $todo);
    }

    /* ------------------------------------------------------------ protocolo */

    private static function conectar(array $cfg)
    {
        $ctx = stream_context_create(['ssl' => self::opcionesSsl($cfg['host'], $cfg['verificar_ssl'])]);
        $destino = ($cfg['cifrado'] === 'ssl' ? 'ssl://' : 'tcp://') . $cfg['host'] . ':' . $cfg['puerto'];
        $errno = 0;
        $errstr = '';
        $fp = @stream_socket_client($destino, $errno, $errstr, self::TIMEOUT, STREAM_CLIENT_CONNECT, $ctx);
        if (!$fp) {
            $msg = 'No se pudo conectar a ' . $cfg['host'] . ':' . $cfg['puerto'] . ($errno ? ' (error ' . $errno . ')' : '') . '.';
            if ($cfg['cifrado'] === 'ssl' && stripos($errstr, 'certificate') !== false) {
                $msg .= ' Falló la verificación del certificado: revisa el servidor o desmarca «Verificar certificado» (menos seguro).';
            } else {
                $msg .= ' Revisa servidor y puerto. En InfinityFree solo funciona el puerto 587 con cifrado TLS.';
            }
            throw new RuntimeException($msg);
        }
        stream_set_timeout($fp, self::TIMEOUT);
        return $fp;
    }

    private static function opcionesSsl(string $host, bool $verificar): array
    {
        return [
            'verify_peer'       => $verificar,
            'verify_peer_name'  => $verificar,
            'allow_self_signed' => !$verificar,
            'peer_name'         => $host,
            'SNI_enabled'       => true,
        ];
    }

    /** Las opciones de verificación ya viajan en el contexto con el que se abrió el socket (conectar()). */
    private static function activarTls($fp): void
    {
        $ok = @stream_socket_enable_crypto($fp, true, STREAM_CRYPTO_METHOD_TLS_CLIENT);
        if ($ok !== true) {
            $err = strtolower((string)(error_get_last()['message'] ?? ''));
            $msg = 'No se pudo establecer la conexión segura (TLS).';
            if (str_contains($err, 'certificate')) {
                $msg .= ' El certificado del servidor no se pudo verificar: si estás en XAMPP, configura openssl.cafile en php.ini o desmarca «Verificar certificado» (menos seguro).';
            }
            throw new RuntimeException($msg);
        }
    }

    private static function ehlo($fp): string
    {
        $nombre = (string)preg_replace('/[^A-Za-z0-9.-]/', '', (string)gethostname());
        if ($nombre === '') $nombre = 'localhost';
        return self::comando($fp, 'EHLO ' . $nombre, [250], 'El servidor no aceptó la presentación (EHLO)');
    }

    private static function autenticar($fp, string $ehlo, string $usuario, string $password): void
    {
        $falla = 'Autenticación rechazada: revisa usuario y contraseña (en Gmail se necesita una «contraseña de aplicación»)';
        if (preg_match('/^\d{3}[ -]AUTH[ =].*\bLOGIN\b/im', $ehlo)) {
            self::comando($fp, 'AUTH LOGIN', [334], 'El servidor no aceptó AUTH LOGIN');
            self::comando($fp, base64_encode($usuario), [334], $falla);
            self::comando($fp, base64_encode($password), [235], $falla, true);
        } elseif (preg_match('/^\d{3}[ -]AUTH[ =].*\bPLAIN\b/im', $ehlo)) {
            self::comando($fp, 'AUTH PLAIN ' . base64_encode("\0" . $usuario . "\0" . $password), [235], $falla, true);
        } else {
            throw new RuntimeException('El servidor no ofrece autenticación LOGIN ni PLAIN. Revisa el cifrado (con TLS suele aparecer tras STARTTLS).');
        }
    }

    /** Envía una línea y valida el código de respuesta. $secreto: no incluir detalles del servidor en el error. */
    private static function comando($fp, string $linea, array $esperados, string $falla, bool $secreto = false): string
    {
        self::escribir($fp, $linea . "\r\n");
        return self::leerRespuesta($fp, $esperados, $falla, $secreto);
    }

    private static function escribir($fp, string $datos): void
    {
        $total = strlen($datos);
        $hecho = 0;
        while ($hecho < $total) {
            $n = @fwrite($fp, substr($datos, $hecho));
            if ($n === false || $n === 0) throw new RuntimeException('Se perdió la conexión con el servidor SMTP.');
            $hecho += $n;
        }
    }

    /** Lee una respuesta (posiblemente de varias líneas "250-...") y devuelve su texto completo. */
    private static function leerRespuesta($fp, array $esperados, string $falla, bool $secreto = false): string
    {
        $texto = '';
        $codigo = 0;
        while (true) {
            $linea = fgets($fp, 2048);
            if ($linea === false) {
                $meta = stream_get_meta_data($fp);
                throw new RuntimeException(!empty($meta['timed_out'])
                    ? 'El servidor SMTP tardó demasiado en responder.'
                    : 'Se perdió la conexión con el servidor SMTP.');
            }
            $texto .= $linea;
            if (strlen($linea) >= 4 && ctype_digit(substr($linea, 0, 3))) {
                $codigo = (int)substr($linea, 0, 3);
                if ($linea[3] !== '-') break;
            } else {
                break;
            }
        }
        if (!in_array($codigo, $esperados, true)) {
            $detalle = $secreto ? '' : ' «' . substr(trim(preg_replace('/\s+/', ' ', $texto)), 0, 120) . '»';
            throw new RuntimeException($falla . ' (código ' . $codigo . ').' . $detalle);
        }
        return $texto;
    }

    /* ------------------------------------------------------------ cabeceras */

    private static function limpiarCabecera(string $s): string
    {
        return trim((string)preg_replace('/[\r\n\0]+/', ' ', $s));
    }

    /** ASCII imprimible tal cual; si no, palabras codificadas RFC 2047 (UTF-8, base64, trozos <= 30 bytes). */
    private static function cabecera(string $s): string
    {
        $s = self::limpiarCabecera($s);
        if (preg_match('/^[\x20-\x7E]*$/', $s)) return $s;
        $trozos = [];
        $actual = '';
        foreach (preg_split('//u', $s, -1, PREG_SPLIT_NO_EMPTY) ?: [] as $c) {
            if ($actual !== '' && strlen($actual . $c) > 30) {
                $trozos[] = $actual;
                $actual = '';
            }
            $actual .= $c;
        }
        if ($actual !== '') $trozos[] = $actual;
        return implode("\r\n ", array_map(fn($t) => '=?UTF-8?B?' . base64_encode($t) . '?=', $trozos));
    }

    private static function direccion(string $nombre, string $correo): string
    {
        $nombre = self::limpiarCabecera($nombre);
        $correo = self::limpiarCabecera($correo);
        if ($nombre === '') return '<' . $correo . '>';
        if (!preg_match('/^[\x20-\x7E]*$/', $nombre)) return self::cabecera($nombre) . ' <' . $correo . '>';
        return '"' . addcslashes($nombre, '"\\') . '" <' . $correo . '>';
    }
}
