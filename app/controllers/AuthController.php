<?php
require_once __DIR__ . '/../models/Usuario.php';
require_once __DIR__ . '/../models/Bitacora.php';
require_once __DIR__ . '/../models/Intentos.php';
require_once __DIR__ . '/../models/Config.php';
require_once __DIR__ . '/../core/Recuperacion.php';
require_once __DIR__ . '/../core/Mailer.php';

class AuthController extends Controller
{
    /** Recuperación: 5 fallos por cuenta cada 15 min bloquean ese método durante el resto de la ventana. */
    private const MAX_FALLOS = 5;
    private const VENTANA    = 900;
    /** Correos de recuperación: 3 solicitudes por cuenta cada 15 min y 20 por hora en total (protege la cuota SMTP). */
    private const MAX_SOLICITUDES        = 3;
    private const MAX_CORREOS_POR_HORA   = 20;

    public function login(): void
    {
        if ($this->usuario()) {
            $this->redirect(...(!empty($_SESSION['usuario']['debe_cambiar']) ? ['auth', 'cambiar'] : $this->destinoInicial()));
        }
        $error = '';

        if ($_SERVER['REQUEST_METHOD'] === 'POST') {
            if (!self::csrfValido((string)($_POST['csrf'] ?? ''))) {   // N11: token vacío = inválido
                $error = 'Sesión expirada, intenta de nuevo.';
            } else {
                $u = Usuario::porUsuario(trim($_POST['usuario'] ?? ''));
                if ($u && $u['estado'] == 1 && password_verify($_POST['password'] ?? '', $u['password'])) {
                    session_regenerate_id(true);
                    $_SESSION['_actividad'] = time();   // ERR-13: inicio del conteo de inactividad
                    $_SESSION['usuario'] = [
                        'id' => (int)$u['id_usuario'], 'nombre' => $u['nombre'],
                        'usuario' => $u['usuario'], 'rol' => $u['rol'],
                        'permisos' => Permisos::delRol((string)$u['rol']),
                        'debe_cambiar' => (int)$u['debe_cambiar_password'] === 1,
                    ];
                    if ($_SESSION['usuario']['debe_cambiar']) $this->redirect('auth', 'cambiar');   // clave temporal: cambiarla primero
                    $this->redirect(...$this->destinoInicial());
                }
                usleep(500000); // frena ataques de fuerza bruta
                $error = 'Usuario o contraseña incorrectos.';
            }
        }
        $this->render('auth/login', ['error' => $error], false);
    }

    /** N11: el cierre de sesión exige POST + token CSRF (un GET externo ya no puede cerrarla). */
    public function logout(): void
    {
        if ($_SERVER['REQUEST_METHOD'] === 'POST' && self::csrfValido((string)($_POST['csrf'] ?? ''))) {
            self::cerrarSesion();
            $this->redirect('auth', 'login');
        }
        $this->usuario() ? $this->redirect('dashboard') : $this->redirect('auth', 'login');
    }

    /** Primera pantalla a la que el usuario tiene permiso (si la Dueña le quitó ventas, no lo manda a un 403). */
    private function destinoInicial(): array
    {
        foreach ([['dashboard.ver', 'dashboard'], ['ventas.cobrar', 'venta'], ['caja.operar', 'caja'], ['inventario.consultar', 'producto'], ['kardex.ver', 'kardex'], ['compras.gestionar', 'compra']] as [$perm, $ctrl]) {
            if (Permisos::tiene($perm)) return [$ctrl, 'index'];
        }
        return ['dashboard', 'index'];
    }

    /* ===================================================================== cambio de contraseña */

    /** Pantalla de cambio: obligatoria si debe_cambiar_password = 1, voluntaria si no. */
    public function cambiar(): void
    {
        $this->requireLogin(false, true);
        $this->render('auth/cambiar', [
            'obligatorio' => !empty($_SESSION['usuario']['debe_cambiar']),
            'nombre'      => (string)$_SESSION['usuario']['nombre'],
            'esDuena'     => ($_SESSION['usuario']['rol'] ?? '') === 'duena',
            'inicio'      => url(...$this->destinoInicial()),
        ], false);
    }

