<?php
$scripts = ['productos.js'];
$jh = JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP;
?>
<?php if ($puedeEditar): ?>
<ul class="nav nav-tabs mb-3">
    <li class="nav-item"><button class="nav-link active" data-bs-toggle="tab" data-bs-target="#tabProductos"><i class="bi bi-gem"></i> Productos</button></li>
    <li class="nav-item"><button class="nav-link" data-bs-toggle="tab" data-bs-target="#tabCategorias"><i class="bi bi-tags"></i> Categorías</button></li>
</ul>
<?php endif; ?>
<div class="tab-content"><div class="tab-pane fade show active" id="tabProductos">
<form class="row g-2 mb-3" method="get">
    <input type="hidden" name="c" value="producto">
    <div class="col-md-4"><input type="text" name="q" value="<?= e($q) ?>" class="form-control" placeholder="Buscar por nombre o código"></div>
    <div class="col-md-3"><select name="cat" class="form-select" onchange="this.form.submit()">
        <option value="0">Todas las categorías</option>
        <?php foreach ($categorias as $c): ?><option value="<?= $c['id_categoria'] ?>" <?= $cat == $c['id_categoria'] ? 'selected' : '' ?>><?= e($c['nombre']) ?></option><?php endforeach; ?>
    </select></div>
    <div class="col-auto"><button class="btn btn-dark"><i class="bi bi-search"></i> Buscar</button></div>
    <?php if ($puedeEditar): ?>
    <div class="col-auto"><div class="form-check form-switch mt-2"><input class="form-check-input" type="checkbox" name="inactivos" value="1" id="chkInact" <?= $inactivos ? 'checked' : '' ?> onchange="this.form.submit()"><label class="form-check-label" for="chkInact">Ver inactivos / Reactivar</label></div></div>
    <div class="col text-end">
        <button type="button" class="btn btn-outline-dark" data-bs-toggle="modal" data-bs-target="#modalPrecios"><i class="bi bi-percent"></i> Precios masivos</button>
        <button type="button" class="btn btn-gold" data-bs-toggle="modal" data-bs-target="#modalProducto" data-nuevo="#modalProducto"><i class="bi bi-plus-lg"></i> Nuevo producto</button>
    </div>
    <?php endif; ?>
</form>

<div class="card"><div class="table-responsive"><table class="table table-hover align-middle mb-0">
    <thead class="table-light"><tr><th>Código</th><th>Producto</th><th>Categoría</th><th class="text-end">Peso</th>
        <?php if ($puedeEditar): ?><th class="text-end">Costo (CPP)</th><?php endif; ?>
        <th class="text-end">Precio</th><th class="text-end">Stock</th><?php if ($puedeEditar): ?><th class="text-end">Acciones</th><?php endif; ?></tr></thead>
    <tbody>
    <?php foreach ($productos as $p): ?>
        <tr>
            <td><code><?= e($p['codigo']) ?></code></td>
            <td><?= e($p['nombre']) ?><?php if ($p['descripcion']): ?><br><small class="text-muted"><?= e($p['descripcion']) ?></small><?php endif; ?></td>
            <td><span class="badge bg-secondary"><?= e($p['categoria']) ?></span></td>
            <td class="text-end"><?= e(fmt_peso($p['peso'], $p['unidad_peso'])) ?></td>
            <?php if ($puedeEditar): ?><td class="text-end"><?= MONEDA . number_format((float)$p['costo'], 4) ?></td><?php endif; ?>
            <td class="text-end fw-semibold"><?= money($p['precio_venta']) ?></td>
            <td class="text-end"><span class="badge <?= $p['stock'] > 0 ? 'bg-success' : 'bg-danger' ?>"><?= (int)$p['stock'] ?></span></td>
            <?php if ($puedeEditar): ?>
            <td class="text-end">
              <?php if ($inactivos): ?>
                <button class="btn btn-sm btn-outline-success" data-c="producto" data-accion="reactivar" data-confirm="¿Reactivar «<?= e($p['nombre']) ?>»?" data-datos='<?= e(json_encode(['id_producto' => $p['id_producto']])) ?>'><i class="bi bi-arrow-counterclockwise"></i> Reactivar</button>
              <?php else: ?>
                <button class="btn btn-sm btn-outline-primary" data-editar="#modalProducto" data-json='<?= e(json_encode($p)) ?>'><i class="bi bi-pencil"></i></button>
                <button class="btn btn-sm btn-outline-danger" data-c="producto" data-accion="eliminar" data-confirm="«<?= e($p['nombre']) ?>» saldrá del inventario (solo si no tiene existencias; el historial se conserva)."
                    data-datos='<?= e(json_encode(['id_producto' => $p['id_producto']])) ?>'><i class="bi bi-trash"></i></button>
              <?php endif; ?>
            </td>
            <?php endif; ?>
        </tr>
    <?php endforeach; if (!$productos): ?><tr><td colspan="8" class="text-center text-muted py-4">No hay productos.</td></tr><?php endif; ?>
    </tbody></table></div></div>
