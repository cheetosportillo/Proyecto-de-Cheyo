<?php
require_once __DIR__ . '/../models/Usuario.php';
require_once __DIR__ . '/../models/Bitacora.php';
require_once __DIR__ . '/../core/Recuperacion.php';

/**
 * Usuarios y roles (permiso usuarios.gestionar, reservado a la Dueña).
 * Todas las acciones de escritura responden JSON limpio (api()) y exigen CSRF.
 * Las claves temporales y las llaves maestras viajan en la respuesta UNA sola vez y nunca se guardan en claro.
 */
class UsuarioController extends Controller
{
    public function index(): void
    {
        $this->requirePermiso('usuarios.gestionar');
        $this->render('usuarios/index', [
            'titulo'   => 'Usuarios',
            'usuarios' => Usuario::listar(),
            'catalogo' => Permisos::catalogo(),
            'cajero'   => Permisos::delRol('cajero'),
            'scripts'  => ['usuarios.js'],
        ]);
    }

    /** Crea (id vacío) o edita. Al crear se genera una clave temporal; al hacer Dueña a alguien se genera su llave maestra. */
    public function guardar(): void
    {
        $this->requirePermiso('usuarios.gestionar', true);
        $this->verificarCsrf();
        $d = $this->entrada();

        $id      = (int)($d['id_usuario'] ?? 0);
        $nombre  = trim((string)($d['nombre'] ?? ''));
        $usuario = trim((string)($d['usuario'] ?? ''));
        $rol     = (string)($d['rol'] ?? '');
        $yo      = (int)$this->usuario()['id'];

        if ($nombre === '' || $usuario === '' || !in_array($rol, ['duena', 'cajero'], true))
            $this->json(['ok' => false, 'msg' => 'Completa nombre, usuario y rol.']);
        if (largo_texto($nombre) > 100)
            $this->json(['ok' => false, 'msg' => 'El nombre admite hasta 100 caracteres.']);
        if (!preg_match('/^[A-Za-z0-9._-]{3,30}$/', $usuario))
            $this->json(['ok' => false, 'msg' => 'El usuario debe tener 3-30 caracteres (letras, números, . _ -).']);
        try {
            $email = Usuario::normalizarEmail((string)($d['email'] ?? ''));
        } catch (InvalidArgumentException $e) {
            $this->json(['ok' => false, 'msg' => $e->getMessage()]);
        }
        if (Usuario::existe($usuario, $id))
            $this->json(['ok' => false, 'msg' => 'Ese nombre de usuario ya existe.']);

        $actual = null;
        if ($id > 0) {
            $actual = Usuario::porId($id);
            if (!$actual) $this->json(['ok' => false, 'msg' => 'El usuario no existe.']);
            if ($id === $yo && $rol !== 'duena')
                $this->json(['ok' => false, 'msg' => 'No puedes quitarte tu propio rol de dueña.']);
        }

        $clave = null;   // clave temporal (solo al crear)
        $llave = null;   // llave maestra nueva (solo si la cuenta pasa a ser Dueña y aún no tiene)
        $hashLlave = null;
        if ($rol === 'duena' && ($actual === null || empty($actual['codigo_recuperacion_hash']))) {
            [$llave, $hashLlave] = Recuperacion::generar();
        }

        try {
            Database::transaccion(function () use ($id, $nombre, $usuario, $email, $rol, $yo, $hashLlave, &$clave) {
                if ($id === 0) {
                    $clave = Usuario::claveTemporal();
                    Usuario::crear(['nombre' => $nombre, 'usuario' => $usuario, 'email' => $email, 'rol' => $rol,
                                    'password' => $clave, 'llave_hash' => $hashLlave]);
                    Bitacora::registrar($yo, 'USUARIO_CREADO', 'Usuario ' . $usuario . ' (' . $rol . ') con clave temporal.');
                } else {
                    Usuario::actualizar($id, ['nombre' => $nombre, 'usuario' => $usuario, 'email' => $email, 'rol' => $rol, 'llave_hash' => $hashLlave]);
                    Bitacora::registrar($yo, 'USUARIO_EDITADO', 'Usuario ' . $usuario . ' (' . $rol . ').');
                }
            });
        } catch (PDOException $e) {
            if ((string)$e->getCode() === '23000') $this->json(['ok' => false, 'msg' => 'Ese nombre de usuario ya existe.']);
            throw $e;
        }

        $this->json(['ok' => true, 'msg' => 'Usuario guardado correctamente.', 'usuario' => $usuario,
                     'clave_temporal' => $clave, 'llave' => $llave]);
    }