    public function cambiarPassword(): void
    {
        $this->requireLogin(true, true);
        $this->verificarCsrf();
        $d = $this->entrada();
        $actual = (string)($d['actual'] ?? '');
        $nueva  = (string)($d['password'] ?? '');
        $conf   = (string)($d['password2'] ?? '');
        $id     = (int)$this->usuario()['id'];

        $clave = 'cambio:' . $id;
        if (Intentos::excedido($clave, self::MAX_FALLOS, self::VENTANA)) {
            $this->json(['ok' => false, 'msg' => 'Demasiados intentos. Espera unos minutos e inténtalo de nuevo.'], 429);
        }
        $fila = Usuario::porId($id);
        if (!$fila || !password_verify($actual, (string)$fila['password'])) {
            usleep(500000);
            Intentos::sumar($clave, self::VENTANA);
            $this->json(['ok' => false, 'msg' => 'La contraseña actual no es correcta.']);
        }
        if (($m = Usuario::validarPassword($nueva)) !== null) $this->json(['ok' => false, 'msg' => $m]);
        if (!hash_equals($nueva, $conf)) $this->json(['ok' => false, 'msg' => 'Las contraseñas nuevas no coinciden.']);
        if (hash_equals($actual, $nueva)) $this->json(['ok' => false, 'msg' => 'La contraseña nueva debe ser distinta de la actual.']);

        Database::transaccion(function () use ($id, $nueva) {
            Usuario::establecerPassword($id, $nueva, false);
            Bitacora::registrar($id, 'PASSWORD_CAMBIADA', 'El usuario cambió su propia contraseña.');
        });
        Intentos::limpiar($clave);
        session_regenerate_id(true);
        $_SESSION['usuario']['debe_cambiar'] = false;
        $this->json(['ok' => true, 'msg' => 'Contraseña actualizada.', 'ir' => url(...$this->destinoInicial())]);
    }

    /**
     * Dueña: genera una llave maestra nueva (ONIX-XXXX-XXXX-XXXX), invalida la anterior y la muestra UNA sola vez.
     * Exige la contraseña actual (con límite de intentos) y queda en bitácora.
     */
    public function regenerarLlave(): void
    {
        $this->requireLogin(true);   // quien tiene una clave temporal pendiente debe cambiarla primero
        $this->verificarCsrf();
        $id = (int)$this->usuario()['id'];
        $actual = (string)($this->entrada()['actual'] ?? '');
        $fila = Usuario::porId($id);
        if (!$fila || $fila['rol'] !== 'duena') $this->json(['ok' => false, 'msg' => 'Solo la Dueña tiene llave maestra.'], 403);

        $clave = 'cambio:' . $id;   // mismo contador que «cambiar contraseña»: ambos adivinan la contraseña actual
        if (Intentos::excedido($clave, self::MAX_FALLOS, self::VENTANA)) {
            $this->json(['ok' => false, 'msg' => 'Demasiados intentos. Espera unos minutos e inténtalo de nuevo.'], 429);
        }
        if (!password_verify($actual, (string)$fila['password'])) {
            usleep(500000);
            Intentos::sumar($clave, self::VENTANA);
            $this->json(['ok' => false, 'msg' => 'La contraseña no es correcta.']);
        }
        [$llave, $hash] = Recuperacion::generar();
        $tenia = !empty($fila['codigo_recuperacion_hash']);
        Database::transaccion(function () use ($id, $hash, $tenia) {
            Database::get()->prepare('UPDATE usuarios SET codigo_recuperacion_hash = ? WHERE id_usuario = ?')->execute([$hash, $id]);
            Bitacora::registrar($id, 'LLAVE_REGENERADA', $tenia ? 'La Dueña regeneró su llave maestra; la anterior quedó invalidada.' : 'La Dueña generó su llave maestra.');
        });
        Intentos::limpiar($clave);
        $this->json(['ok' => true, 'msg' => 'Llave maestra regenerada. La anterior ya no sirve.', 'llave' => $llave, 'usuario' => $fila['usuario']]);
    }

    /* ===================================================================== sesión (aviso de expiración) */

    /**
     * Cuánto falta para que venza la sesión por inactividad. NO la renueva (no pasa por requireLogin), así el
     * navegador puede preguntar sin alargar la sesión. Si ya venció responde activa=false (200, sin redirigir).
     */
    public function estadoSesion(): void
    {
        $u = $this->usuario();
        if ($u === null) $this->json(['ok' => true, 'activa' => false]);
        $resta = SESION_INACTIVIDAD - (time() - (int)($_SESSION['_actividad'] ?? time()));
        // csrf/usuario (Bloque 2): el modo offline renueva su token y comprueba que sincroniza con el mismo usuario. GET same-origin, no-store.
        $this->json(['ok' => true, 'activa' => true, 'restante' => max(0, $resta), 'total' => SESION_INACTIVIDAD,
                     'csrf' => self::csrf(), 'usuario' => (int)$u['id']]);
    }

