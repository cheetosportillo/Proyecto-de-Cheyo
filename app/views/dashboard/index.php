<?php $esDuena = Permisos::tiene('dashboard.ver'); ?>
<?php if ($alertas): ?>
<div class="alert alert-warning d-flex align-items-start">
    <i class="bi bi-exclamation-triangle-fill fs-4 me-3"></i>
    <div><strong>Alerta de stock mínimo</strong>
        <ul class="mb-0">
            <?php foreach ($alertas as $a): ?>
                <li><?= e($a['nombre']) ?>: <strong><?= (int)$a['piezas'] ?></strong> piezas / <strong><?= e(fmt_peso($a['peso_total'], $a['unidad_peso'])) ?></strong>
                    (mínimos: <?= (int)$a['stock_minimo'] ?> pzs · <?= e(fmt_peso($a['peso_minimo'], $a['unidad_peso'])) ?>) — <?= e($a['alerta_motivo']) ?></li>
            <?php endforeach; ?>
        </ul>
    </div>
</div>
<?php endif; ?>

<div class="row g-3 mb-4">
    <?php
    $cards = [
        ['Ventas de hoy', money($resumen['ventas_hoy']), $resumen['num_hoy'] . ' venta(s)', 'bi-cash-stack'],
        ['Ventas de la semana', money($resumen['ventas_semana']), $resumen['num_semana'] . ' venta(s)', 'bi-calendar-week'],
        ['Ventas del mes', money($resumen['ventas_mes']), 'Efectivo ingresado', 'bi-calendar-check'],
        ['Productos activos', $resumen['productos'], 'En inventario', 'bi-gem'],
    ];
    if ($esDuena) $cards[] = ['Inversión en stock', money($resumen['invertido']), 'Saldo del Kardex (CPP)', 'bi-safe'];
    foreach ($cards as [$t, $v, $s, $i]): ?>
    <div class="col-6 col-xl-3">
        <div class="card stat-card h-100"><div class="card-body">
            <div class="d-flex justify-content-between">
                <div><div class="text-muted small"><?= $t ?></div><div class="fs-3 fw-semibold"><?= e($v) ?></div><div class="small text-muted"><?= e($s) ?></div></div>
                <i class="bi <?= $i ?> fs-1 text-gold"></i>
            </div>
        </div></div>
    </div>
    <?php endforeach; ?>
</div>

<div class="row g-3">
    <div class="col-lg-7">
        <div class="card h-100"><div class="card-header fw-semibold">Valoración del inventario por categoría</div>
        <div class="table-responsive"><table class="table table-sm align-middle mb-0">
            <thead><tr><th>Categoría</th><th class="text-end">Piezas</th><th class="text-end">Gramos</th><th class="text-end">Kilates</th>
                <?php if ($esDuena): ?><th class="text-end">Invertido</th><?php endif; ?>
                <th class="text-end">Valor de venta</th></tr></thead>
            <tbody>
            <?php foreach ($val as $c): ?>
                <tr><td><?= e($c['nombre']) ?><?= $c['alerta'] ? ' <i class="bi bi-exclamation-triangle-fill text-warning"></i>' : '' ?></td><td class="text-end"><?= (int)$c['piezas'] ?></td>
                    <td class="text-end"><?= $c['unidad_peso'] === 'gramos' ? number_format($c['peso_gramos'], 3) . ' g' : '—' ?></td>
                    <td class="text-end"><?= $c['unidad_peso'] === 'kilates' ? number_format($c['peso_kilates'], 3) . ' kt' : '—' ?></td>
                    <?php if ($esDuena): ?><td class="text-end"><?= money($c['invertido']) ?></td><?php endif; ?>
                    <td class="text-end"><?= money($c['valor_venta']) ?></td></tr>
            <?php endforeach; ?>
            </tbody></table></div></div>
    </div>
    <div class="col-lg-5">
        <div class="card h-100"><div class="card-header fw-semibold">Últimas ventas</div>
        <ul class="list-group list-group-flush">
            <?php foreach ($ultimas as $v): ?>
                <li class="list-group-item d-flex justify-content-between">
                    <span>#<?= str_pad($v['numero_ticket'], 6, '0', STR_PAD_LEFT) ?> <small class="text-muted"><?= e($v['fecha']) ?></small></span>
                    <strong><?= money($v['total']) ?></strong></li>
            <?php endforeach; if (!$ultimas): ?><li class="list-group-item text-muted">Aún no hay ventas.</li><?php endif; ?>
        </ul></div>
    </div>
</div>

<div class="card mt-3"><div class="card-header fw-semibold">Reporte de ventas del periodo</div><div class="card-body">
    <form class="row g-2 mb-3" method="get"><input type="hidden" name="c" value="dashboard">
        <div class="col-auto"><input type="date" name="desde" value="<?= e($periodo['desde']) ?>" class="form-control"></div>
        <div class="col-auto"><input type="date" name="hasta" value="<?= e($periodo['hasta']) ?>" class="form-control"></div>
        <div class="col-auto"><button class="btn btn-dark"><i class="bi bi-funnel"></i> Filtrar</button></div>
        <div class="col-auto">
            <?php foreach ($rapidos as $t => [$d, $h]): ?><a class="btn btn-outline-secondary" href="<?= e(url('dashboard', 'index', ['desde' => $d, 'hasta' => $h])) ?>"><?= $t ?></a> <?php endforeach; ?>
            <a class="btn btn-outline-success" href="<?= e(url('dashboard', 'csv', ['desde' => $periodo['desde'], 'hasta' => $periodo['hasta']])) ?>"><i class="bi bi-filetype-csv"></i> Exportar CSV</a></div>
    </form>
    <table class="table table-sm mb-0"><thead><tr><th>Día</th><th class="text-end">Ventas</th><th class="text-end">Total</th></tr></thead><tbody>
    <?php foreach ($periodo['dias'] as $d): ?><tr><td><?= e($d['dia']) ?></td><td class="text-end"><?= (int)$d['num'] ?></td><td class="text-end"><?= money($d['total']) ?></td></tr><?php endforeach; ?>
    <?php if (!$periodo['dias']): ?><tr><td colspan="3" class="text-muted text-center">Sin ventas en el periodo.</td></tr><?php endif; ?>
    </tbody><tfoot><tr class="fw-bold"><td>Total</td><td class="text-end"><?= (int)$periodo['total']['num'] ?></td><td class="text-end"><?= money($periodo['total']['total']) ?></td></tr></tfoot></table>
</div></div>
