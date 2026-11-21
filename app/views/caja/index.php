<?php $scripts = ['caja.js']; $r = $resumen; $m = fn($c) => money(de_centavos((int)$c)); ?>
<?php if (!$caja): ?>
<div class="card" style="max-width:520px"><div class="card-body">
    <h5><i class="bi bi-unlock"></i> Abrir caja</h5>
    <p class="text-muted">No hay una caja abierta. Cuenta el efectivo inicial para empezar a cobrar.</p>
    <div class="input-group mb-3"><span class="input-group-text"><?= MONEDA ?></span><input id="montoInicial" type="number" step="0.01" min="0" class="form-control form-control-lg" placeholder="Monto inicial"></div>
    <button id="btnAbrir" class="btn btn-gold btn-lg w-100">Abrir caja</button>
</div></div>
<?php else: ?>
<div class="row g-3">
  <div class="col-lg-5"><div class="card"><div class="card-header fw-semibold">Caja #<?= (int)$caja['id_caja'] ?> · abierta <?= e($caja['fecha_apertura']) ?></div>
    <table class="table mb-0">
        <tr><td>Monto inicial</td><td class="text-end"><?= $m($r['inicial']) ?></td></tr>
        <tr><td>+ Ventas activas</td><td class="text-end"><?= $m($r['ventas']) ?></td></tr>
        <tr><td>+ Ingresos</td><td class="text-end"><?= $m($r['ingresos']) ?></td></tr>
        <tr><td>− Retiros</td><td class="text-end"><?= $m($r['retiros']) ?></td></tr>
        <tr><td>− Gastos</td><td class="text-end"><?= $m($r['gastos']) ?></td></tr>
        <tr><td>− Devoluciones</td><td class="text-end"><?= $m($r['devoluciones']) ?></td></tr>
        <tr class="table-warning fw-bold fs-5"><td>Efectivo esperado</td><td class="text-end"><?= $m($r['esperado']) ?></td></tr>
    </table>
    <div class="card-body"><button class="btn btn-dark w-100" id="btnCerrar" data-esperado="<?= e(de_centavos($r['esperado'])) ?>"><i class="bi bi-lock"></i> Cierre y arqueo</button></div></div></div>
  <div class="col-lg-7"><div class="card"><div class="card-header fw-semibold">Movimientos de efectivo</div><div class="card-body">
      <form id="formMov" class="row g-2 mb-3">
        <div class="col-md-3"><select name="tipo" class="form-select"><option>INGRESO</option><option>RETIRO</option><option>GASTO</option><option>DEVOLUCION</option></select></div>
        <div class="col-md-3"><input name="monto" type="number" step="0.01" min="0.01" class="form-control" placeholder="Monto" required></div>
        <div class="col-md-4"><input name="motivo" class="form-control" placeholder="Motivo (mín. 3)" minlength="3" maxlength="255" required></div>
        <div class="col-md-2"><button class="btn btn-gold w-100">Registrar</button></div>
      </form>
      <table class="table table-sm"><thead><tr><th>Hora</th><th>Tipo</th><th>Motivo</th><th class="text-end">Monto</th></tr></thead><tbody>
      <?php foreach ($movimientos as $x): ?><tr><td><?= e(substr($x['fecha'], 11, 5)) ?></td><td><?= e($x['tipo']) ?></td><td><?= e($x['motivo']) ?></td><td class="text-end"><?= money($x['monto']) ?></td></tr><?php endforeach; ?>
      <?php if (!$movimientos): ?><tr><td colspan="4" class="text-muted text-center">Sin movimientos.</td></tr><?php endif; ?></tbody></table>
  </div></div></div>
</div>
<?php endif; ?>
<div class="card mt-3"><div class="card-header fw-semibold">Últimas cajas</div><div class="table-responsive"><table class="table table-sm mb-0">
    <thead><tr><th>N.º</th><th>Apertura</th><th>Cierre</th><th class="text-end">Inicial</th><th class="text-end">Esperado</th><th class="text-end">Contado</th><th class="text-end">Diferencia</th><th>Estado</th></tr></thead><tbody>
    <?php foreach ($historial as $c): ?><tr><td>#<?= $c['id_caja'] ?></td><td><?= e($c['fecha_apertura']) ?></td><td><?= e($c['fecha_cierre']) ?></td><td class="text-end"><?= money($c['monto_inicial']) ?></td>
        <td class="text-end"><?= $c['efectivo_esperado'] !== null ? money($c['efectivo_esperado']) : '—' ?></td><td class="text-end"><?= $c['efectivo_contado'] !== null ? money($c['efectivo_contado']) : '—' ?></td>
        <td class="text-end <?= (float)$c['diferencia'] < 0 ? 'text-danger' : '' ?>"><?= $c['diferencia'] !== null ? money($c['diferencia']) : '—' ?></td><td><?= e($c['estado']) ?></td></tr><?php endforeach; ?>
</tbody></table></div></div>
