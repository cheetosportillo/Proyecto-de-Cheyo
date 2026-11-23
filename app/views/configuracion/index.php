<?php /* Variables: $c (config), $tienePassword, $correoPrueba */
$v = fn(string $k, string $d = '') => e($c[$k] ?? $d); ?>
<div class="row g-4">
  <div class="col-lg-6">
    <div class="card h-100"><div class="card-header fw-semibold"><i class="bi bi-gem text-gold"></i> Datos de la joyería</div>
    <form class="card-body" id="frm-negocio" novalidate>
        <div class="mb-3"><label class="form-label">Nombre *</label><input name="negocio_nombre" class="form-control" required maxlength="100" value="<?= $v('negocio_nombre', APP_NAME) ?>"></div>
        <div class="mb-3"><label class="form-label">Teléfono</label><input type="tel" name="negocio_telefono" class="form-control" maxlength="30" inputmode="numeric" pattern="[0-9+\-\s()]*" oninput="this.value = this.value.replace(/[^0-9+\-\s()]/g, '')" value="<?= $v('negocio_telefono') ?>"></div>
        <div class="mb-3"><label class="form-label">Dirección</label><input name="negocio_direccion" class="form-control" maxlength="200" value="<?= $v('negocio_direccion') ?>"></div>
        <div class="mb-3"><label class="form-label">Mensaje al pie del ticket</label><input name="ticket_mensaje" class="form-control" maxlength="120" value="<?= $v('ticket_mensaje', '¡Gracias por su compra!') ?>"></div>
        <button class="btn btn-gold" type="submit">Guardar datos</button>
    </form></div>
  </div>
  <div class="col-lg-6">
    <div class="card h-100"><div class="card-header fw-semibold"><i class="bi bi-envelope text-gold"></i> Correo para recuperar contraseñas</div>
    <form class="card-body" id="frm-smtp" autocomplete="off" novalidate>
        <div class="row g-2 mb-2">
            <div class="col-8"><label class="form-label">Servidor</label><input name="smtp_host" class="form-control" maxlength="255" placeholder="smtp.gmail.com" value="<?= $v('smtp_host') ?>"></div>
            <div class="col-4"><label class="form-label">Puerto</label><input name="smtp_puerto" class="form-control" inputmode="numeric" value="<?= $v('smtp_puerto', '587') ?>"></div>
        </div>
        <div class="mb-2"><label class="form-label">Cifrado</label>
            <select name="smtp_cifrado" class="form-select">
                <?php foreach (['tls' => 'STARTTLS (puerto 587, recomendado)', 'ssl' => 'SSL/TLS implícito (puerto 465)', 'none' => 'Sin cifrado (no recomendado)'] as $k => $t): ?>
                    <option value="<?= $k ?>" <?= ($c['smtp_cifrado'] ?? 'tls') === $k ? 'selected' : '' ?>><?= e($t) ?></option>
                <?php endforeach; ?>
            </select></div>
        <div class="mb-2"><label class="form-label">Usuario</label><input name="smtp_usuario" class="form-control" maxlength="150" autocomplete="off" value="<?= $v('smtp_usuario') ?>"></div>
        <div class="mb-2"><label class="form-label">Contraseña</label>
            <input type="password" name="smtp_password" class="form-control" autocomplete="new-password" placeholder="<?= $tienePassword ? '•••••••• (guardada; escribe para cambiarla)' : '' ?>">
            <div class="form-text">Se guarda cifrada y nunca se vuelve a mostrar.</div></div>
        <div class="row g-2 mb-2">
            <div class="col-md-6"><label class="form-label">Correo remitente</label><input type="email" name="smtp_remitente" class="form-control" maxlength="150" value="<?= $v('smtp_remitente') ?>"></div>
            <div class="col-md-6"><label class="form-label">Nombre remitente</label><input name="smtp_remitente_nombre" class="form-control" maxlength="100" value="<?= $v('smtp_remitente_nombre') ?>"></div>
        </div>
        <div class="d-flex gap-2 mb-3"><button class="btn btn-gold" type="submit">Guardar correo</button></div>
  </div>
</div>
