<?php $scripts = ['kardex.js'];
$jh = JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP;
$ent = ['SALDO_INICIAL', 'ENTRADA', 'AJUSTE_ENTRADA', 'ANULACION_VENTA'];
$filtro = ['prod' => $prod, 'cat' => $cat, 'desde' => $desde, 'hasta' => $hasta]; ?>
<form class="row g-2 mb-3 no-print" method="get"><input type="hidden" name="c" value="kardex">
    <div class="col-md-3"><select name="prod" class="form-select"><option value="0">Todos los productos</option>
        <?php foreach ($productos as $p): ?><option value="<?= $p['id_producto'] ?>" <?= $prod == $p['id_producto'] ? 'selected' : '' ?>><?= e($p['codigo'] . ' – ' . $p['nombre']) ?></option><?php endforeach; ?></select></div>
    <div class="col-md-2"><select name="cat" class="form-select"><option value="0">Todas las categorías</option>
        <?php foreach ($categorias as $c): ?><option value="<?= $c['id_categoria'] ?>" <?= $cat == $c['id_categoria'] ? 'selected' : '' ?>><?= e($c['nombre']) ?></option><?php endforeach; ?></select></div>
    <div class="col-auto"><input type="date" name="desde" value="<?= e($desde) ?>" class="form-control"></div>
    <div class="col-auto"><input type="date" name="hasta" value="<?= e($hasta) ?>" class="form-control"></div>
    <div class="col-auto"><button class="btn btn-dark"><i class="bi bi-funnel"></i></button>
        <?php foreach ($rapidos as $t => [$d, $h]): ?><a class="btn btn-outline-secondary" href="<?= e(url('kardex', 'index', ['prod' => $prod, 'cat' => $cat, 'desde' => $d, 'hasta' => $h])) ?>"><?= $t ?></a> <?php endforeach; ?></div>
    <div class="col text-end">
        <button type="button" class="btn btn-outline-dark" onclick="window.print()"><i class="bi bi-printer"></i> Imprimir</button>
        <a class="btn btn-outline-success" href="<?= e(url('kardex', 'csv', $filtro)) ?>"><i class="bi bi-filetype-csv"></i> CSV</a>
        <?php if ($puedeAjustar): ?><button  type="button" class="btn btn-outline-primary" data-bs-toggle="modal" data-bs-target="#modalAjuste"><i class="bi bi-sliders"></i> Ajuste</button>
        <button type="button" class="btn btn-outline-primary" data-bs-toggle="modal" data-bs-target="#modalToma"><i class="bi bi-clipboard-check"></i> Toma física</button><?php endif; ?>
        <?php if ($puedeCargar): ?><button type="button" class="btn btn-gold" data-bs-toggle="modal" data-bs-target="#modalCarga"><i class="bi bi-upload"></i> Carga inicial CSV</button><?php endif; ?>
    </div>
</form>
<div class="card"><div class="table-responsive"><table class="table table-sm table-hover align-middle mb-0" style="font-size:.85rem">
    <thead class="table-light"><tr><th rowspan="2">Fecha</th><th rowspan="2">Producto</th><th rowspan="2">Tipo</th><th colspan="2" class="text-center">Entradas</th><th colspan="2" class="text-center">Salidas</th>
        <th colspan="4" class="text-center">Saldos</th></tr>    
        <tr><th class="text-end">Cant.</th><th class="text-end">Valor</th><th class="text-end">Cant.</th><th class="text-end">Valor</th><th class="text-end">Piezas</th><th class="text-end">Peso</th><th class="text-end">Monto</th><th class="text-end">CPP</th></tr></thead>
    <tbody>
    <?php foreach ($filas as $k): $es = in_array($k['tipo'], $ent, true); ?>
        <tr><td><?= e($k['fecha']) ?></td><td><code><?= e($k['codigo']) ?></code> <?= e($k['nombre']) ?><?php if ($k['motivo']): ?><br><small class="text-muted"><?= e($k['motivo']) ?></small><?php endif; ?></td>
            <td><span class="badge <?= $es ? 'bg-success' : 'bg-danger' ?>"><?= e($k['tipo']) ?></span></td>
            <td class="text-end"><?= $es ? (int)$k['cantidad'] : '' ?></td><td class="text-end"><?= $es ? money($k['valor_total']) : '' ?></td>
            <td class="text-end"><?= !$es ? (int)$k['cantidad'] : '' ?></td><td class="text-end"><?= !$es ? money($k['valor_total']) : '' ?></td>
            <td class="text-end fw-semibold"><?= (int)$k['saldo_cantidad'] ?></td><td class="text-end"><?= e(fmt_peso($k['saldo_peso'], $k['unidad_peso'])) ?></td>
            <td class="text-end"><?= money($k['saldo_monetario']) ?></td><td class="text-end"><?= MONEDA . number_format((float)$k['cpp'], 4) ?></td></tr>
    <?php endforeach; if (!$filas): ?><tr><td colspan="11" class="text-center text-muted py-4">Sin movimientos en el periodo.</td></tr><?php endif; ?>
    </tbody></table></div></div>

