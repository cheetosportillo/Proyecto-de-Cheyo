<?php
/* Variables: $usuarios, $catalogo, $cajero (permisos vigentes del rol cajero) */
$grupos = [];
foreach ($catalogo as $p) $grupos[$p['modulo']][] = $p;
?>
<div class="d-flex justify-content-between flex-wrap gap-2 mb-3">
    <p class="text-muted mb-0">Crea usuarios, asigna roles y restablece contraseñas.</p>
    <div class="d-flex gap-2">
        <button class="btn btn-outline-dark" data-bs-toggle="modal" data-bs-target="#modalPermisos"><i class="bi bi-shield-check"></i> Roles y permisos</button>
        <button class="btn btn-gold" data-bs-toggle="modal" data-bs-target="#modalUsuario" data-nuevo="#modalUsuario"><i class="bi bi-person-plus"></i> Nuevo usuario</button>
    </div>
</div>
<div class="card"><div class="table-responsive"><table class="table table-hover align-middle mb-0">
    <thead class="table-light"><tr><th>Nombre</th><th>Usuario</th><th>Correo</th><th>Rol</th><th>Estado</th><th class="text-end">Acciones</th></tr></thead>
    <tbody>
    <?php foreach ($usuarios as $u): $yo = (int)$u['id_usuario'] === (int)$_SESSION['usuario']['id']; ?>
        <tr>
            <td><?= e($u['nombre']) ?></td><td><?= e($u['usuario']) ?></td>
            <td><small><?= $u['email'] ? e($u['email']) : '<span class="text-muted">—</span>' ?></small></td>
            <td><span class="badge <?= $u['rol'] === 'duena' ? 'bg-warning text-dark' : 'bg-secondary' ?>"><?= $u['rol'] === 'duena' ? 'Dueña' : 'Cajero' ?></span></td>
            <td><span class="badge <?= $u['estado'] ? 'bg-success' : 'bg-danger' ?>"><?= $u['estado'] ? 'Activo' : 'Inactivo' ?></span>
                <?php if ($u['debe_cambiar_password']): ?><span class="badge bg-info text-dark" title="Entró con clave temporal y aún no la cambia">Clave temporal</span><?php endif; ?></td>
            <td class="text-end text-nowrap">
                <button class="btn btn-sm btn-outline-primary" title="Editar" data-editar="#modalUsuario"
                    data-json='<?= e(json_encode(['id_usuario' => $u['id_usuario'], 'nombre' => $u['nombre'], 'usuario' => $u['usuario'], 'email' => $u['email'], 'rol' => $u['rol']])) ?>'><i class="bi bi-pencil"></i></button>
                <?php if (!$yo): ?>
                <button class="btn btn-sm btn-outline-secondary" title="Restablecer contraseña" data-reiniciar="<?= (int)$u['id_usuario'] ?>" data-nombre="<?= e($u['nombre']) ?>"><i class="bi bi-key"></i></button>
                <button class="btn btn-sm <?= $u['estado'] ? 'btn-outline-danger' : 'btn-outline-success' ?>" data-c="usuario" data-accion="estado" title="<?= $u['estado'] ? 'Desactivar' : 'Activar' ?>"
                    data-confirm="<?= $u['estado'] ? 'Se desactivará el acceso de este usuario.' : 'Se reactivará el acceso de este usuario.' ?>"
                    data-datos='<?= e(json_encode(['id_usuario' => $u['id_usuario'], 'estado' => $u['estado'] ? 0 : 1])) ?>'>
                    <i class="bi <?= $u['estado'] ? 'bi-person-x' : 'bi-person-check' ?>"></i></button>
                <?php endif; ?>
            </td>
        </tr>
    <?php endforeach; ?>
    </tbody></table></div></div>

<div class="modal fade" id="modalUsuario" tabindex="-1" data-titulo-nuevo="Nuevo usuario" data-titulo-editar="Editar usuario">
  <div class="modal-dialog"><form class="modal-content" id="frm-usuario" novalidate>
    <div class="modal-header"><h5 class="modal-title">Nuevo usuario</h5><button type="button" class="btn-close" data-bs-dismiss="modal"></button></div>
    <div class="modal-body">
        <input type="hidden" name="id_usuario">
        <div class="mb-3"><label class="form-label">Nombre completo *</label><input name="nombre" class="form-control" required maxlength="100"></div>
        <div class="mb-3"><label class="form-label">Usuario *</label><input name="usuario" class="form-control" required maxlength="30" autocomplete="off"></div>
        <div class="mb-3"><label class="form-label">Correo <small class="text-muted">(opcional · sirve para recuperar la contraseña)</small></label>
            <input type="email" name="email" class="form-control" maxlength="150" autocomplete="off"></div>
        <div class="mb-3"><label class="form-label">Rol *</label>
            <select name="rol" class="form-select" required><option value="cajero">Empleado / Cajero</option><option value="duena">Dueña / Administradora</option></select></div>
        <div class="alert alert-light border small mb-0"><i class="bi bi-info-circle"></i> Al crear un usuario se genera una <b>clave temporal</b> que verás una sola vez;
            deberá cambiarla en su primer ingreso. en caso de hacerlo el administrador , también se genera su <b>llave maestra</b>.</div>
    </div>
    <div class="modal-footer"><button type="button" class="btn btn-light" data-bs-dismiss="modal">Cancelar</button><button class="btn btn-gold">Guardar</button></div>
  </form></div>
</div>

<div class="modal fade" id="modalPermisos" tabindex="-1">
  <div class="modal-dialog modal-lg modal-dialog-scrollable"><form class="modal-content" id="frm-permisos">
    <div class="modal-header"><h5 class="modal-title">Permisos del rol «Empleado / Cajero»</h5><button type="button" class="btn-close" data-bs-dismiss="modal"></button></div>
    <div class="modal-body">
        <p class="small text-muted">Administracion de Roles y Permisos</p>
        <?php foreach ($grupos as $modulo => $lista): ?>
            <h6 class="mt-3"><?= e($modulo) ?></h6>
            <?php foreach ($lista as $p): $res = in_array($p['clave'], Permisos::RESERVADOS, true); ?>
                <div class="form-check">
                    <input class="form-check-input" type="checkbox" id="p-<?= e($p['clave']) ?>" value="<?= e($p['clave']) ?>"
                        <?= in_array($p['clave'], $cajero, true) ? 'checked' : '' ?> <?= $res ? 'disabled' : '' ?>>
                    <label class="form-check-label" for="p-<?= e($p['clave']) ?>"><?= e($p['descripcion']) ?> <?= $res ? '<span class="badge bg-secondary">solo Dueña</span>' : '' ?></label>
                </div>
            <?php endforeach; ?>
        <?php endforeach; ?>
    </div>
    <div class="modal-footer"><button type="button" class="btn btn-light" data-bs-dismiss="modal">Cancelar</button><button class="btn btn-gold">Guardar permisos</button></div>
  </form></div>
</div>
