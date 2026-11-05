<form class="row g-2 mb-3" method="get">
    <input type="hidden" name="c" value="producto">
    <div class="col-md-4"><input type="text" name="q" value="<?= e($q) ?>" class="form-control" placeholder="Buscar por nombre o código"></div>
    <div class="col-md-3"><select name="cat" class="form-select" onchange="this.form.submit()">
        <option value="0">Todas las categorías</option>
        <?php foreach ($categorias as $c): ?><option value="<?= $c['id_categoria'] ?>" <?= $cat == $c['id_categoria'] ? 'selected' : '' ?>><?= e($c['nombre']) ?></option><?php endforeach; ?>
    </select></div>
    <div class="col-auto"><button class="btn btn-dark"><i class="bi bi-search"></i> Buscar</button></div>
    <?php if ($puedeEditar): ?>
    <div class="col text-end"><button type="button" class="btn btn-gold" data-bs-toggle="modal" data-bs-target="#modalProducto" data-nuevo="#modalProducto"><i class="bi bi-plus-lg"></i> Nuevo producto</button></div>
    <?php endif; ?>
</form>

<div class="card"><div class="table-responsive"><table class="table table-hover align-middle mb-0">
    <thead class="table-light"><tr><th>Código</th><th>Producto</th><th>Categoría</th><th class="text-end">Peso</th>
        <?php if ($puedeEditar): ?><th class="text-end">Costo</th><?php endif; ?>
        <th class="text-end">Precio</th><th class="text-end">Stock</th><?php if ($puedeEditar): ?><th class="text-end">Acciones</th><?php endif; ?></tr></thead>
    <tbody>
    <?php foreach ($productos as $p): ?>
        <tr>
            <td><code><?= e($p['codigo']) ?></code></td>
            <td><?= e($p['nombre']) ?><?php if ($p['descripcion']): ?><br><small class="text-muted"><?= e($p['descripcion']) ?></small><?php endif; ?></td>
            <td><span class="badge bg-secondary"><?= e($p['categoria']) ?></span></td>
            <td class="text-end"><?= number_format($p['peso'], 2) ?> <?= $p['unidad_peso'] === 'kilates' ? 'kt' : 'g' ?></td>
            <?php if ($puedeEditar): ?><td class="text-end"><?= money($p['costo']) ?></td><?php endif; ?>
            <td class="text-end fw-semibold"><?= money($p['precio_venta']) ?></td>
            <td class="text-end"><span class="badge <?= $p['stock'] > 0 ? 'bg-success' : 'bg-danger' ?>"><?= (int)$p['stock'] ?></span></td>
            <?php if ($puedeEditar): ?>
            <td class="text-end">
                <button class="btn btn-sm btn-outline-primary" data-editar="#modalProducto" data-json='<?= e(json_encode($p)) ?>'><i class="bi bi-pencil"></i></button>
                <button class="btn btn-sm btn-outline-danger" data-c="producto" data-accion="eliminar" data-confirm="«<?= e($p['nombre']) ?>» saldrá del inventario (el historial se conserva)."
                    data-datos='<?= e(json_encode(['id_producto' => $p['id_producto']])) ?>'><i class="bi bi-trash"></i></button>
            </td>
            <?php endif; ?>
        </tr>
    <?php endforeach; if (!$productos): ?><tr><td colspan="8" class="text-center text-muted py-4">No hay productos.</td></tr><?php endif; ?>
    </tbody></table></div></div>

<?php if ($puedeEditar): ?>
<div class="modal fade" id="modalProducto" tabindex="-1" data-titulo-nuevo="Nuevo producto" data-titulo-editar="Editar producto">
  <div class="modal-dialog modal-lg"><form class="modal-content" data-c="producto" data-a="guardar" novalidate>
    <div class="modal-header"><h5 class="modal-title">Nuevo producto</h5><button type="button" class="btn-close" data-bs-dismiss="modal"></button></div>
    <div class="modal-body"><input type="hidden" name="id_producto">
      <div class="row g-3">
        <div class="col-md-4"><label class="form-label">Código único *</label><input name="codigo" class="form-control" required maxlength="30"></div>
        <div class="col-md-8"><label class="form-label">Nombre *</label><input name="nombre" class="form-control" required maxlength="120"></div>
        <div class="col-12"><label class="form-label">Descripción</label><input name="descripcion" class="form-control" maxlength="255"></div>
        <div class="col-md-4"><label class="form-label">Categoría *</label><select name="id_categoria" class="form-select" required>
            <option value="">Seleccione…</option>
            <?php foreach ($categorias as $c): ?><option value="<?= $c['id_categoria'] ?>"><?= e($c['nombre']) ?></option><?php endforeach; ?></select></div>
        <div class="col-md-4"><label class="form-label">Peso *</label><input type="number" step="0.001" min="0" name="peso" class="form-control" required></div>
        <div class="col-md-4"><label class="form-label">Unidad de peso</label><select name="unidad_peso" class="form-select"><option value="gramos">Gramos</option><option value="kilates">Kilates</option></select></div>
        <div class="col-md-4"><label class="form-label">Costo de compra *</label><input type="number" step="0.01" min="0" name="costo" class="form-control" required></div>
        <div class="col-md-4"><label class="form-label">Precio de venta *</label><input type="number" step="0.01" min="0.01" name="precio_venta" class="form-control" required></div>
        <div class="col-md-4"><label class="form-label">Stock (piezas) *</label><input type="number" step="1" min="0" name="stock" class="form-control" value="0" required></div>
      </div>
    </div>
    <div class="modal-footer"><button type="button" class="btn btn-light" data-bs-dismiss="modal">Cancelar</button><button class="btn btn-gold">Guardar</button></div>
  </form></div>
</div>
<?php endif; ?>
