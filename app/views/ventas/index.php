<?php $scripts = ['ventas.js']; $esDuena = $_SESSION['usuario']['rol'] === 'duena'; ?>
<ul class="nav nav-tabs mb-3">
    <li class="nav-item"><button class="nav-link <?= $tabHistorial ? '' : 'active' ?>" data-bs-toggle="tab" data-bs-target="#tabCaja"><i class="bi bi-cash-coin"></i> Caja</button></li>
    <li class="nav-item"><button class="nav-link <?= $tabHistorial ? 'active' : '' ?>" data-bs-toggle="tab" data-bs-target="#tabHistorial"><i class="bi bi-receipt"></i> Historial / Reportes</button></li>
</ul>
<div class="tab-content">
<!-- ===== CAJA ===== -->
<div class="tab-pane fade <?= $tabHistorial ? '' : 'show active' ?>" id="tabCaja">
  <div class="row g-3">
    <div class="col-lg-6">
      <div class="card"><div class="card-body">
        <div class="row g-2 mb-3">
            <div class="col-5"><select id="categoria" class="form-select">
                <option value="0">Todas las categorías</option>
                <?php foreach ($categorias as $c): ?><option value="<?= $c['id_categoria'] ?>"><?= e($c['nombre']) ?></option><?php endforeach; ?></select></div>
            <div class="col-7"><input id="buscador" class="form-control" placeholder="Buscar por nombre o código…" autocomplete="off"></div>
        </div>
        <div id="resultados" class="list-group lista-resultados"></div>
      </div></div>
    </div>
    <div class="col-lg-6">
      <div class="card"><div class="card-header fw-semibold d-flex justify-content-between">Venta actual
          <button id="btnVaciar" class="btn btn-sm btn-outline-secondary">Vaciar</button></div>
        <div class="table-responsive"><table class="table table-sm align-middle mb-0">
            <thead><tr><th>Pieza</th><th>Cant.</th><th class="text-end">Precio</th><th class="text-end">Subtotal</th><th></th></tr></thead>
            <tbody id="carrito"></tbody></table></div>
        <div class="card-body border-top">
            <div class="d-flex justify-content-between fs-4"><span>Total</span><strong id="total">$0.00</strong></div>
            <div class="row g-2 align-items-center mt-2">
                <div class="col-6"><label class="form-label mb-0">Efectivo recibido</label>
                    <div class="input-group"><span class="input-group-text"><?= MONEDA ?></span><input id="efectivo" type="number" step="0.01" min="0" class="form-control form-control-lg"></div></div>
                <div class="col-6 text-end"><div class="small text-muted">Vuelto</div><div id="cambio" class="fs-4 fw-bold text-muted">$0.00</div></div>
            </div>
            <button id="btnCobrar" class="btn btn-gold btn-lg w-100 mt-3" disabled><i class="bi bi-check2-circle"></i> Cobrar e imprimir</button>
        </div></div>
    </div>
  </div>
</div>

<!-- ===== HISTORIAL ===== -->
<div class="tab-pane fade <?= $tabHistorial ? 'show active' : '' ?>" id="tabHistorial">
    <form class="row g-2 mb-3" method="get">
        <input type="hidden" name="c" value="venta">
        <div class="col-auto"><label class="form-label mb-0 small">Desde</label><input type="date" name="desde" value="<?= e($desde) ?>" class="form-control"></div>
        <div class="col-auto"><label class="form-label mb-0 small">Hasta</label><input type="date" name="hasta" value="<?= e($hasta) ?>" class="form-control"></div>
        <div class="col-auto align-self-end"><button class="btn btn-dark"><i class="bi bi-funnel"></i> Filtrar</button></div>
        <div class="col text-end align-self-end fs-5">Total en el periodo: <strong><?= money($totalPeriodo) ?></strong></div>
    </form>
    <div class="card"><div class="table-responsive"><table class="table table-hover align-middle mb-0">
        <thead class="table-light"><tr><th>N.º</th><th>Fecha</th><?php if ($esDuena): ?><th>Cajero</th><?php endif; ?><th class="text-end">Total</th><th class="text-end">Efectivo</th><th class="text-end">Vuelto</th><th></th></tr></thead>
        <tbody>
        <?php foreach ($historial as $v): ?>
            <tr><td>#<?= str_pad($v['id_venta'], 6, '0', STR_PAD_LEFT) ?></td><td><?= e($v['fecha']) ?></td>
                <?php if ($esDuena): ?><td><?= e($v['cajero']) ?></td><?php endif; ?>
                <td class="text-end fw-semibold"><?= money($v['total']) ?></td><td class="text-end"><?= money($v['efectivo']) ?></td><td class="text-end"><?= money($v['cambio']) ?></td>
                <td class="text-end"><a class="btn btn-sm btn-outline-dark" target="_blank" href="<?= e(url('venta', 'ticket', ['id' => $v['id_venta']])) ?>"><i class="bi bi-printer"></i></a></td></tr>
        <?php endforeach; if (!$historial): ?><tr><td colspan="7" class="text-center text-muted py-4">Sin ventas en este periodo.</td></tr><?php endif; ?>
        </tbody></table></div></div>
</div>
</div>
