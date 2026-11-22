<?php
require_once __DIR__ . '/../models/Usuario.php';
require_once __DIR__ . '/../models/Bitacora.php';
require_once __DIR__ . '/../models/Config.php';
require_once __DIR__ . '/../models/Intentos.php';
require_once __DIR__ . '/../core/Mailer.php';

/** Datos de la joyería (ticket) y parámetros SMTP. Permiso configuracion.gestionar (solo Dueña). */
class ConfiguracionController extends Controller
{
    public function index(): void
    {
        $this->requirePermiso('configuracion.gestionar');
        $yo = Usuario::porId((int)$this->usuario()['id']);
        $this->render('configuracion/index', [
            'titulo'        => 'Configuración',
            'c'             => Config::todas(true),
            'tienePassword' => Config::tieneSecreto('smtp_password'),
            'correoPrueba'  => (string)($yo['email'] ?? ''),
            'scripts'       => ['configuracion.js'],
        ]);
    }

    /** Nombre, teléfono, dirección y mensaje del ticket. */
    public function guardarNegocio(): void
    {
        $this->requirePermiso('configuracion.gestionar', true);
        $this->verificarCsrf();
        $d = $this->entrada();

        $nombre    = $this->texto($d['negocio_nombre'] ?? '');
        $telefono  = $this->texto($d['negocio_telefono'] ?? '');
        $direccion = $this->texto($d['negocio_direccion'] ?? '');
        $mensaje   = $this->texto($d['ticket_mensaje'] ?? '');

        if ($nombre === '' || largo_texto($nombre) > 100) $this->json(['ok' => false, 'msg' => 'Escribe el nombre de la joyería (hasta 100 caracteres).']);
        if (largo_texto($telefono) > 30)   $this->json(['ok' => false, 'msg' => 'El teléfono admite hasta 30 caracteres.']);
        if (largo_texto($direccion) > 200) $this->json(['ok' => false, 'msg' => 'La dirección admite hasta 200 caracteres.']);
        if (largo_texto($mensaje) > 120)   $this->json(['ok' => false, 'msg' => 'El mensaje del ticket admite hasta 120 caracteres.']);

        $this->guardarSeguro(['negocio_nombre' => $nombre, 'negocio_telefono' => $telefono,
                              'negocio_direccion' => $direccion, 'ticket_mensaje' => $mensaje]);
        Bitacora::registrar((int)$this->usuario()['id'], 'CONFIG_NEGOCIO', 'Datos de la joyería actualizados.');
        $this->json(['ok' => true, 'msg' => 'Datos de la joyería guardados. Se usan en los tickets nuevos.']);
    }

    /** Parámetros SMTP. La contraseña solo se cambia si se escribe una nueva (nunca se devuelve al navegador). */
    public function guardarSmtp(): void
    {
        $this->requirePermiso('configuracion.gestionar', true);
        $this->verificarCsrf();
        try {
            $cfg = $this->leerSmtp($this->entrada(), true);
        } catch (InvalidArgumentException $e) {
            $this->json(['ok' => false, 'msg' => $e->getMessage()]);
        }
        $pares = [
            'smtp_host' => $cfg['host'], 'smtp_puerto' => (string)$cfg['puerto'], 'smtp_cifrado' => $cfg['cifrado'],
            'smtp_usuario' => $cfg['usuario'], 'smtp_remitente' => $cfg['remitente'],
            'smtp_remitente_nombre' => $cfg['remitente_nombre'], 'smtp_verificar_ssl' => $cfg['verificar_ssl'] ? '1' : '0',
        ];
        if ($cfg['password_nueva'] !== '') $pares['smtp_password'] = $cfg['password_nueva'];
        $this->guardarSeguro($pares);
        Bitacora::registrar((int)$this->usuario()['id'], 'CONFIG_SMTP', 'Correo SMTP actualizado (' . $cfg['host'] . ':' . $cfg['puerto'] . ').'
            . ($cfg['password_nueva'] !== '' ? ' Contraseña cambiada.' : ''));
        $this->json(['ok' => true, 'msg' => 'Parámetros de correo guardados. Usa «Enviar prueba» para comprobarlos.']);
    }

