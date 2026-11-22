<?php /* Variables: $obligatorio, $nombre, $inicio */ ?><!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="csrf-token" content="<?= e(Controller::csrf()) ?>">
    <title>Cambiar contraseña | <?= e(APP_NAME) ?></title>
    <link href="<?= e(asset('vendor/bootstrap/css/bootstrap.min.css')) ?>" rel="stylesheet">
    <link href="<?= e(asset('vendor/bootstrap-icons/font/bootstrap-icons.min.css')) ?>" rel="stylesheet">
    <link href="<?= e(asset('css/estilos.css')) ?>" rel="stylesheet">
</head>
<body class="login-bg">
<div class="container min-vh-100 d-flex align-items-center justify-content-center">
<div class="card login-card shadow-lg border-0"><div class="card-body p-4 p-md-5">
    <div class="text-center mb-3"><i class="bi bi-key display-5 text-gold"></i><h4 class="mt-2 mb-0">Cambiar contraseña</h4>
        <small class="text-muted"><?= e($nombre) ?></small></div>
    <?php if ($obligatorio): ?>
        <div class="alert alert-warning py-2 small">Tu contraseña es temporal. Elige una nueva para poder usar el sistema.</div>
    <?php endif; ?>
    <form id="frm-cambiar" data-inicio="<?= e($inicio) ?>" autocomplete="off" novalidate>
        <div class="mb-3"><label class="form-label"><?= $obligatorio ? 'Contraseña temporal' : 'Contraseña actual' ?></label>
            <input type="password" name="actual" class="form-control" required autocomplete="current-password" autofocus></div>
        <div class="mb-3"><label class="form-label">Contraseña nueva</label>
            <input type="password" name="password" class="form-control" minlength="8" maxlength="72" required autocomplete="new-password">
            <div class="form-text">Mínimo 8 caracteres.</div></div>
        <div class="mb-4"><label class="form-label">Repite la contraseña nueva</label>
            <input type="password" name="password2" class="form-control" minlength="8" maxlength="72" required autocomplete="new-password"></div>
        <button class="btn btn-gold w-100 py-2" type="submit">Guardar contraseña</button>
    </form>
    <div class="text-center mt-3">
        <?php if ($obligatorio): ?>
            <form method="post" action="<?= e(url('auth', 'logout')) ?>" class="m-0">
                <input type="hidden" name="csrf" value="<?= e(Controller::csrf()) ?>">
                <button class="btn btn-link btn-sm" type="submit">Cerrar sesión</button>
            </form>
        <?php else: ?><a class="small" href="<?= e($inicio) ?>">Volver</a><?php endif; ?>
    </div>
</div></div></div>
<script src="<?= e(asset('vendor/bootstrap/js/bootstrap.bundle.min.js')) ?>"></script>
<script src="<?= e(asset('vendor/sweetalert2/sweetalert2.all.min.js')) ?>"></script>
<script>const BASE_URL = <?= json_encode(base_url() . '/index.php') ?>, MONEDA = <?= json_encode(MONEDA) ?>;</script>
<script src="<?= e(asset('js/app.js')) ?>"></script>
<script src="<?= e(asset('js/cambiar.js')) ?>"></script>
</body>
</html>
