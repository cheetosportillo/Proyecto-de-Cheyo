<?php /* Variables: $c (config), $tienePassword, $correoPrueba */
$v = fn(string $k, string $d = '') => e($c[$k] ?? $d); ?>
<div class="row g-4">
  <div class="col-lg-6">
    <div class="card h-100"><div class="card-header fw-semibold"><i class="bi bi-gem text-gold"></i> Datos de la joyería</div>
    <form class="card-body" id="frm-negocio" novalidate>
        <div class="mb-3"><label class="form-label">Nombre *</label><input name="negocio_nombre" class="form-control" required maxlength="100" value="<?= $v('negocio_nombre', APP_NAME) ?>"></div>
        <div class="mb-3"><label class="form-label">Teléfono</label><input name="negocio_telefono" class="form-control" maxlength="30" value="<?= $v('negocio_telefono') ?>"></div>
        <div class="mb-3"><label class="form-label">Dirección</label><input name="negocio_direccion" class="form-control" maxlength="200" value="<?= $v('negocio_direccion') ?>"></div>
        <div class="mb-3"><label class="form-label">Mensaje al pie del ticket</label><input name="ticket_mensaje" class="form-control" maxlength="120" value="<?= $v('ticket_mensaje', '¡Gracias por su compra!') ?>"></div>
        <button class="btn btn-gold" type="submit">Guardar datos</button>
    </form></div>
  </div>
  <div class="col-lg-6">
    <div class="card h-100"><div class="card-header fw-semibold"><i class="bi bi-envelope text-gold"></i> Correo (SMTP) para recuperar contraseñas</div>
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
            <div class="form-text">Se guarda cifrada y nunca se vuelve a mostrar. En Gmail usa una «contraseña de aplicación».</div></div>
        <div class="row g-2 mb-2">
            <div class="col-md-6"><label class="form-label">Correo remitente</label><input type="email" name="smtp_remitente" class="form-control" maxlength="150" value="<?= $v('smtp_remitente') ?>"></div>
            <div class="col-md-6"><label class="form-label">Nombre remitente</label><input name="smtp_remitente_nombre" class="form-control" maxlength="100" value="<?= $v('smtp_remitente_nombre') ?>"></div>
        </div>
        <div class="form-check mb-3"><input class="form-check-input" type="checkbox" name="smtp_verificar_ssl" id="vssl" value="1" <?= ($c['smtp_verificar_ssl'] ?? '1') !== '0' ? 'checked' : '' ?>>
            <label class="form-check-label" for="vssl">Verificar certificado del servidor <span class="text-muted small">(desmárcalo solo si en XAMPP falla por certificado)</span></label></div>
        <div class="alert alert-light border small py-2"><b>InfinityFree:</b> solo permite el puerto <b>587</b> con STARTTLS (465, 25 y 2525 están bloqueados).</div>
        <div class="d-flex gap-2 mb-3"><button class="btn btn-gold" type="submit">Guardar correo</button></div>
        <div class="border-top pt-3"><label class="form-label">Enviar prueba a</label>
            <div class="input-group"><input type="email" name="correo_prueba" class="form-control" value="<?= e($correoPrueba) ?>" placeholder="tu@correo.com">
                <button class="btn btn-outline-dark" type="button" id="btn-probar"><i class="bi bi-send"></i> Enviar prueba</button></div>
            <div class="form-text">Prueba con lo escrito arriba, aunque todavía no lo hayas guardado.</div></div>
    </form></div>
  </div>
</div>
