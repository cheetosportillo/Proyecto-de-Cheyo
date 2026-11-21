<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Iniciar sesión | <?= APP_NAME ?></title>
    <link href="<?= asset('vendor/bootstrap/css/bootstrap.min.css') ?>" rel="stylesheet">
    <link href="<?= asset('vendor/bootstrap-icons/font/bootstrap-icons.min.css') ?>" rel="stylesheet">
    <link href="<?= asset('css/estilos.css') ?>" rel="stylesheet">
</head>
<body class="login-bg">
<div class="container min-vh-100 d-flex align-items-center justify-content-center">
    <div class="card login-card shadow-lg border-0">
        <div class="card-body p-4 p-md-5">
            <div class="text-center mb-4">
                <i class="bi bi-gem display-4 text-gold"></i>
                <h3 class="mt-2 mb-0"><?= APP_NAME ?></h3>
                <small class="text-muted">Sistema de ventas e inventario</small>
            </div>
            <?php if ($error): ?><div class="alert alert-danger py-2"><?= e($error) ?></div><?php endif; ?>
            <form method="post" action="<?= e(url('auth', 'login')) ?>" autocomplete="off">
                <input type="hidden" name="csrf" value="<?= e(Controller::csrf()) ?>">
                <div class="mb-3">
                    <label class="form-label">Usuario</label>
                    <div class="input-group"><span class="input-group-text"><i class="bi bi-person"></i></span>
                        <input type="text" name="usuario" class="form-control" required autofocus></div>
                </div>
                <div class="mb-4">
                    <label class="form-label">Contraseña</label>
                    <div class="input-group"><span class="input-group-text"><i class="bi bi-lock"></i></span>
                        <input type="password" name="password" class="form-control" required></div>
                </div>
                <button class="btn btn-gold w-100 py-2" type="submit">Ingresar</button>
            </form>
        </div>
    </div>
</div>
</body>
</html>
