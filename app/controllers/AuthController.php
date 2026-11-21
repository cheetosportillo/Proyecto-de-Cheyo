<?php
require_once __DIR__ . '/../models/Usuario.php';

class AuthController extends Controller
{
    public function login(): void
    {
        if ($this->usuario()) $this->redirect('dashboard');
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
                    ];
                    $this->redirect($u['rol'] === 'duena' ? 'dashboard' : 'venta');
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
}