</div>

<?php if ($puedeEditar): ?>
<!-- ===== CATEGORÍAS ===== -->
<div class="tab-pane fade" id="tabCategorias">
    <div class="text-end mb-3"><button class="btn btn-gold" data-bs-toggle="modal" data-bs-target="#modalCategoria" data-nuevo="#modalCategoria"><i class="bi bi-plus-lg"></i> Nueva categoría</button></div>
    <div class="card"><div class="table-responsive"><table class="table table-hover align-middle mb-0">
        <thead class="table-light"><tr><th>Categoría</th><th>Unidad de peso</th><th class="text-end">Stock mín.</th><th class="text-end">Peso mín.</th><th class="text-end">Productos</th><th class="text-end">Piezas</th><th>Estado</th><th class="text-end">Acciones</th></tr></thead>
        <tbody>
        <?php foreach ($todasCategorias as $c): ?>
            <tr class="<?= $c['estado'] ? '' : 'text-muted' ?>"><td><?= e($c['nombre']) ?></td><td><?= $c['unidad_peso'] === 'kilates' ? 'Kilates' : 'Gramos' ?></td>
                <td class="text-end"><?= (int)$c['stock_minimo'] ?></td><td class="text-end"><?= number_format($c['peso_minimo'], 3) ?></td>
                <td class="text-end"><?= (int)$c['num_productos'] ?></td><td class="text-end"><?= (int)$c['stock_total'] ?></td>
                <td><span class="badge <?= $c['estado'] ? 'bg-success' : 'bg-secondary' ?>"><?= $c['estado'] ? 'Activa' : 'Inactiva' ?></span></td>
                <td class="text-end">
                  <button class="btn btn-sm btn-outline-primary" data-editar="#modalCategoria" data-json='<?= e(json_encode($c)) ?>'><i class="bi bi-pencil"></i></button>
                  <?php if ($c['estado']): ?>
                  <button class="btn btn-sm btn-outline-danger" data-c="categoria" data-accion="baja" data-confirm="¿Dar de baja «<?= e($c['nombre']) ?>»? Solo es posible si no tiene existencias." data-datos='<?= e(json_encode(['id_categoria' => $c['id_categoria']])) ?>'><i class="bi bi-trash"></i></button>
                  <?php else: ?>
                  <button class="btn btn-sm btn-outline-success" data-c="categoria" data-accion="reactivar" data-confirm="¿Reactivar «<?= e($c['nombre']) ?>»?" data-datos='<?= e(json_encode(['id_categoria' => $c['id_categoria']])) ?>'><i class="bi bi-arrow-counterclockwise"></i></button>
                  <?php endif; ?></td></tr>
        <?php endforeach; ?>
        </tbody></table></div></div>
</div>
<?php endif; ?>
</div>

