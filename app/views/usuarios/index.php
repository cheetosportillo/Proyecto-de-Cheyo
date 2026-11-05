<?php $scripts = []; ?>
<div class="d-flex justify-content-between mb-3">
    <p class="text-muted mb-0">Solo la dueña puede administrar usuarios.</p>
    <button class="btn btn-gold" data-bs-toggle="modal" data-bs-target="#modalUsuario" data-nuevo="#modalUsuario"><i class="bi bi-person-plus"></i> Nuevo usuario</button>
</div>
<div class="card"><div class="table-responsive"><table class="table table-hover align-middle mb-0">
    <thead class="table-light"><tr><th>Nombre</th><th>Usuario</th><th>Rol</th><th>Estado</th><th>Creado</th><th class="text-end">Acciones</th></tr></thead>
    <tbody>
    <?php foreach ($usuarios as $u): ?>
        <tr>
            <td><?= e($u['nombre']) ?></td><td><?= e($u['usuario']) ?></td>
            <td><span class="badge <?= $u['rol'] === 'duena' ? 'bg-warning text-dark' : 'bg-secondary' ?>"><?= $u['rol'] === 'duena' ? 'Dueña' : 'Cajero' ?></span></td>
            <td><span class="badge <?= $u['estado'] ? 'bg-success' : 'bg-danger' ?>"><?= $u['estado'] ? 'Activo' : 'Inactivo' ?></span></td>
            <td><small><?= e($u['created_at']) ?></small></td>
            <td class="text-end">
                <button class="btn btn-sm btn-outline-primary" data-editar="#modalUsuario"
                    data-json='<?= e(json_encode(['id_usuario' => $u['id_usuario'], 'nombre' => $u['nombre'], 'usuario' => $u['usuario'], 'rol' => $u['rol']])) ?>'><i class="bi bi-pencil"></i></button>
                <?php if ($u['id_usuario'] != $_SESSION['usuario']['id']): ?>
                <button class="btn btn-sm <?= $u['estado'] ? 'btn-outline-danger' : 'btn-outline-success' ?>" data-c="usuario" data-accion="estado"
                    data-confirm="<?= $u['estado'] ? 'Se desactivará el acceso de este usuario.' : 'Se reactivará el acceso de este usuario.' ?>"
                    data-datos='<?= e(json_encode(['id_usuario' => $u['id_usuario'], 'estado' => $u['estado'] ? 0 : 1])) ?>'>
                    <i class="bi <?= $u['estado'] ? 'bi-person-x' : 'bi-person-check' ?>"></i></button>
                <?php endif; ?>
            </td>
        </tr>
    <?php endforeach; ?>
    </tbody></table></div></div>

<div class="modal fade" id="modalUsuario" tabindex="-1" data-titulo-nuevo="Nuevo usuario" data-titulo-editar="Editar usuario">
  <div class="modal-dialog"><form class="modal-content" data-c="usuario" data-a="guardar" novalidate>
    <div class="modal-header"><h5 class="modal-title">Nuevo usuario</h5><button type="button" class="btn-close" data-bs-dismiss="modal"></button></div>
    <div class="modal-body">
        <input type="hidden" name="id_usuario">
        <div class="mb-3"><label class="form-label">Nombre completo *</label><input name="nombre" class="form-control" required maxlength="100"></div>
        <div class="mb-3"><label class="form-label">Usuario *</label><input name="usuario" class="form-control" required maxlength="30" autocomplete="off"></div>
        <div class="mb-3"><label class="form-label">Contraseña <small class="text-muted">(al editar, déjala vacía para no cambiarla)</small></label>
            <input type="password" name="password" class="form-control" minlength="6" autocomplete="new-password"></div>
        <div class="mb-3"><label class="form-label">Rol *</label>
            <select name="rol" class="form-select" required><option value="cajero">Empleado / Cajero</option><option value="duena">Dueña / Administradora</option></select></div>
    </div>
    <div class="modal-footer"><button type="button" class="btn btn-light" data-bs-dismiss="modal">Cancelar</button><button class="btn btn-gold">Guardar</button></div>
  </form></div>
</div>
