<?php /* Variables: $obligatorio, $nombre, $inicio, $esDuena */ ?><!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="csrf-token" content="<?= e(Controller::csrf()) ?>">
    <title>Cambiar contraseña | <?= e(APP_NAME) ?></title>
    <link href="<?= e(asset('vendor/bootstrap/css/bootstrap.min.css')) ?>" rel="stylesheet">
    <link href="<?= e(asset('vendor/bootstrap-icons/font/bootstrap-icons.min.css')) ?>" rel="stylesheet">
    <link href="<?= e(asset('css/estilos.css')) ?>" rel="stylesheet">
    <style>
        .codigo-recuperacion { font-family: Consolas, "Courier New", monospace; font-size: 1.5rem; letter-spacing: .1em; border: 2px dashed #b8923a;
            border-radius: .75rem; padding: .8rem; text-align: center; background: #fffaf0; user-select: all; word-break: break-all; }
        .solo-impresion { display: none; }
        @media print { body * { visibility: hidden; } .solo-impresion, .solo-impresion * { visibility: visible; }
            .solo-impresion { display: block; position: absolute; left: 0; top: 0; width: 100%; padding: 2rem; } }
    </style>
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
    <?php if ($esDuena && !$obligatorio): ?>
    <hr class="my-4">
    <div id="bloque-llave">
        <h6><i class="bi bi-shield-lock"></i> Llave maestra</h6>
        <p class="small text-muted">Sirve para recuperar tu acceso sin internet. Al regenerarla, la anterior <b>deja de funcionar</b> y la nueva se muestra una sola vez.</p>
        <form id="frm-llave" autocomplete="off" novalidate>
            <div class="mb-2"><input type="password" name="actual" class="form-control" placeholder="Confirma tu contraseña actual" required autocomplete="current-password"></div>
            <button class="btn btn-outline-dark w-100" type="submit"><i class="bi bi-arrow-repeat"></i> Regenerar llave maestra</button>
        </form>
    </div>
    <div id="vista-llave" class="d-none">
        <div class="alert alert-success py-2 mb-2">Llave regenerada. La anterior ya no sirve.</div>
        <div class="codigo-recuperacion mb-2" id="llave-nueva"></div>
        <div class="alert alert-danger small py-2">Se muestra <b>una sola vez</b>: imprímela o anótala y guárdala fuera del equipo de la tienda.</div>
        <div class="d-flex gap-2">
            <button type="button" class="btn btn-outline-dark flex-fill" id="btn-imprimir"><i class="bi bi-printer"></i> Imprimir</button>
            <button type="button" class="btn btn-gold flex-fill" id="btn-listo">Ya la guardé</button>
        </div>
    </div>
    <div class="solo-impresion" id="hoja-llave">
        <h2><?= e(APP_NAME) ?> · Llave maestra</h2>
        <p>Cuenta: <b><?= e($nombre) ?></b><br>Generada: <span id="hoja-fecha"></span></p>
        <div class="codigo-recuperacion" id="hoja-codigo"></div>
        <p style="margin-top:1rem">Sirve para restablecer la contraseña de la Dueña sin internet (Iniciar sesión → «¿Olvidaste tu contraseña?» → Sin internet).
            Guarda esta hoja bajo llave, fuera de la tienda. Cualquier llave anterior quedó anulada.</p>
    </div>
    <?php endif; ?>
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
<script src="<?= e(asset('js/ojito.js')) ?>"></script>
<script src="<?= e(asset('js/cambiar.js')) ?>"></script>
</body>
</html>