    public function estado(): void
    {
        $this->requirePermiso('usuarios.gestionar', true);
        $this->verificarCsrf();
        $d = $this->entrada();
        $id = (int)($d['id_usuario'] ?? 0);
        $yo = (int)$this->usuario()['id'];
        if ($id === $yo)
            $this->json(['ok' => false, 'msg' => 'No puedes desactivar tu propia cuenta.']);
        $u = Usuario::porId($id);
        if (!$u) $this->json(['ok' => false, 'msg' => 'El usuario no existe.']);
        $nuevo = (int)($d['estado'] ?? 0) ? 1 : 0;
        Database::transaccion(function () use ($id, $nuevo, $yo, $u) {
            Usuario::cambiarEstado($id, $nuevo);
            Bitacora::registrar($yo, $nuevo ? 'USUARIO_ACTIVADO' : 'USUARIO_DESACTIVADO', 'Usuario ' . $u['usuario'] . '.');
        });
        $this->json(['ok' => true, 'msg' => 'Estado actualizado.']);
    }

    /** Restablece la contraseña de OTRO usuario: genera una clave temporal y lo obliga a cambiarla al entrar. */
    public function reiniciarPassword(): void
    {
        $this->requirePermiso('usuarios.gestionar', true);
        $this->verificarCsrf();
        $id = (int)($this->entrada()['id_usuario'] ?? 0);
        $yo = (int)$this->usuario()['id'];
        if ($id === $yo)
            $this->json(['ok' => false, 'msg' => 'Para cambiar tu propia contraseña usa «Cambiar contraseña» en el menú.']);
        $u = Usuario::porId($id);
        if (!$u) $this->json(['ok' => false, 'msg' => 'El usuario no existe.']);

        $clave = Usuario::claveTemporal();
        Database::transaccion(function () use ($id, $clave, $yo, $u) {
            Usuario::establecerPassword($id, $clave, true);   // debe_cambiar_password = 1 y anula códigos pendientes
            Bitacora::registrar($yo, 'PASSWORD_RESTABLECIDA', 'Clave temporal generada para ' . $u['usuario'] . '.');
        });
        $this->json(['ok' => true, 'msg' => 'Clave temporal generada.', 'usuario' => $u['usuario'], 'clave_temporal' => $clave]);
    }

    /** Permisos del rol «cajero» (la Dueña siempre tiene todo; los reservados no se pueden otorgar). */
    public function permisos(): void
    {
        $this->requirePermiso('usuarios.gestionar', true);
        $this->verificarCsrf();
        $d = $this->entrada();
        $rol = (string)($d['rol'] ?? '');
        $lista = $d['permisos'] ?? [];
        if (!is_array($lista)) $this->json(['ok' => false, 'msg' => 'Solicitud no válida.']);
        try {
            $otorgados = Permisos::asignar($rol, array_values($lista));
        } catch (InvalidArgumentException $e) {
            $this->json(['ok' => false, 'msg' => $e->getMessage()]);
        }
        Bitacora::registrar((int)$this->usuario()['id'], 'PERMISOS_ROL', 'Rol ' . $rol . ': ' . ($otorgados ? implode(', ', $otorgados) : '(ninguno)'));
        $this->json(['ok' => true, 'msg' => 'Permisos del rol actualizados. Se aplican en el siguiente clic de cada empleado.']);
    }
}
