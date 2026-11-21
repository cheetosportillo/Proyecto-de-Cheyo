<?php
require_once __DIR__ . '/../models/Usuario.php';

/** Controlador base: sesión, permisos, CSRF, render y JSON. */
abstract class Controller
{
    protected function render(string $vista, array $datos = [], bool $layout = true): void
    {
        extract($datos);
        $archivo = __DIR__ . '/../views/' . $vista . '.php';
        if ($layout) require __DIR__ . '/../views/layout/header.php';
        require $archivo;
        if ($layout) require __DIR__ . '/../views/layout/footer.php';
    }

    protected function json(array $data, int $code = 200): void
    {
        http_response_code($code);
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode($data, JSON_UNESCAPED_UNICODE);
        exit;
    }

    protected function redirect(string $c, string $a = 'index'): void
    {
        header('Location: ' . url($c, $a));
        exit;
    }

    /** ¿La petición espera JSON? (los fetch de app.js envían Accept: application/json). */
    protected function esAjax(bool $forzar = false): bool
    {
        return $forzar || str_contains($_SERVER['HTTP_ACCEPT'] ?? '', 'application/json');
    }

    /* ---------- Sesión ---------- */

    /** Vacía la sesión y rota su id (la sesión sigue activa para poder mostrar el login). */
    protected static function cerrarSesion(): void
    {
        $_SESSION = [];
        if (session_status() === PHP_SESSION_ACTIVE) session_regenerate_id(true);
    }

    /** Usuario en sesión, o null si no hay o se superó la inactividad (ERR-13: 30 min). */
    protected function usuario(): ?array
    {
        $u = $_SESSION['usuario'] ?? null;
        if ($u !== null) {
            $ultima = (int)($_SESSION['_actividad'] ?? 0);
            if ($ultima > 0 && time() - $ultima > SESION_INACTIVIDAD) {
                self::cerrarSesion();
                return null;
            }
        }
        return $u;
    }

    /**
     * Exige sesión válida. En cada petición revalida contra la BD que el usuario siga activo y
     * refresca sus datos (ERR-06/13). Sin sesión: JSON 401 si es AJAX; redirección al login si es página.
     */
    protected function requireLogin(bool $ajax = false): void
    {
        $u = $this->usuario();
        if ($u !== null) {
            $fila = Usuario::porId((int)$u['id']);
            if (!$fila || (int)$fila['estado'] !== 1) {
                self::cerrarSesion();   // desactivado o eliminado: se le expulsa en su siguiente clic
                $u = null;
            } else {
                $_SESSION['usuario']['nombre']  = $fila['nombre'];
                $_SESSION['usuario']['usuario'] = $fila['usuario'];
                $_SESSION['usuario']['rol']     = $fila['rol'];   // [Bloque 1.3] aquí también se recargarán los permisos
                $_SESSION['_actividad'] = time();
            }
        }
        if ($u === null) {
            if ($this->esAjax($ajax)) {
                $this->json(['ok' => false, 'msg' => 'Tu sesión expiró. Inicia sesión de nuevo.', 'login' => url('auth', 'login')], 401);
            }
            $this->redirect('auth', 'login');
        }
    }

    /** Roles permitidos: 'duena' y/o 'cajero'. */
    protected function requireRol(array $roles, bool $ajax = false): void
    {
        $this->requireLogin($ajax);
        if (!in_array($_SESSION['usuario']['rol'], $roles, true)) {
            $this->denegar($ajax);
        }
    }

    /** ¿El usuario en sesión tiene este permiso? (inventario.gestionar, caja.operar, etc.) */
    protected function puede(string $permiso): bool { return Permisos::tiene($permiso); }

    /** Exige un permiso. En peticiones AJAX responde JSON 403; en páginas, la vista 403. */
    protected function requirePermiso(string $permiso, bool $ajax = false): void
    {
        $this->requireLogin($ajax);
        if (!Permisos::tiene($permiso)) {
            $this->denegar($ajax);
        }
    }

    private function denegar(bool $ajax): void
    {
        if ($this->esAjax($ajax)) $this->json(['ok' => false, 'msg' => 'No tienes permiso para esta acción.'], 403);
        http_response_code(403);
        $this->render('auth/403');
        exit;
    }

    /* ---------- CSRF ---------- */
    public static function csrf(): string
    {
        if (empty($_SESSION['csrf'])) $_SESSION['csrf'] = bin2hex(random_bytes(32));
        return $_SESSION['csrf'];
    }

    /** N11: un token vacío NUNCA es válido (hash_equals('', '') devolvía true). */
    protected static function csrfValido(string $token): bool
    {
        $esperado = (string)($_SESSION['csrf'] ?? '');
        return $esperado !== '' && $token !== '' && hash_equals($esperado, $token);
    }

    protected function verificarCsrf(): void
    {
        $t = (string)($_SERVER['HTTP_X_CSRF_TOKEN'] ?? ($_POST['csrf'] ?? ''));
        if ($_SERVER['REQUEST_METHOD'] !== 'POST' || !self::csrfValido($t)) {
            $this->json(['ok' => false, 'msg' => 'Solicitud no válida. Recarga la página.'], 419);
        }
    }

    /** Lee el cuerpo JSON (o $_POST si no es JSON). */
    protected function entrada(): array
    {
        $raw = file_get_contents('php://input');
        $j = json_decode($raw, true);
        return is_array($j) ? $j : $_POST;
    }
}
