<?php $esDuena = $_SESSION['usuario']['rol'] === 'duena'; ?>
<?php if ($alertas): ?>
<div class="alert alert-warning d-flex align-items-start">
    <i class="bi bi-exclamation-triangle-fill fs-4 me-3"></i>
    <div><strong>Alerta de stock mínimo</strong>
        <ul class="mb-0">
            <?php foreach ($alertas as $a): ?>
                <li><?= e($a['nombre']) ?>: quedan <strong><?= (int)$a['piezas'] ?></strong> piezas (mínimo configurado: <?= (int)$a['stock_minimo'] ?>)</li>
            <?php endforeach; ?>
        </ul>
    </div>
</div>
<?php endif; ?>

<div class="row g-3 mb-4">
    <?php
    $cards = [
        ['Ventas de hoy', money($resumen['ventas_hoy']), $resumen['num_hoy'] . ' venta(s)', 'bi-cash-stack'],
        ['Ventas del mes', money($resumen['ventas_mes']), 'Efectivo ingresado', 'bi-calendar-check'],
        ['Productos activos', $resumen['productos'], 'En inventario', 'bi-gem'],
    ];
    if ($esDuena) $cards[] = ['Inversión en stock', money($resumen['invertido']), 'A costo de compra', 'bi-safe'];
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
            <thead><tr><th>Categoría</th><th class="text-end">Piezas</th><th class="text-end">Peso total</th>
                <?php if ($esDuena): ?><th class="text-end">Invertido</th><?php endif; ?>
                <th class="text-end">Valor de venta</th></tr></thead>
            <tbody>
            <?php foreach ($val as $c): ?>
                <tr><td><?= e($c['nombre']) ?></td><td class="text-end"><?= (int)$c['piezas'] ?></td>
                    <td class="text-end"><?= number_format($c['peso_total'], 2) ?></td>
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
                    <span>#<?= str_pad($v['id_venta'], 6, '0', STR_PAD_LEFT) ?> <small class="text-muted"><?= e($v['fecha']) ?></small></span>
                    <strong><?= money($v['total']) ?></strong></li>
            <?php endforeach; if (!$ultimas): ?><li class="list-group-item text-muted">Aún no hay ventas.</li><?php endif; ?>
        </ul></div>
    </div>
</div>
