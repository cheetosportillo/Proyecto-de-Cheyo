<?php
$scripts = ['ventas.js'];
$jh = JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP;
$qs = fn(int $p) => url('venta', 'index', ['desde' => $desde, 'hasta' => $hasta, 'p' => $p]);
?>
<script>const APP = <?= json_encode(['cajaAbierta' => $cajaAbierta, 'puedeAnular' => $puedeAnular, 'puedeReimprimir' => $puedeReimprimir,
    'usuarioId' => (int)$_SESSION['usuario']['id'], 'negocio' => (string)Config::obtener('negocio_nombre', APP_NAME), 'sinHistorial' => !empty($sinHistorial)], $jh) ?>;</script>
<?php if (!$cajaAbierta): ?>
<div class="alert alert-warning"><i class="bi bi-lock"></i> La caja está <strong>cerrada</strong>: no se puede cobrar. <a href="<?= e(url('caja')) ?>" class="alert-link">Abrir caja</a></div>
<?php endif; ?>
<ul class="nav nav-tabs mb-3">
    <li class="nav-item"><button class="nav-link <?= $tabHistorial ? '' : 'active' ?>" data-bs-toggle="tab" data-bs-target="#tabCaja"><i class="bi bi-cash-coin"></i> Caja</button></li>
    <?php if (empty($sinHistorial)): ?><li class="nav-item"><button class="nav-link <?= $tabHistorial ? 'active' : '' ?>" data-bs-toggle="tab" data-bs-target="#tabHistorial"><i class="bi bi-receipt"></i> Historial <?= $verTodas ? '' : '(mis ventas)' ?></button></li><?php endif; ?>
</ul>
<div class="tab-content">
<div class="tab-pane fade <?= $tabHistorial ? '' : 'show active' ?>" id="tabCaja">
  <div id="avisoOffline" class="alert alert-info py-2 d-none"><i class="bi bi-wifi-off"></i> Sin conexión: las ventas se guardan en este equipo y se sincronizan al volver internet.</div>
  <div class="row g-3">
    <div class="col-lg-6"><div class="card"><div class="card-body">
        <div class="row g-2 mb-3">
            <div class="col-5"><select id="categoria" class="form-select"><option value="0">Todas las categorías</option>
                <?php foreach ($categorias as $c): ?><option value="<?= $c['id_categoria'] ?>"><?= e($c['nombre']) ?></option><?php endforeach; ?></select></div>
            <div class="col-7"><input id="buscador" class="form-control" placeholder="Escanea el código o busca… (Enter agrega)" autocomplete="off" autofocus></div>
        </div>
        <div id="resultados" class="list-group lista-resultados"></div>
    </div></div></div>
    <div class="col-lg-6"><div class="card"><div class="card-header fw-semibold d-flex justify-content-between">Venta actual
          <button id="btnVaciar" class="btn btn-sm btn-outline-secondary">Vaciar</button></div>
        <div class="table-responsive"><table class="table table-sm align-middle mb-0">
            <thead><tr><th>Pieza</th><th>Cant.</th><th class="text-end">Precio</th><th class="text-end">Subtotal</th><th></th></tr></thead><tbody id="carrito"></tbody></table></div>
        <div class="card-body border-top">
            <div class="d-flex justify-content-between fs-4"><span>Total</span><strong id="total"><?= MONEDA ?>0.00</strong></div>
            <div class="row g-2 align-items-end mt-1">
                <div class="col-7"><label class="form-label mb-0">Efectivo recibido</label>
                    <div class="input-group"><span class="input-group-text"><?= MONEDA ?></span><input id="efectivo" type="number" step="0.01" min="0" class="form-control form-control-lg"></div></div>
                <div class="col-5 text-end"><div class="small text-muted">Vuelto</div><div id="cambio" class="fs-4 fw-bold text-muted"><?= MONEDA ?>0.00</div></div>
            </div>
            <div class="d-flex flex-wrap gap-1 mt-2">
                <button type="button" class="btn btn-sm btn-outline-dark" id="btnExacto">Efectivo exacto</button>
                <?php foreach ([5, 10, 20, 50] as $b): ?><button type="button" class="btn btn-sm btn-outline-secondary" data-billete="<?= $b ?>">+<?= MONEDA . $b ?></button><?php endforeach; ?>
                <button type="button" class="btn btn-sm btn-outline-danger" id="btnLimpiarEf">C</button>
            </div>
            <button id="btnCobrar" class="btn btn-gold btn-lg w-100 mt-3" disabled><i class="bi bi-check2-circle"></i> Cobrar e imprimir</button>
        </div></div></div>
  </div>
