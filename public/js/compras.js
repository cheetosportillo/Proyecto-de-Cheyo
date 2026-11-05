/* ===== Nueva compra a proveedor ===== */
const lineas = []; // {id, codigo, nombre, cantidad, costo}
const $c = id => document.getElementById(id);
const esc = s => { const d = document.createElement('div'); d.textContent = s ?? ''; return d.innerHTML; };

function pintarLineas() {
    $c('lineas').innerHTML = lineas.length ? lineas.map((l, i) => `
        <tr><td>${esc(l.codigo)} – ${esc(l.nombre)}</td>
            <td style="width:100px"><input type="number" min="1" class="form-control form-control-sm" value="${l.cantidad}" data-f="cantidad" data-i="${i}"></td>
            <td style="width:120px"><input type="number" min="0" step="0.01" class="form-control form-control-sm" value="${l.costo}" data-f="costo" data-i="${i}"></td>
            <td class="text-end">${fmt(l.cantidad * l.costo)}</td>
            <td><button type="button" class="btn btn-sm btn-outline-danger" data-x="${i}"><i class="bi bi-x-lg"></i></button></td></tr>`).join('')
        : '<tr><td colspan="5" class="text-center text-muted">Agrega productos a la compra</td></tr>';
    $c('lineas').querySelectorAll('[data-f]').forEach(inp => inp.addEventListener('change', () => {
        lineas[inp.dataset.i][inp.dataset.f] = parseFloat(inp.value) || 0; pintarLineas();
    }));
    $c('lineas').querySelectorAll('[data-x]').forEach(b => b.addEventListener('click', () => { lineas.splice(b.dataset.x, 1); pintarLineas(); }));
    $c('totalCompra').textContent = fmt(lineas.reduce((s, l) => s + l.cantidad * l.costo, 0));
}

$c('btnAgregarLinea').addEventListener('click', () => {
    const sel = $c('selProducto');
    const p = PRODUCTOS.find(x => x.id_producto == sel.value);
    if (!p) return;
    const ex = lineas.find(l => l.id == p.id_producto);
    if (ex) ex.cantidad++;
    else lineas.push({id: +p.id_producto, codigo: p.codigo, nombre: p.nombre, cantidad: 1, costo: +p.costo});
    pintarLineas();
});

$c('btnGuardarCompra').addEventListener('click', async () => {
    const r = await api('compra', 'guardar', {
        id_proveedor: $c('selProveedor').value, observacion: $c('observacion').value,
        items: lineas.map(l => ({id: l.id, cantidad: l.cantidad, costo: l.costo}))
    });
    aviso(r.ok, r.msg, () => location.reload());
});

document.querySelectorAll('[data-detalle]').forEach(b => b.addEventListener('click', async () => {
    const r = await api('compra', 'detalle', null, '&id=' + b.dataset.detalle);
    if (!r.ok) return aviso(false, r.msg);
    $c('detalleBody').innerHTML = r.items.map(i => `<tr><td>${esc(i.codigo)}</td><td>${esc(i.nombre)}</td>
        <td class="text-end">${i.cantidad}</td><td class="text-end">${fmt(i.costo_unitario)}</td><td class="text-end">${fmt(i.subtotal)}</td></tr>`).join('');
    bootstrap.Modal.getOrCreateInstance($c('modalDetalle')).show();
}));
pintarLineas();
