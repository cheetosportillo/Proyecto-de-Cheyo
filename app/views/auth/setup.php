<?php
/**
 * Vista del instalador. Variables que recibe de install.php:
 * $vista ('token'|'bd'|'cuenta'|'hoja'), $error, $datos, $res, $hoja, $flash, $csrf, $local, $tokenListo
 * Todo lo que se imprime pasa por e(). Nunca se vuelve a mostrar una contraseña escrita por la persona.
 */
$paso = ['token' => 0, 'bd' => 1, 'cuenta' => 2, 'hoja' => 3][$vista] ?? 1;
$v = fn(string $k, string $def = '') => e($datos[$k] ?? $def);
$chk = fn(string $k) => !empty($datos[$k]) ? 'checked' : '';
?><!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="robots" content="noindex, nofollow">
    <title>Instalación | <?= e(APP_NAME) ?></title>
    <link href="<?= e(asset('vendor/bootstrap/css/bootstrap.min.css')) ?>" rel="stylesheet">
    <link href="<?= e(asset('vendor/bootstrap-icons/font/bootstrap-icons.min.css')) ?>" rel="stylesheet">
    <link href="<?= e(asset('css/estilos.css')) ?>" rel="stylesheet">
    <style>
        .inst-card { width: 100%; max-width: 680px; border-radius: 1rem; }
        .codigo-recuperacion { font-family: Consolas, "Courier New", monospace; font-size: 2rem; letter-spacing: .12em;
            border: 2px dashed #b8923a; border-radius: .75rem; padding: 1rem; text-align: center; background: #fffaf0; user-select: all; }
        .pasos-inst span { font-size: .8rem; }
        @media print {
            .no-print { display: none !important; }
            body, .login-bg { background: #fff !important; }
            .inst-card { box-shadow: none !important; max-width: 100%; }
        }
    </style>
</head>
<body class="login-bg">
<div class="container min-vh-100 d-flex align-items-center justify-content-center py-4">
<div class="card inst-card shadow-lg border-0"><div class="card-body p-4 p-md-5">

    <div class="text-center mb-3">
        <i class="bi bi-gem display-5 text-gold"></i>
        <h3 class="mt-2 mb-0"><?= e(APP_NAME) ?></h3>
        <small class="text-muted">Instalación del sistema</small>
    </div>

    <div class="pasos-inst d-flex justify-content-center gap-3 mb-4 no-print">
        <?php foreach ([1 => 'Base de datos', 2 => 'Cuenta de la Dueña', 3 => 'Listo'] as $n => $t): ?>
            <span class="badge <?= $paso === $n ? 'text-bg-warning' : ($paso > $n ? 'text-bg-success' : 'text-bg-light border') ?>"><?= $n ?>. <?= e($t) ?></span>
        <?php endforeach; ?>
    </div>

    <?php if ($error): ?><div class="alert alert-danger py-2"><?= e($error) ?></div><?php endif; ?>

<?php /* =============================== TOKEN =============================== */ if ($vista === 'token'): ?>

    <h5>Verificación de acceso</h5>
    <p class="text-muted small">Este instalador solo se puede usar desde el mismo equipo del servidor o con el <b>token de instalación</b>.</p>
    <?php if (!$tokenListo): ?>
        <div class="alert alert-warning small">No se pudo crear <code>config/install.token</code> (permisos). Crea ese archivo con el
            Administrador de archivos de tu hosting, escribe dentro una clave secreta cualquiera y recarga esta página.</div>
    <?php else: ?>
        <ol class="small">
            <li>Abre el <b>Administrador de archivos</b> (o FTP) de tu hosting.</li>
            <li>Entra a la carpeta <code>config/</code> de este sistema y abre <code>install.token</code>.</li>
            <li>Copia su contenido y pégalo aquí.</li>
        </ol>
    <?php endif; ?>
    <form method="post" autocomplete="off">
        <input type="hidden" name="csrf" value="<?= e($csrf) ?>">
        <input type="hidden" name="accion" value="token">
        <div class="mb-3"><label class="form-label">Token de instalación</label>
            <input type="password" name="token" class="form-control" required autofocus></div>
        <button class="btn btn-gold w-100" type="submit">Continuar</button>
    </form>

<?php /* ================================ BD ================================= */ elseif ($vista === 'bd'): ?>

    <h5>Paso 1 · Base de datos</h5>

    <?php if ($res !== null): ?>
        <div class="alert alert-warning">
            <b>Falta un paso manual.</b> No se pudo escribir <code>config/config.local.php</code>. Crea ese archivo con este contenido y
            luego <a href="<?= e(base_url() . '/install.php') ?>">recarga esta página</a>:
            <pre class="bg-light border rounded p-2 mt-2 small user-select-all"><?= e((string)$res['config_manual']) ?></pre>
            <?php foreach ($res['pasos'] as $p): ?><div class="small text-success">✔ <?= e($p) ?></div><?php endforeach; ?>
            <?php foreach ($res['avisos'] as $a): ?><div class="small text-warning-emphasis">⚠ <?= e($a) ?></div><?php endforeach; ?>
        </div>
    <?php endif; ?>

    <form method="post" autocomplete="off" id="frm-bd">
        <input type="hidden" name="csrf" value="<?= e($csrf) ?>">
        <input type="hidden" name="accion" value="bd">

        <div class="mb-3">
            <label class="form-label d-block">¿Dónde se instala?</label>
            <div class="form-check form-check-inline">
                <input class="form-check-input" type="radio" name="entorno" id="e-x" value="xampp" <?= ($datos['entorno'] ?? 'xampp') === 'hosting' ? '' : 'checked' ?>>
                <label class="form-check-label" for="e-x">Mi computadora (XAMPP)</label>
            </div>
            <div class="form-check form-check-inline">
                <input class="form-check-input" type="radio" name="entorno" id="e-h" value="hosting" <?= ($datos['entorno'] ?? '') === 'hosting' ? 'checked' : '' ?>>
                <label class="form-check-label" for="e-h">Hosting (InfinityFree u otro)</label>
            </div>
            <div class="form-text" id="ayuda-entorno"></div>
        </div>

        <div class="row g-3">
            <div class="col-md-8"><label class="form-label">Servidor de base de datos</label>
                <input type="text" name="host" id="f-host" class="form-control" value="<?= $v('host', '127.0.0.1') ?>" required>
                <div class="form-text">XAMPP: usa <code>127.0.0.1</code> (no «localhost») para que se respete el puerto.</div></div>
            <div class="col-md-4"><label class="form-label">Puerto</label>
                <input type="text" name="port" id="f-port" class="form-control" inputmode="numeric" value="<?= $v('port', '3306') ?>" required></div>
            <div class="col-12"><label class="form-label">Nombre de la base</label>
                <input type="text" name="name" class="form-control" value="<?= $v('name', 'joyeria_onix') ?>" required>
                <div class="form-text">En hosting, copia el nombre exacto que muestra tu panel (suele empezar con «if0_…»).</div></div>
            <div class="col-md-6"><label class="form-label">Usuario de la base</label>
                <input type="text" name="user" id="f-user" class="form-control" value="<?= $v('user', 'root') ?>" required></div>
            <div class="col-md-6"><label class="form-label">Contraseña de la base</label>
                <input type="password" name="pass" class="form-control" autocomplete="new-password">
                <div class="form-text">XAMPP con <code>root</code> normalmente va vacía.</div></div>
        </div>

        <div class="border rounded p-3 mt-3 bg-light">
            <div class="form-check mb-2">
                <input class="form-check-input" type="checkbox" name="crear_bd" id="c-bd" value="1" <?= $chk('crear_bd') ?>>
                <label class="form-check-label" for="c-bd"><b>Crear la base si no existe</b>
                    <div class="small text-muted">Si la base ya existe (o tu hosting ya la creó) se omite automáticamente.</div></label>
            </div>
            <div class="form-check">
                <input class="form-check-input" type="checkbox" name="crear_usuario" id="c-usr" value="1" <?= $chk('crear_usuario') ?>>
                <label class="form-check-label" for="c-usr"><b>Crear usuario restringido «onix_app»</b> <span class="badge text-bg-success">recomendado en XAMPP</span>
                    <div class="small text-muted">Contraseña aleatoria y permisos mínimos por tabla (sin borrar ni vaciar tablas). Solo funciona con
                        <code>root</code> en este mismo equipo; si la cuenta no tiene permiso se omite con un aviso.</div></label>
            </div>
        </div>

        <button class="btn btn-gold w-100 py-2 mt-4" type="submit">Instalar base de datos</button>
    </form>
    <script>
    (function () {
        var P = { xampp: { host: '127.0.0.1', user: 'root', bd: true, usr: true,
                           ayuda: 'Usa la cuenta root de XAMPP. Se puede crear la base y un usuario restringido.' },
                  hosting: { host: '', user: '', bd: false, usr: false,
                           ayuda: 'Usa los datos que te dio el hosting (servidor tipo sqlXXX.infinityfree.com). La base y el usuario ya vienen creados: no se intenta crear nada.' } };
        var f = document.getElementById('frm-bd'), host = document.getElementById('f-host'), user = document.getElementById('f-user');
        function aplicar(k, cambiarCampos) {
            var o = k === 'xampp' ? P.hosting : P.xampp;
            document.getElementById('c-bd').checked = P[k].bd;
            document.getElementById('c-usr').checked = P[k].usr;
            document.getElementById('ayuda-entorno').textContent = P[k].ayuda;
            if (cambiarCampos) {
                if (host.value === '' || host.value === o.host) host.value = P[k].host;
                if (user.value === '' || user.value === o.user) user.value = P[k].user;
            }
        }
        f.querySelectorAll('input[name=entorno]').forEach(function (r) {
            r.addEventListener('change', function () { aplicar(r.value, true); });
        });
        document.getElementById('ayuda-entorno').textContent = P[f.querySelector('input[name=entorno]:checked').value].ayuda;
    })();
    </script>

<?php /* ============================== CUENTA =============================== */ elseif ($vista === 'cuenta'): ?>

    <h5>Paso 2 · Cuenta de la Dueña</h5>
    <?php foreach ($flash['pasos'] as $p): ?><div class="small text-success">✔ <?= e($p) ?></div><?php endforeach; ?>
    <?php foreach ($flash['avisos'] as $a): ?><div class="small text-warning-emphasis">⚠ <?= e($a) ?></div><?php endforeach; ?>
    <hr>
    <p class="text-muted small">Esta será la cuenta principal. Al terminar se mostrará <b>una sola vez</b> un código de recuperación para restablecer
        la contraseña sin internet ni correo.</p>
    <form method="post" autocomplete="off">
        <input type="hidden" name="csrf" value="<?= e($csrf) ?>">
        <input type="hidden" name="accion" value="cuenta">
        <div class="mb-3"><label class="form-label">Nombre completo</label>
            <input type="text" name="nombre" class="form-control" maxlength="100" value="<?= $v('nombre') ?>" required autofocus></div>
        <div class="mb-3"><label class="form-label">Usuario</label>
            <input type="text" name="usuario" class="form-control" maxlength="30" pattern="[A-Za-z0-9._\-]{3,30}" value="<?= $v('usuario') ?>" required>
            <div class="form-text">3-30 caracteres: letras, números, punto, guion y guion bajo.</div></div>
        <div class="mb-3"><label class="form-label">Correo electrónico <span class="text-muted">(opcional)</span></label>
            <input type="email" name="email" class="form-control" maxlength="150" value="<?= $v('email') ?>" autocomplete="off">
            <div class="form-text">Sirve para recuperar la contraseña por correo cuando haya internet. Puedes agregarlo o cambiarlo después en Usuarios.</div></div>
        <div class="row g-3">
            <div class="col-md-6"><label class="form-label">Contraseña</label>
                <input type="password" name="password" class="form-control" minlength="8" required autocomplete="new-password">
                <div class="form-text">Mínimo 8 caracteres.</div></div>
            <div class="col-md-6"><label class="form-label">Confirmar contraseña</label>
                <input type="password" name="password2" class="form-control" minlength="8" required autocomplete="new-password"></div>
        </div>
        <button class="btn btn-gold w-100 py-2 mt-4" type="submit">Crear cuenta y finalizar</button>
    </form>
    <div class="text-center mt-3 no-print"><a class="small" href="<?= e(base_url() . '/install.php?paso=bd') ?>">Volver a configurar la base de datos</a></div>

<?php /* =============================== HOJA ================================ */ elseif ($vista === 'hoja'): ?>

    <div class="alert alert-success py-2 no-print"><b>¡Instalación completada!</b> Imprime o anota esta hoja ahora.</div>
    <h5 class="mb-1">Hoja de instalación y recuperación</h5>
    <div class="small text-muted mb-3"><?= e(APP_NAME) ?> · <?= e($hoja['fecha']) ?></div>

    <div class="mb-2 fw-semibold">Código de recuperación de la Dueña</div>
    <div class="codigo-recuperacion mb-2"><?= e($hoja['codigo']) ?></div>
    <div class="alert alert-danger small py-2">
        <b>Se muestra una sola vez.</b> No se guarda en el sistema (solo una huella cifrada), así que nadie podrá volver a verlo.
        Guárdalo en un lugar seguro y <b>fuera del equipo de la tienda</b>. Quien lo tenga puede restablecer la contraseña de la Dueña.
    </div>

    <table class="table table-sm small">
        <tbody>
        <tr><th style="width:38%">Dueña</th><td><?= e($hoja['nombre']) ?></td></tr>
        <tr><th>Usuario de acceso</th><td><code><?= e($hoja['usuario']) ?></code> <span class="text-muted">(la contraseña no se imprime)</span></td></tr>
        <tr><th>Base de datos</th><td><code><?= e($hoja['bd']) ?></code> en <code><?= e($hoja['host']) ?>:<?= e($hoja['port']) ?></code></td></tr>
        <tr><th>Usuario de la base</th><td><code><?= e($hoja['bd_usuario']) ?></code></td></tr>
        <?php if (!empty($hoja['bd_clave'])): ?>
        <tr><th>Clave de la base</th><td><code><?= e($hoja['bd_clave']) ?></code>
            <div class="text-muted">Generada para «onix_app». También está en <code>config/config.local.php</code>.</div></td></tr>
        <?php else: ?>
        <tr><th>Clave de la base</th><td class="text-muted">La entregó tu hosting o tu cuenta de XAMPP; está en <code>config/config.local.php</code>.</td></tr>
        <?php endif; ?>
        </tbody>
    </table>
    <?php foreach ($hoja['avisos'] as $a): ?><div class="small text-warning-emphasis">⚠ <?= e($a) ?></div><?php endforeach; ?>

    <div class="d-flex gap-2 mt-3 no-print">
        <button type="button" class="btn btn-outline-dark flex-fill" onclick="window.print()"><i class="bi bi-printer"></i> Imprimir</button>
        <a class="btn btn-gold flex-fill" href="<?= e(url('auth', 'login')) ?>"
           onclick="return confirm('¿Ya anotaste o imprimiste el código de recuperación? No se podrá volver a mostrar.');">Ir al inicio de sesión</a>
    </div>

<?php endif; ?>

</div></div>
</div>
</body>
</html>