<?php if ($puedeAjustar): ?>
<div class="modal fade" id="modalAjuste" tabindex="-1"><div class="modal-dialog"><form class="modal-content" id="formAjuste" novalidate>
    <div class="modal-header"><h5 class="modal-title">Ajuste de inventario</h5><button type="button" class="btn-close" data-bs-dismiss="modal"></button></div>
    <div class="modal-body">
      <div class="mb-2"><label class="form-label">Producto *</label><select name="id_producto" class="form-select" required><?php foreach ($productos as $p): ?><option value="<?= $p['id_producto'] ?>"><?= e($p['codigo'] . ' – ' . $p['nombre'] . ' (stock ' . $p['stock'] . ')') ?></option><?php endforeach; ?></select></div>
      <div class="row g-2 mb-2"><div class="col-6"><label class="form-label">Tipo</label><select name="tipo" class="form-select"><option value="ENTRADA">Entrada (sobrante)</option><option value="SALIDA">Salida (merma / faltante)</option></select></div>
        <div class="col-6"><label class="form-label">Piezas *</label><input type="number" name="cantidad" min="1" step="1" class="form-control" required></div></div>
      <div class="mb-2"><label class="form-label">Motivo * (mín. 5 caracteres)</label><input name="motivo" class="form-control" minlength="5" maxlength="255" required></div>
      <small class="text-muted">Se valora automáticamente al costo promedio (CPP) vigente.</small></div>
    <div class="modal-footer"><button class="btn btn-gold">Registrar ajuste</button></div></form></div></div>

<div class="modal fade" id="modalToma" tabindex="-1"><div class="modal-dialog modal-lg"><div class="modal-content">
    <div class="modal-header"><h5 class="modal-title">Toma física</h5><button type="button" class="btn-close" data-bs-dismiss="modal"></button></div>
    <div class="modal-body" style="max-height:60vh;overflow:auto"><p class="text-muted small">Escribe las piezas contadas. Los vacíos se ignoran; las diferencias generan ajustes al CPP.</p>
      <table class="table table-sm"><thead><tr><th>Producto</th><th class="text-end">Sistema</th><th style="width:120px">Contado</th></tr></thead><tbody>
      <?php foreach ($productos as $p): ?><tr><td><code><?= e($p['codigo']) ?></code> <?= e($p['nombre']) ?></td><td class="text-end"><?= (int)$p['stock'] ?></td><td><input type="number" min="0" step="1" class="form-control form-control-sm" data-conteo="<?= $p['id_producto'] ?>"></td></tr><?php endforeach; ?></tbody></table></div>
    <div class="modal-footer"><button class="btn btn-gold" id="btnToma">Aplicar toma física</button></div></div></div></div>
<?php endif; ?>
<?php if ($puedeCargar): ?>
<div class="modal fade" id="modalCarga" tabindex="-1"><div class="modal-dialog"><div class="modal-content">
    <div class="modal-header"><h5 class="modal-title">Carga inicial por CSV (SALDO_INICIAL)</h5><button type="button" class="btn-close" data-bs-dismiss="modal"></button></div>
    <div class="modal-body"><p class="small text-muted">Columnas: <code>codigo,cantidad,costo</code> (costo por pieza). Los productos deben existir y no tener movimientos. Si una fila falla, no se carga nada.</p>
      <input type="file" id="archivoCsv" accept=".csv,text/csv,text/plain" class="form-control"></div>
    <div class="modal-footer"><button class="btn btn-gold" id="btnCarga">Cargar</button></div></div></div></div>
<?php endif; ?>
