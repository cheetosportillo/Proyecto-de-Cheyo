<?php /* Variables: $f, $filas, $total, $pagina, $paginas, $acciones, $usuarios */
$qs = array_filter($f, fn($v) => $v !== '');
$link = fn(int $p) => e(url('bitacora', 'index', $qs + ['p' => $p])); ?>
<form class="card card-body mb-3" method="get" action="<?= e(base_url() . '/index.php') ?>">
    <input type="hidden" name="c" value="bitacora"><input type="hidden" name="a" value="index">
    <div class="row g-2 align-items-end">
        <div class="col-6 col-md-2"><label class="form-label small mb-0">Desde</label><input type="date" name="desde" class="form-control" value="<?= e($f['desde']) ?>"></div>
        <div class="col-6 col-md-2"><label class="form-label small mb-0">Hasta</label><input type="date" name="hasta" class="form-control" value="<?= e($f['hasta']) ?>"></div>
        <div class="col-md-3"><label class="form-label small mb-0">Usuario</label>
            <select name="usuario" class="form-select"><option value="">Todos</option>
                <option value="sistema" <?= $f['usuario'] === 'sistema' ? 'selected' : '' ?>>(sistema / sin usuario)</option>
                <?php foreach ($usuarios as $u): ?><option value="<?= (int)$u['id_usuario'] ?>" <?= $f['usuario'] === (string)$u['id_usuario'] ? 'selected' : '' ?>><?= e($u['nombre']) ?> (<?= e($u['usuario']) ?>)</option><?php endforeach; ?>
            </select></div>
        <div class="col-md-3"><label class="form-label small mb-0">Acción</label>
            <select name="accion" class="form-select"><option value="">Todas</option>
                <?php foreach ($acciones as $a): ?><option value="<?= e($a) ?>" <?= $f['accion'] === $a ? 'selected' : '' ?>><?= e($a) ?></option><?php endforeach; ?>
            </select></div>
        <div class="col-md-2 d-flex gap-1"><button class="btn btn-gold flex-fill">Filtrar</button>
            <a class="btn btn-outline-secondary" href="<?= e(url('bitacora')) ?>" title="Quitar filtros"><i class="bi bi-x-lg"></i></a></div>
    </div>
</form>
<div class="d-flex justify-content-between align-items-center mb-2 flex-wrap gap-2">
    <small class="text-muted"><?= number_format($total) ?> registro(s) · solo lectura: la bitácora no se puede editar ni borrar.</small>
    <a class="btn btn-sm btn-outline-dark" href="<?= e(url('bitacora', 'exportar', $qs)) ?>" title="Hasta <?= number_format(Bitacora::MAX_EXPORTAR) ?> filas, las más recientes"><i class="bi bi-filetype-csv"></i> Exportar CSV</a>
</div>
<div class="card"><div class="table-responsive"><table class="table table-sm table-hover align-middle mb-0">
    <thead class="table-light"><tr><th>#</th><th>Fecha</th><th>Usuario</th><th>Acción</th><th>Detalle</th></tr></thead>
    <tbody>
    <?php if (!$filas): ?><tr><td colspan="5" class="text-center text-muted py-4">Sin registros con esos filtros.</td></tr><?php endif; ?>
    <?php foreach ($filas as $r): ?>
        <tr><td class="text-muted"><?= (int)$r['id_bitacora'] ?></td>
            <td class="text-nowrap"><?= e(date('d/m/Y H:i:s', strtotime($r['fecha']))) ?></td>
            <td><?= $r['id_usuario'] === null ? '<span class="text-muted">(sistema)</span>' : e($r['u_nombre'] ?? ('#' . $r['id_usuario'])) ?></td>
            <td><span class="badge bg-secondary"><?= e($r['accion']) ?></span></td>
            <td class="small"><?= e($r['detalle'] ?? '') ?></td></tr>
    <?php endforeach; ?>
    </tbody></table></div></div>
<?php if ($paginas > 1): ?>
<nav class="mt-3"><ul class="pagination pagination-sm justify-content-center mb-0">
    <li class="page-item <?= $pagina <= 1 ? 'disabled' : '' ?>"><a class="page-link" href="<?= $link(max(1, $pagina - 1)) ?>">«</a></li>
    <?php for ($i = max(1, $pagina - 3); $i <= min($paginas, $pagina + 3); $i++): ?>
        <li class="page-item <?= $i === $pagina ? 'active' : '' ?>"><a class="page-link" href="<?= $link($i) ?>"><?= $i ?></a></li>
    <?php endfor; ?>
    <li class="page-item <?= $pagina >= $paginas ? 'disabled' : '' ?>"><a class="page-link" href="<?= $link(min($paginas, $pagina + 1)) ?>">»</a></li>
</ul><div class="text-center small text-muted mt-1">Página <?= $pagina ?> de <?= number_format($paginas) ?></div></nav>
<?php endif; ?>