    /** «Seguir trabajando»: renueva la sesión sin recargar. Vencida => ok=false (no 401, para no sacar al usuario de la pantalla). */
    public function renovar(): void
    {
        $this->verificarCsrf();
        if ($this->usuario() === null) $this->json(['ok' => false, 'expirada' => true, 'msg' => 'La sesión ya venció.']);
        $this->requireLogin(true, true);   // revalida contra la BD y reinicia el conteo de inactividad
        $this->json(['ok' => true, 'restante' => SESION_INACTIVIDAD]);
    }

    /* ===================================================================== recuperación híbrida */

    /** «¿Olvidaste tu contraseña?»: método 1 (correo, con internet) y método 2 (llave maestra, sin internet). */
    public function recuperar(): void
    {
        if ($this->usuario()) $this->redirect('dashboard');
        $this->render('auth/recuperar', ['correoListo' => Mailer::listo(), 'minutos' => Usuario::MINUTOS_CODIGO], false);
    }

    /**
     * Método 1, paso 1: envía un código de 8 caracteres (15 min) al correo registrado.
     * La respuesta es SIEMPRE la misma exista o no la cuenta (no revela qué usuarios tienen correo).
     */
    public function recuperarCorreo(): void
    {
        $this->verificarCsrf();
        $usuario = trim((string)($this->entrada()['usuario'] ?? ''));
        if ($usuario === '') $this->json(['ok' => false, 'msg' => 'Escribe tu usuario.']);
        if (!Mailer::listo()) {
            $this->json(['ok' => false, 'msg' => 'El envío de correos todavía no está configurado en este sistema. '
                . 'Si eres la Dueña, usa la llave maestra; si eres empleado/a, pide a la Dueña que restablezca tu contraseña desde Usuarios.']);
        }

        $u = Usuario::porUsuario($usuario);
        $valido = $u && (int)$u['estado'] === 1 && !empty($u['email']);
        $clave = $valido ? 'correo:' . $u['usuario'] : 'correo:anon';
        if (Intentos::excedido($clave, self::MAX_SOLICITUDES, self::VENTANA)) {
            $this->json(['ok' => false, 'msg' => 'Ya se solicitaron varios códigos. Espera unos minutos antes de pedir otro.'], 429);
        }
        Intentos::sumar($clave, self::VENTANA);

        if ($valido) {
            if (Intentos::excedido('correo:global', self::MAX_CORREOS_POR_HORA, 3600)) {
                $this->json(['ok' => false, 'msg' => 'Se alcanzó el límite de correos por hora. Inténtalo más tarde o usa otro método.'], 429);
            }
            Intentos::sumar('correo:global', 3600);
            [$codigo, $hash] = Recuperacion::generarCodigoCorreo();
            Usuario::guardarTokenCorreo((int)$u['id_usuario'], $hash);
            $negocio = (string)Config::obtener('negocio_nombre', APP_NAME);
            try {
                Mailer::enviar((string)$u['email'], 'Código para restablecer tu contraseña · ' . $negocio,
                    "Hola " . $u['nombre'] . ",\n\nTu código para restablecer la contraseña de " . $negocio . " es:\n\n    " . $codigo
                    . "\n\nVence en " . Usuario::MINUTOS_CODIGO . " minutos y solo sirve una vez.\n"
                    . "Si no lo pediste tú, ignora este mensaje: tu contraseña no cambia.\n");
            } catch (Throwable $e) {
                error_log('[recuperacion] No se pudo enviar el correo: ' . $e->getMessage());   // la persona recibe la respuesta genérica
            }
        } else {
            usleep(random_int(300, 700) * 1000);
        }
        $this->json(['ok' => true, 'msg' => 'Si la cuenta existe y tiene un correo registrado, enviamos un código de 8 caracteres '
            . '(válido ' . Usuario::MINUTOS_CODIGO . ' minutos). Revisa también la carpeta de spam.']);
    }

