<?php $scripts = ['compras.js']; ?>
<ul class="nav nav-tabs mb-3" role="tablist">
    <li class="nav-item"><button class="nav-link active" data-bs-toggle="tab" data-bs-target="#tabCompras"><i class="bi bi-bag-plus"></i> Compras</button></li>
    <li class="nav-item"><button class="nav-link" data-bs-toggle="tab" data-bs-target="#tabProveedores"><i class="bi bi-building"></i> Proveedores</button></li>
</ul>
<div class="tab-content">
<!-- ===== COMPRAS ===== -->
<div class="tab-pane fade show active" id="tabCompras">
    <div class="text-end mb-3"><button class="btn btn-gold" data-bs-toggle="modal" data-bs-target="#modalCompra"><i class="bi bi-plus-lg"></i> Registrar compra</button></div>
    <div class="card"><div class="table-responsive"><table class="table table-hover align-middle mb-0">
        <thead class="table-light"><tr><th>N.º</th><th>Fecha</th><th>Proveedor</th><th>Registró</th><th class="text-end">Total</th><th></th></tr></thead>
        <tbody>
        <?php foreach ($compras as $c): ?>
            <tr><td>#<?= $c['id_compra'] ?></td><td><?= e($c['fecha']) ?></td><td><?= e($c['proveedor']) ?></td><td><?= e($c['usuario']) ?></td>
                <td class="text-end fw-semibold"><?= money($c['total']) ?></td>
                <td class="text-end"><button class="btn btn-sm btn-outline-dark" data-detalle="<?= $c['id_compra'] ?>"><i class="bi bi-eye"></i> Ver</button></td></tr>
        <?php endforeach; if (!$compras): ?><tr><td colspan="6" class="text-center text-muted py-4">Aún no hay compras registradas.</td></tr><?php endif; ?>
        </tbody></table></div></div>
</div>

<!-- ===== PROVEEDORES ===== -->
<div class="tab-pane fade" id="tabProveedores">
    <div class="text-end mb-3"><button class="btn btn-gold" data-bs-toggle="modal" data-bs-target="#modalProveedor" data-nuevo="#modalProveedor"><i class="bi bi-plus-lg"></i> Nuevo proveedor</button></div>
    <div class="card"><div class="table-responsive"><table class="table table-hover align-middle mb-0">
        <thead class="table-light"><tr><th>Nombre</th><th>Teléfono</th><th>Correo</th><th>Dirección</th><th class="text-end">Acciones</th></tr></thead>
        <tbody>
        <?php foreach ($proveedores as $p): ?>
            <tr><td><?= e($p['nombre']) ?></td><td><?= e($p['telefono']) ?></td><td><?= e($p['correo']) ?></td><td><?= e($p['direccion']) ?></td>
                <td class="text-end">
                    <button class="btn btn-sm btn-outline-primary" data-editar="#modalProveedor" data-json='<?= e(json_encode($p)) ?>'><i class="bi bi-pencil"></i></button>
                    <button class="btn btn-sm btn-outline-danger" data-c="proveedor" data-accion="eliminar" data-confirm="Se quitará a «<?= e($p['nombre']) ?>» de la lista (sus compras se conservan)."
                        data-datos='<?= e(json_encode(['id_proveedor' => $p['id_proveedor']])) ?>'><i class="bi bi-trash"></i></button></td></tr>
        <?php endforeach; if (!$proveedores): ?><tr><td colspan="5" class="text-center text-muted py-4">Aún no hay proveedores.</td></tr><?php endif; ?>
        </tbody></table></div></div>
</div>
</div>

<!-- Modal nueva compra -->
<div class="modal fade" id="modalCompra" tabindex="-1"><div class="modal-dialog modal-lg"><div class="modal-content">
    <div class="modal-header"><h5 class="modal-title">Registrar compra a proveedor</h5><button class="btn-close" data-bs-dismiss="modal"></button></div>
    <div class="modal-body">
        <div class="row g-2 mb-3">
            <div class="col-md-6"><label class="form-label">Proveedor *</label><select id="selProveedor" class="form-select">
                <option value="">Seleccione…</option>
                <?php foreach ($proveedores as $p): ?><option value="<?= $p['id_proveedor'] ?>"><?= e($p['nombre']) ?></option><?php endforeach; ?></select></div>
            <div class="col-md-6"><label class="form-label">Observación</label><input id="observacion" class="form-control" maxlength="255"></div>
        </div>
        <div class="input-group mb-3">
            <select id="selProducto" class="form-select"><?php foreach ($productos as $p): ?><option value="<?= $p['id_producto'] ?>"><?= e($p['codigo'] . ' – ' . $p['nombre']) ?></option><?php endforeach; ?></select>
            <button type="button" id="btnAgregarLinea" class="btn btn-dark"><i class="bi bi-plus"></i> Agregar</button>
        </div>
        <table class="table table-sm align-middle"><thead><tr><th>Producto</th><th>Cantidad</th><th>Costo unit.</th><th class="text-end">Subtotal</th><th></th></tr></thead><tbody id="lineas"></tbody></table>
        <div class="text-end fs-5">Total: <strong id="totalCompra">$0.00</strong></div>
        <small class="text-muted">Al guardar, el stock sube y el costo de cada producto se actualiza con el costo de esta compra. ¿Producto nuevo? Primero créalo en Inventario.</small>
    </div>
    <div class="modal-footer"><button class="btn btn-light" data-bs-dismiss="modal">Cancelar</button><button id="btnGuardarCompra" class="btn btn-gold">Guardar compra</button></div>
</div></div></div>

<!-- Modal detalle -->
<div class="modal fade" id="modalDetalle" tabindex="-1"><div class="modal-dialog modal-lg"><div class="modal-content">
    <div class="modal-header"><h5 class="modal-title">Detalle de la compra</h5><button class="btn-close" data-bs-dismiss="modal"></button></div>
    <div class="modal-body"><table class="table table-sm"><thead><tr><th>Código</th><th>Producto</th><th class="text-end">Cant.</th><th class="text-end">Costo</th><th class="text-end">Subtotal</th></tr></thead><tbody id="detalleBody"></tbody></table></div>
</div></div></div>

<!-- Modal proveedor -->
<div class="modal fade" id="modalProveedor" tabindex="-1" data-titulo-nuevo="Nuevo proveedor" data-titulo-editar="Editar proveedor">
  <div class="modal-dialog"><form class="modal-content" data-c="proveedor" data-a="guardar" novalidate>
    <div class="modal-header"><h5 class="modal-title">Nuevo proveedor</h5><button type="button" class="btn-close" data-bs-dismiss="modal"></button></div>
    <div class="modal-body"><input type="hidden" name="id_proveedor">
        <div class="mb-3"><label class="form-label">Nombre *</label><input name="nombre" class="form-control" required maxlength="120"></div>
        <div class="mb-3"><label class="form-label">Teléfono</label><input name="telefono" class="form-control" maxlength="30"></div>
        <div class="mb-3"><label class="form-label">Correo</label><input type="email" name="correo" class="form-control" maxlength="120"></div>
        <div class="mb-3"><label class="form-label">Dirección</label><input name="direccion" class="form-control" maxlength="255"></div>
    </div>
    <div class="modal-footer"><button type="button" class="btn btn-light" data-bs-dismiss="modal">Cancelar</button><button class="btn btn-gold">Guardar</button></div>
  </form></div>
</div>
<script>const PRODUCTOS = <?= json_encode($productos, JSON_UNESCAPED_UNICODE) ?>;</script>
