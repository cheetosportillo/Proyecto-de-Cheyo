<?php
require_once __DIR__ . '/../models/Usuario.php';

class AuthController extends Controller
{
    public function login(): void
    {
        if ($this->usuario()) $this->redirect('dashboard');
        $error = '';

        if ($_SERVER['REQUEST_METHOD'] === 'POST') {
            if (!hash_equals($_SESSION['csrf'] ?? '', $_POST['csrf'] ?? '')) {
                $error = 'Sesión expirada, intenta de nuevo.';
            } else {
                $u = Usuario::porUsuario(trim($_POST['usuario'] ?? ''));
                if ($u && $u['estado'] == 1 && password_verify($_POST['password'] ?? '', $u['password'])) {
                    session_regenerate_id(true);
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

    public function logout(): void
    {
        $_SESSION = [];
        session_destroy();
        $this->redirect('auth', 'login');
    }
}
