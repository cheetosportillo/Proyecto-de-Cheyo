<?php
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

    /* ---------- Sesión y permisos ---------- */
    protected function usuario(): ?array { return $_SESSION['usuario'] ?? null; }

    protected function requireLogin(): void
    {
        if (!$this->usuario()) $this->redirect('auth', 'login');
    }

    /** Roles permitidos: 'duena' y/o 'cajero'. */
    protected function requireRol(array $roles, bool $ajax = false): void
    {
        $this->requireLogin();
        if (!in_array($_SESSION['usuario']['rol'], $roles, true)) {
            if ($ajax) $this->json(['ok' => false, 'msg' => 'No tienes permiso para esta acción.'], 403);
            http_response_code(403);
            $this->render('auth/403');
            exit;
        }
    }

    /* ---------- CSRF ---------- */
    public static function csrf(): string
    {
        if (empty($_SESSION['csrf'])) $_SESSION['csrf'] = bin2hex(random_bytes(32));
        return $_SESSION['csrf'];
    }

    protected function verificarCsrf(): void
    {
        $t = $_SERVER['HTTP_X_CSRF_TOKEN'] ?? ($_POST['csrf'] ?? '');
        if ($_SERVER['REQUEST_METHOD'] !== 'POST' || !hash_equals($_SESSION['csrf'] ?? '', $t)) {
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
