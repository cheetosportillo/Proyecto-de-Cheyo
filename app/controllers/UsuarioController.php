<?php
require_once __DIR__ . '/../models/Usuario.php';

/** Solo la dueña puede entrar aquí. */
class UsuarioController extends Controller
{
    public function index(): void
    {
        $this->requireRol(['duena']);
        $this->render('usuarios/index', ['titulo' => 'Usuarios', 'usuarios' => Usuario::listar()]);
    }

    public function guardar(): void
    {
        $this->requireRol(['duena'], true);
        $this->verificarCsrf();
        $d = $this->entrada();

        $id      = (int)($d['id_usuario'] ?? 0);
        $nombre  = trim($d['nombre'] ?? '');
        $usuario = trim($d['usuario'] ?? '');
        $pass    = (string)($d['password'] ?? '');
        $rol     = $d['rol'] ?? '';

        if ($nombre === '' || $usuario === '' || !in_array($rol, ['duena', 'cajero'], true))
            $this->json(['ok' => false, 'msg' => 'Completa todos los campos obligatorios.']);
        if (!preg_match('/^[A-Za-z0-9._-]{3,30}$/', $usuario))
            $this->json(['ok' => false, 'msg' => 'El usuario debe tener 3-30 caracteres (letras, números, . _ -).']);
        if ($id === 0 && strlen($pass) < 6)
            $this->json(['ok' => false, 'msg' => 'La contraseña debe tener al menos 6 caracteres.']);
        if ($id > 0 && $pass !== '' && strlen($pass) < 6)
            $this->json(['ok' => false, 'msg' => 'La contraseña debe tener al menos 6 caracteres.']);
        if (Usuario::existe($usuario, $id))
            $this->json(['ok' => false, 'msg' => 'Ese nombre de usuario ya existe.']);
        if ($id === $this->usuario()['id'] && $rol !== 'duena')
            $this->json(['ok' => false, 'msg' => 'No puedes quitarte tu propio rol de dueña.']);

        $datos = ['nombre' => $nombre, 'usuario' => $usuario, 'password' => $pass, 'rol' => $rol];
        $id > 0 ? Usuario::actualizar($id, $datos) : Usuario::crear($datos);
        $this->json(['ok' => true, 'msg' => 'Usuario guardado correctamente.']);
    }

    public function estado(): void
    {
        $this->requireRol(['duena'], true);
        $this->verificarCsrf();
        $d = $this->entrada();
        $id = (int)($d['id_usuario'] ?? 0);
        if ($id === $this->usuario()['id'])
            $this->json(['ok' => false, 'msg' => 'No puedes desactivar tu propia cuenta.']);
        Usuario::cambiarEstado($id, (int)($d['estado'] ?? 0) ? 1 : 0);
        $this->json(['ok' => true, 'msg' => 'Estado actualizado.']);
    }
}
