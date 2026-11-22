<?php /* Variables: $correoListo (bool), $minutos (int) */ ?><!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="csrf-token" content="<?= e(Controller::csrf()) ?>">
    <title>Recuperar contraseña | <?= e(APP_NAME) ?></title>
    <link href="<?= e(asset('vendor/bootstrap/css/bootstrap.min.css')) ?>" rel="stylesheet">
    <link href="<?= e(asset('vendor/bootstrap-icons/font/bootstrap-icons.min.css')) ?>" rel="stylesheet">
    <link href="<?= e(asset('css/estilos.css')) ?>" rel="stylesheet">
    <style>
        .codigo-recuperacion { font-family: Consolas, "Courier New", monospace; font-size: 1.5rem; letter-spacing: .1em; border: 2px dashed #b8923a;
            border-radius: .75rem; padding: .8rem; text-align: center; background: #fffaf0; user-select: all; word-break: break-all; }
        @media print { .no-print { display: none !important; } }
    </style>
</head>
<body class="login-bg">
<div class="container min-vh-100 d-flex align-items-center justify-content-center py-4">
<div class="card shadow-lg border-0" style="width:100%;max-width:520px;border-radius:1rem"><div class="card-body p-4">
    <div class="text-center mb-3"><i class="bi bi-shield-lock display-5 text-gold"></i><h4 class="mt-2 mb-0">Recuperar contraseña</h4></div>

    <div id="vista-form">
    <ul class="nav nav-pills nav-fill mb-3 no-print" role="tablist">
        <li class="nav-item"><button class="nav-link active" data-bs-toggle="pill" data-bs-target="#m-correo" type="button"><i class="bi bi-envelope"></i> Con internet</button></li>
        <li class="nav-item"><button class="nav-link" data-bs-toggle="pill" data-bs-target="#m-llave" type="button"><i class="bi bi-key"></i> Sin internet</button></li>
    </ul>
    <div class="tab-content">
        <div class="tab-pane fade show active" id="m-correo">
            <p class="small text-muted">Te enviamos un código al correo registrado en tu cuenta. Sirve para cualquier usuario que tenga correo.</p>
            <?php if (!$correoListo): ?><div class="alert alert-warning py-2 small">El envío de correos aún no está configurado. Usa «Sin internet» (Dueña) o pide a la Dueña que restablezca tu contraseña.</div><?php endif; ?>
            <form id="frm-pedir" novalidate>
                <div class="input-group mb-2"><span class="input-group-text"><i class="bi bi-person"></i></span>
                    <input type="text" name="usuario" class="form-control" placeholder="Tu usuario" required maxlength="50" autocomplete="username"></div>
                <button class="btn btn-gold w-100" type="submit" <?= $correoListo ? '' : 'disabled' ?>>Enviarme un código</button>
            </form>
            <form id="frm-codigo" class="mt-3 d-none" novalidate>
                <hr>
                <input type="hidden" name="usuario">
                <div class="mb-2"><label class="form-label">Código recibido (vale <?= (int)$minutos ?> min)</label>
                    <input type="text" name="codigo" class="form-control text-uppercase" placeholder="ABCD-EFGH" required maxlength="12" autocomplete="off"></div>
                <div class="row g-2 mb-2">
                    <div class="col-6"><input type="password" name="password" class="form-control" placeholder="Contraseña nueva" minlength="8" maxlength="72" required autocomplete="new-password"></div>
                    <div class="col-6"><input type="password" name="password2" class="form-control" placeholder="Repítela" minlength="8" maxlength="72" required autocomplete="new-password"></div>
                </div>
                <button class="btn btn-dark w-100" type="submit">Cambiar contraseña</button>
            </form>
        </div>
        <div class="tab-pane fade" id="m-llave">
            <p class="small text-muted"><b>Solo la Dueña.</b> Usa la llave maestra <code>ONIX-XXXX-XXXX-XXXX</code> de la hoja que imprimió al instalar. No necesita internet.</p>
            <form id="frm-llave" novalidate>
                <div class="mb-2"><input type="text" name="usuario" class="form-control" placeholder="Usuario de la Dueña" required maxlength="50" autocomplete="username"></div>
                <div class="mb-2"><input type="text" name="llave" class="form-control text-uppercase" placeholder="ONIX-XXXX-XXXX-XXXX" required maxlength="30" autocomplete="off"></div>
                <div class="row g-2 mb-2">
                    <div class="col-6"><input type="password" name="password" class="form-control" placeholder="Contraseña nueva" minlength="8" maxlength="72" required autocomplete="new-password"></div>
                    <div class="col-6"><input type="password" name="password2" class="form-control" placeholder="Repítela" minlength="8" maxlength="72" required autocomplete="new-password"></div>
                </div>
                <button class="btn btn-dark w-100" type="submit">Restablecer con la llave</button>
            </form>
            <div class="alert alert-info small mt-3 mb-0"><b>¿Eres empleado/a y no tienes internet?</b> Pide a la Dueña que restablezca tu contraseña desde
                <i>Usuarios → Restablecer contraseña</i>: te dará una clave temporal que cambiarás al entrar.</div>
        </div>
    </div>
    </div>

    <div id="vista-llave" class="d-none">
        <div class="alert alert-success py-2">Contraseña actualizada.</div>
        <div class="fw-semibold mb-1">Tu NUEVA llave maestra</div>
        <div class="codigo-recuperacion mb-2" id="llave-nueva"></div>
        <div class="alert alert-danger small py-2">La llave anterior <b>ya no sirve</b>. Esta se muestra <b>una sola vez</b>: imprímela o anótala y guárdala fuera del equipo de la tienda.</div>
        <div class="d-flex gap-2 no-print">
            <button type="button" class="btn btn-outline-dark flex-fill" onclick="window.print()"><i class="bi bi-printer"></i> Imprimir</button>
            <a id="ir-login" class="btn btn-gold flex-fill" href="<?= e(url('auth', 'login')) ?>">Ya la guardé · Iniciar sesión</a>
        </div>
    </div>

    <div class="text-center mt-3 no-print"><a class="small" href="<?= e(url('auth', 'login')) ?>">Volver al inicio de sesión</a></div>
</div></div></div>
<script src="<?= e(asset('vendor/bootstrap/js/bootstrap.bundle.min.js')) ?>"></script>
<script src="<?= e(asset('vendor/sweetalert2/sweetalert2.all.min.js')) ?>"></script>
<script>const BASE_URL = <?= json_encode(base_url() . '/index.php') ?>, MONEDA = <?= json_encode(MONEDA) ?>;</script>
<script src="<?= e(asset('js/app.js')) ?>"></script>
<script src="<?= e(asset('js/ojito.js')) ?>"></script>
<script src="<?= e(asset('js/recuperar.js')) ?>"></script>
</body>
</html>