    /** Método 1, paso 2: código recibido + contraseña nueva. */
    public function restablecerCorreo(): void
    {
        $this->verificarCsrf();
        $d = $this->entrada();
        $usuario = trim((string)($d['usuario'] ?? ''));
        $codigo  = (string)($d['codigo'] ?? '');
        $nueva   = (string)($d['password'] ?? '');
        $this->validarNuevaPassword($nueva, (string)($d['password2'] ?? ''));

        $u = Usuario::porUsuario($usuario);
        $activo = $u && (int)$u['estado'] === 1;
        $clave = $activo ? 'codigo:' . $u['usuario'] : 'codigo:anon';
        if (Intentos::excedido($clave, self::MAX_FALLOS, self::VENTANA)) {
            $this->json(['ok' => false, 'msg' => 'Demasiados intentos. Espera unos minutos o pide un código nuevo más tarde.'], 429);
        }
        $hash = $activo ? Usuario::tokenCorreo((int)$u['id_usuario']) : null;   // solo si no venció
        if (!$activo || !Recuperacion::verificarCodigoCorreo($codigo, $hash)) {
            usleep(500000);
            Intentos::sumar($clave, self::VENTANA);
            if ($activo && Intentos::excedido($clave, self::MAX_FALLOS, self::VENTANA)) {
                Usuario::invalidarTokenCorreo((int)$u['id_usuario']);   // 5 fallos: el código queda inservible
            }
            $this->json(['ok' => false, 'msg' => 'Usuario o código incorrecto, o el código ya venció.']);
        }

        $id = (int)$u['id_usuario'];
        Database::transaccion(function () use ($id, $nueva, $u) {
            Usuario::establecerPassword($id, $nueva, false);   // la eligió ella/él mismo: no hace falta forzar otro cambio
            Bitacora::registrar($id, 'RECUPERACION_CORREO', 'Contraseña restablecida con el código enviado al correo de ' . $u['usuario'] . '.');
        });
        Intentos::limpiar($clave);
        $this->json(['ok' => true, 'msg' => 'Contraseña actualizada. Ya puedes iniciar sesión.', 'login' => url('auth', 'login')]);
    }

    /**
     * Método 2 (sin internet, solo Dueña): usuario + llave maestra + contraseña nueva.
     * Al usarla se rota la llave (la nueva se muestra UNA vez) y queda registrado en bitácora.
     */
    public function restablecerLlave(): void
    {
        $this->verificarCsrf();
        $d = $this->entrada();
        $usuario = trim((string)($d['usuario'] ?? ''));
        $llave   = (string)($d['llave'] ?? '');
        $nueva   = (string)($d['password'] ?? '');
        $this->validarNuevaPassword($nueva, (string)($d['password2'] ?? ''));

        $u = Usuario::porUsuario($usuario);
        $valido = $u && (int)$u['estado'] === 1 && $u['rol'] === 'duena' && !empty($u['codigo_recuperacion_hash']);
        $clave = $valido ? 'llave:' . $u['usuario'] : 'llave:anon';
        if (Intentos::excedido($clave, self::MAX_FALLOS, self::VENTANA)) {
            $this->json(['ok' => false, 'msg' => 'Demasiados intentos con la llave maestra. Espera ' . intdiv(self::VENTANA, 60) . ' minutos e inténtalo de nuevo.'], 429);
        }
        if (!$valido || !Recuperacion::verificar($llave, (string)$u['codigo_recuperacion_hash'])) {
            usleep(500000);
            Intentos::sumar($clave, self::VENTANA);
            if ($valido && Intentos::excedido($clave, self::MAX_FALLOS, self::VENTANA)) {
                Bitacora::registrar((int)$u['id_usuario'], 'RECUPERACION_BLOQUEADA', 'Llave maestra bloqueada ' . intdiv(self::VENTANA, 60) . ' min por ' . self::MAX_FALLOS . ' intentos fallidos.');
            }
            $this->json(['ok' => false, 'msg' => 'Usuario o llave maestra incorrectos. Este método es solo para la Dueña; '
                . 'si eres empleado/a, pide a la Dueña que restablezca tu contraseña desde Usuarios.']);
        }

        $id = (int)$u['id_usuario'];
        [$nuevaLlave, $hashLlave] = Recuperacion::generar();
        Database::transaccion(function () use ($id, $nueva, $hashLlave, $u) {
            Usuario::establecerPasswordYLlave($id, $nueva, $hashLlave);
            Bitacora::registrar($id, 'RECUPERACION_LLAVE', 'Contraseña de ' . $u['usuario'] . ' restablecida con la llave maestra. La llave fue rotada.');
        });
        Intentos::limpiar($clave);
        $this->json(['ok' => true, 'msg' => 'Contraseña actualizada.', 'llave' => $nuevaLlave, 'login' => url('auth', 'login')]);
    }

    private function validarNuevaPassword(string $nueva, string $conf): void
    {
        if (($m = Usuario::validarPassword($nueva)) !== null) $this->json(['ok' => false, 'msg' => $m]);
        if (!hash_equals($nueva, $conf)) $this->json(['ok' => false, 'msg' => 'Las contraseñas no coinciden.']);
    }
}