<?php if ($puedeEditar): ?>
<div class="modal fade" id="modalProducto" tabindex="-1" data-titulo-nuevo="Nuevo producto" data-titulo-editar="Editar producto">
  <div class="modal-dialog modal-lg"><form class="modal-content" data-c="producto" data-a="guardar" novalidate>
    <div class="modal-header"><h5 class="modal-title">Nuevo producto</h5><button type="button" class="btn-close" data-bs-dismiss="modal"></button></div>
    <div class="modal-body"><input type="hidden" name="id_producto">
      <div class="row g-3">
        <div class="col-md-5"><label class="form-label">Código único *</label>
            <div class="input-group"><input name="codigo" class="form-control" required maxlength="30"><button type="button" class="btn btn-outline-dark" id="btnGenCodigo" title="Siguiente correlativo de la categoría">Generar código</button></div></div>
        <div class="col-md-7"><label class="form-label">Nombre *</label><input name="nombre" class="form-control" required maxlength="120"></div>
        <div class="col-12"><label class="form-label">Descripción</label><input name="descripcion" class="form-control" maxlength="255"></div>
        <div class="col-md-4"><label class="form-label">Categoría *</label><select name="id_categoria" id="selCategoria" class="form-select" required>
            <option value="">Seleccione…</option>
            <?php foreach ($categorias as $c): ?><option value="<?= $c['id_categoria'] ?>" data-unidad="<?= e($c['unidad_peso']) ?>"><?= e($c['nombre']) ?> (<?= $c['unidad_peso'] === 'kilates' ? 'kt' : 'g' ?>)</option><?php endforeach; ?></select></div>
        <div class="col-md-4"><label class="form-label">Peso por pieza * <small class="text-muted" id="lblUnidad"></small></label><input type="number" step="0.001" min="0" max="99999.999" name="peso" class="form-control" required>
            <div class="form-text">Se bloquea tras el primer movimiento en el Kardex.</div></div>
        <div class="col-md-4"><label class="form-label">Precio de venta *</label><input type="number" step="0.01" min="0.01" max="9999999.99" name="precio_venta" id="inpPrecio" class="form-control" required></div>
        <div class="col-md-4"><label class="form-label">Costo promedio (CPP)</label><input name="costo" id="inpCosto" class="form-control" disabled placeholder="Lo fija el Kardex"></div>
        <div class="col-md-8"><label class="form-label">Ganancia por pieza</label><div id="ganancia" class="form-control-plaintext fw-semibold">—</div></div>
      </div>
    </div>
    <div class="modal-footer"><button type="button" class="btn btn-light" data-bs-dismiss="modal">Cancelar</button><button class="btn btn-gold">Guardar</button></div>
  </form></div>
</div>

<div class="modal fade" id="modalCategoria" tabindex="-1" data-titulo-nuevo="Nueva categoría" data-titulo-editar="Editar categoría">
  <div class="modal-dialog"><form class="modal-content" data-c="categoria" data-a="guardar" novalidate>
    <div class="modal-header"><h5 class="modal-title">Nueva categoría</h5><button type="button" class="btn-close" data-bs-dismiss="modal"></button></div>
    <div class="modal-body"><input type="hidden" name="id_categoria">
      <div class="mb-3"><label class="form-label">Nombre *</label><input name="nombre" class="form-control" required maxlength="100"></div>
      <div class="mb-3"><label class="form-label">Unidad de peso *</label><select name="unidad_peso" class="form-select"><option value="gramos">Gramos (Oro, Plata, Cobre…)</option><option value="kilates">Kilates de peso (Gemas)</option></select>
          <div class="form-text">No se puede cambiar si la categoría ya tiene movimientos en el Kardex.</div></div>
      <div class="row g-2"><div class="col-6"><label class="form-label">Stock mínimo (piezas)</label><input type="number" min="0" step="1" name="stock_minimo" class="form-control" value="0"></div>
        <div class="col-6"><label class="form-label">Peso mínimo</label><input type="number" min="0" step="0.001" name="peso_minimo" class="form-control" value="0"></div></div>
    </div>
    <div class="modal-footer"><button type="button" class="btn btn-light" data-bs-dismiss="modal">Cancelar</button><button class="btn btn-gold">Guardar</button></div>
  </form></div>
</div>

<div class="modal fade" id="modalPrecios" tabindex="-1"><div class="modal-dialog"><form class="modal-content" data-c="producto" data-a="preciosMasivos" novalidate>
    <div class="modal-header"><h5 class="modal-title">Actualización masiva de precios</h5><button type="button" class="btn-close" data-bs-dismiss="modal"></button></div>
    <div class="modal-body">
      <div class="mb-3"><label class="form-label">Porcentaje (+ sube, − baja) *</label><input type="number" step="0.01" min="-90" max="500" name="porcentaje" class="form-control" required></div>
      <div class="mb-3"><label class="form-label">Aplicar a</label><select name="id_categoria" class="form-select"><option value="0">Todo el catálogo activo</option>
        <?php foreach ($categorias as $c): ?><option value="<?= $c['id_categoria'] ?>"><?= e($c['nombre']) ?></option><?php endforeach; ?></select></div>
      <small class="text-muted">Queda registrado en la bitácora.</small></div>
    <div class="modal-footer"><button type="button" class="btn btn-light" data-bs-dismiss="modal">Cancelar</button><button class="btn btn-gold">Aplicar</button></div>
</form></div></div>
<?php endif; ?>