    /** Envía un correo de prueba con lo que hay en el formulario (sin guardarlo). */
    public function probarSmtp(): void
    {
        $this->requirePermiso('configuracion.gestionar', true);
        $this->verificarCsrf();
        $d = $this->entrada();
        $id = (int)$this->usuario()['id'];
        if (Intentos::excedido('smtp_prueba:' . $id, 10, 900)) {
            $this->json(['ok' => false, 'msg' => 'Demasiadas pruebas seguidas. Espera unos minutos.'], 429);
        }
        Intentos::sumar('smtp_prueba:' . $id, 900);
        try {
            $cfg = $this->leerSmtp($d, false);
            $para = trim((string)($d['correo_prueba'] ?? ''));
            if (!filter_var($para, FILTER_VALIDATE_EMAIL)) throw new InvalidArgumentException('Escribe un correo válido para recibir la prueba.');
            if ($cfg['password_nueva'] !== '') {
                $cfg['password'] = $cfg['password_nueva'];
            } else {
                $cfg['password'] = (string)Config::obtener('smtp_password', '');   // la guardada
            }
            Mailer::enviar($para, 'Prueba de correo · ' . (string)Config::obtener('negocio_nombre', APP_NAME),
                "Este es un mensaje de prueba.\n\nSi lo estás leyendo, el correo (SMTP) del sistema funciona.\n", $cfg);
        } catch (InvalidArgumentException | RuntimeException $e) {
            $this->json(['ok' => false, 'msg' => $e->getMessage()]);
        }
        $this->json(['ok' => true, 'msg' => 'Correo de prueba enviado a ' . $para . '. Revisa también la carpeta de spam.']);
    }

    /* ------------------------------------------------------------ privados */

    /** Texto de una línea: sin saltos ni caracteres de control, recortado. */
    private function texto($v): string
    {
        return trim((string)preg_replace('/[\x00-\x1F\x7F]+/', ' ', (string)$v));
    }

    private function guardarSeguro(array $pares): void
    {
        try {
            Config::guardar($pares);
        } catch (RuntimeException $e) {   // p. ej. no se pudo crear config/app.key
            $this->json(['ok' => false, 'msg' => $e->getMessage()]);
        }
    }

    /**
     * Valida el bloque SMTP del formulario. Al guardar se permite dejar el servidor vacío (correo desactivado); al probar es obligatorio.
     * @return array{host:string,puerto:int,cifrado:string,usuario:string,password:string,password_nueva:string,remitente:string,remitente_nombre:string,verificar_ssl:bool}
     * @throws InvalidArgumentException
     */
    private function leerSmtp(array $d, bool $guardando): array
    {
        $host    = $this->texto($d['smtp_host'] ?? '');
        $puerto  = $this->texto($d['smtp_puerto'] ?? '');
        $cifrado = $this->texto($d['smtp_cifrado'] ?? 'tls');
        $usuario = $this->texto($d['smtp_usuario'] ?? '');
        $remit   = $this->texto($d['smtp_remitente'] ?? '');
        $nombre  = $this->texto($d['smtp_remitente_nombre'] ?? '');
        $pass    = (string)($d['smtp_password'] ?? '');
        if ($puerto === '') $puerto = '587';

        if ($host === '' && $guardando) {
            // Permitido: dejar el correo sin configurar (se desactiva el método 1 de recuperación).
        } elseif (!preg_match('/^[A-Za-z0-9._-]{1,255}$/', $host)) {
            throw new InvalidArgumentException('Servidor SMTP no válido (solo letras, números, punto y guion).');
        }
        if (!ctype_digit($puerto) || (int)$puerto < 1 || (int)$puerto > 65535) throw new InvalidArgumentException('Puerto SMTP no válido.');
        if (!in_array($cifrado, ['tls', 'ssl', 'none'], true)) throw new InvalidArgumentException('Elige un tipo de cifrado válido.');
        if (strlen($usuario) > 150) throw new InvalidArgumentException('El usuario SMTP admite hasta 150 caracteres.');
        if ($remit === '' && filter_var($usuario, FILTER_VALIDATE_EMAIL)) $remit = $usuario;
        if ($host !== '' && !filter_var($remit, FILTER_VALIDATE_EMAIL)) throw new InvalidArgumentException('Escribe un correo remitente válido (el que aparecerá como «De»).');
        if (largo_texto($nombre) > 100) throw new InvalidArgumentException('El nombre del remitente admite hasta 100 caracteres.');
        if (strlen($pass) > 200) throw new InvalidArgumentException('La contraseña SMTP es demasiado larga.');

        return [
            'host' => $host, 'puerto' => (int)$puerto, 'cifrado' => $cifrado, 'usuario' => $usuario,
            'password' => '', 'password_nueva' => $pass, 'remitente' => $remit,
            'remitente_nombre' => $nombre !== '' ? $nombre : (string)Config::obtener('negocio_nombre', APP_NAME),
            'verificar_ssl' => !empty($d['smtp_verificar_ssl']) && (string)$d['smtp_verificar_ssl'] !== '0',
        ];
    }
}
