<?php /* Variables: $archivos, $cuenta */
$tam = fn(int $b) => $b >= 1048576 ? number_format($b / 1048576, 1) . ' MB' : number_format(max(1, $b / 1024), 0) . ' KB'; ?>
<div class="d-flex justify-content-between flex-wrap gap-2 mb-3 align-items-center">
    <p class="text-muted mb-0">Copia completa de la base de datos en un archivo <code>.sql</code>. Solo la Dueña entra aquí.</p>
    <button class="btn btn-gold" id="btn-respaldo"><i class="bi bi-hdd-network"></i> Generar y descargar respaldo</button>
</div>
<div class="alert alert-light border small">
    <b>Cuenta usada:</b> <?= e($cuenta) ?>.
    Los respaldos se guardan en <code>backups/</code> (fuera de git y bloqueada para el navegador) y además se descargan a tu equipo.
    Contienen datos sensibles: guarda una copia <b>fuera de la tienda</b>. No incluyen triggers ni <code>config/app.key</code>
    (respáldala aparte); para restaurar sigue <code>docs/INSTALACION.md</code>. Conviene borrar de vez en cuando los respaldos viejos desde el administrador de archivos.
</div>
<div class="card"><div class="table-responsive"><table class="table table-hover align-middle mb-0">
    <thead class="table-light"><tr><th>Archivo</th><th>Fecha</th><th class="text-end">Tamaño</th><th>Estado</th><th class="text-end">Descargar</th></tr></thead>
    <tbody>
    <?php if (!$archivos): ?><tr><td colspan="5" class="text-center text-muted py-4">Todavía no hay respaldos.</td></tr><?php endif; ?>
    <?php foreach ($archivos as $a): ?>
        <tr>
            <td><code><?= e($a['archivo']) ?></code></td>
            <td><?= e(date('d/m/Y H:i', $a['fecha'])) ?></td>
            <td class="text-end"><?= e($tam($a['bytes'])) ?></td>
            <td><?= $a['completo'] ? '<span class="badge bg-success">Completo</span>' : '<span class="badge bg-danger" title="El archivo no termina con la marca de fin">Incompleto</span>' ?></td>
            <td class="text-end"><a class="btn btn-sm btn-outline-dark" href="<?= e(url('respaldo', 'descargar', ['f' => $a['archivo']])) ?>"><i class="bi bi-download"></i></a></td>
        </tr>
    <?php endforeach; ?>
    </tbody></table></div></div>