</div>

<?php if (empty($sinHistorial)): ?>
<div class="tab-pane fade <?= $tabHistorial ? 'show active' : '' ?>" id="tabHistorial">
    <form class="row g-2 mb-3" method="get">
        <input type="hidden" name="c" value="venta">
        <div class="col-auto"><label class="form-label mb-0 small">Desde</label><input type="date" name="desde" value="<?= e($desde) ?>" class="form-control"></div>
        <div class="col-auto"><label class="form-label mb-0 small">Hasta</label><input type="date" name="hasta" value="<?= e($hasta) ?>" class="form-control"></div>
        <div class="col-auto align-self-end"><button class="btn btn-dark"><i class="bi bi-funnel"></i> Filtrar</button></div>
        <div class="col text-end align-self-end fs-5">Total del periodo (<?= (int)$h['num_activas'] ?> ventas): <strong><?= money($h['total_periodo']) ?></strong></div>
    </form>
    <div class="card"><div class="table-responsive"><table class="table table-hover align-middle mb-0">
        <thead class="table-light"><tr><th>Ticket</th><th>Fecha</th><?php if ($verTodas): ?><th>Cajero</th><?php endif; ?><th class="text-end">Total</th><th class="text-end">Efectivo</th><th class="text-end">Vuelto</th><th></th></tr></thead>
        <tbody>
        <?php foreach ($h['filas'] as $v): $anul = $v['estado'] === 'ANULADA'; ?>
            <tr class="<?= $anul ? 'text-muted' : '' ?>"><td>#<?= str_pad($v['numero_ticket'], 6, '0', STR_PAD_LEFT) ?><?= $anul ? ' <span class="badge bg-secondary">Anulada</span>' : '' ?></td><td><?= e($v['fecha']) ?></td>
                <?php if ($verTodas): ?><td><?= e($v['cajero']) ?></td><?php endif; ?>
                <td class="text-end fw-semibold"><?= $anul ? '<s>' . money($v['total']) . '</s>' : money($v['total']) ?></td><td class="text-end"><?= money($v['efectivo']) ?></td><td class="text-end"><?= money($v['cambio']) ?></td>
                <td class="text-end"><button class="btn btn-sm btn-outline-dark" data-ver="<?= $v['id_venta'] ?>"><i class="bi bi-eye"></i> Ver detalle</button></td></tr>
        <?php endforeach; if (!$h['filas']): ?><tr><td colspan="7" class="text-center text-muted py-4">Sin ventas en este periodo.</td></tr><?php endif; ?>
        </tbody></table></div></div>
    <?php if ($h['paginas'] > 1): ?><nav class="mt-3"><ul class="pagination pagination-sm justify-content-center mb-0">
        <?php for ($i = 1; $i <= $h['paginas']; $i++): ?><li class="page-item <?= $i == $h['pagina'] ? 'active' : '' ?>"><a class="page-link" href="<?= e($qs($i)) ?>"><?= $i ?></a></li><?php endfor; ?>
    </ul></nav><?php endif; ?>
</div>
<?php endif; ?>
</div>

<div class="modal fade" id="modalVenta" tabindex="-1"><div class="modal-dialog modal-lg"><div class="modal-content">
    <div class="modal-header"><h5 class="modal-title" id="vTitulo">Detalle de venta</h5><button class="btn-close" data-bs-dismiss="modal"></button></div>
    <div class="modal-body"><div id="vInfo" class="small text-muted mb-2"></div>
        <table class="table table-sm"><thead><tr><th>Código</th><th>Pieza</th><th class="text-end">Cant.</th><th class="text-end">Peso</th><th class="text-end">Precio</th><th class="text-end">Subtotal</th></tr></thead><tbody id="vBody"></tbody></table></div>
    <div class="modal-footer"><button class="btn btn-outline-danger d-none" id="vAnular"><i class="bi bi-x-octagon"></i> Anular venta</button>
        <button class="btn btn-dark d-none" id="vImprimir"><i class="bi bi-printer"></i> Imprimir</button></div>
</div></div></div>
