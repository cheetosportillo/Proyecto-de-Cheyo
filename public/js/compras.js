/* ===== Nueva compra a proveedor ===== */
const lineas = []; // {id, codigo, nombre, cantidad, costo}
const $c = id => document.getElementById(id);
const esc = s => { const d = document.createElement('div'); d.textContent = s ?? ''; return d.innerHTML; };
const nuevoUuid = () => (crypto.randomUUID ? crypto.randomUUID() : 'xxxxxxxx-xxxx-4xxx-yxxx-xxxxxxxxxxxx'.replace(/[xy]/g, c => { const r = Math.random() * 16 | 0; return (c === 'x' ? r : (r & 3 | 8)).toString(16); }));
let uuidOperacion = nuevoUuid();   // se conserva mientras la compra no se confirme: reintentos = misma operación

function pintarLineas() {
    $c('lineas').innerHTML = lineas.length ? lineas.map((l, i) => `
        <tr><td>${esc(l.codigo)} – ${esc(l.nombre)}</td>
            <td style="width:100px"><input type="number" min="1" step="1" class="form-control form-control-sm" value="${l.cantidad}" data-f="cantidad" data-i="${i}"></td>
            <td style="width:120px"><input type="number" min="0" step="0.01" class="form-control form-control-sm" value="${l.costo}" data-f="costo" data-i="${i}"></td>
            <td class="text-end">${fmt(l.cantidad * l.costo)}</td>
            <td><button type="button" class="btn btn-sm btn-outline-danger" data-x="${i}"><i class="bi bi-x-lg"></i></button></td></tr>`).join('')
        : '<tr><td colspan="5" class="text-center text-muted">Agrega productos a la compra</td></tr>';
    $c('lineas').querySelectorAll('[data-f]').forEach(inp => inp.addEventListener('change', () => {
        const l = lineas[inp.dataset.i];
        l[inp.dataset.f] = inp.dataset.f === 'cantidad' ? Math.max(1, parseInt(inp.value) || 1) : Math.max(0, parseFloat(inp.value) || 0);
        pintarLineas();
    }));
    $c('lineas').querySelectorAll('[data-x]').forEach(b => b.addEventListener('click', () => { lineas.splice(b.dataset.x, 1); pintarLineas(); }));
    $c('totalCompra').textContent = fmt(lineas.reduce((s, l) => s + Math.round(l.cantidad * l.costo * 100) / 100, 0));
}

// Buscador: filtra el <select> por código o nombre
$c('buscaProducto').addEventListener('input', () => {
    const q = $c('buscaProducto').value.trim().toLowerCase(), sel = $c('selProducto');
    let primero = null;
    [...sel.options].forEach(o => { const ok = !q || o.textContent.toLowerCase().includes(q); o.hidden = !ok; if (ok && !primero) primero = o; });
    if (primero) sel.value = primero.value;
});
$c('buscaProducto').addEventListener('keydown', e => { if (e.key === 'Enter') { e.preventDefault(); $c('btnAgregarLinea').click(); } });

$c('btnAgregarLinea').addEventListener('click', () => {
    const p = PRODUCTOS.find(x => x.id_producto == $c('selProducto').value);
    if (!p) return;
    const ex = lineas.find(l => l.id == p.id_producto);
    if (ex) ex.cantidad = Math.max(1, ex.cantidad + 1);
    else lineas.push({id: +p.id_producto, codigo: p.codigo, nombre: p.nombre, cantidad: 1, costo: +p.costo || 0});
    pintarLineas();
});

$c('btnGuardarCompra').addEventListener('click', async () => {
    const btn = $c('btnGuardarCompra');
    if (btn.disabled) return;
    btn.disabled = true;                                   // evita doble clic
    try {
        const r = await api('compra', 'guardar', {
            uuid_operacion: uuidOperacion, id_proveedor: $c('selProveedor').value, observacion: $c('observacion').value,
            numero_factura: $c('numeroFactura').value.trim(),
            items: lineas.map(l => ({id: l.id, cantidad: Math.max(1, parseInt(l.cantidad) || 1), costo: l.costo}))
        });
        if (r.ok) uuidOperacion = nuevoUuid();
        aviso(r.ok, r.msg, () => location.reload());
    } finally { btn.disabled = false; }
});

document.querySelectorAll('[data-detalle]').forEach(b => b.addEventListener('click', async () => {
    const r = await api('compra', 'detalle', null, '&id=' + b.dataset.detalle);
    if (!r.ok) return aviso(false, r.msg);
    $c('detalleBody').innerHTML = r.items.map(i => `<tr><td>${esc(i.codigo)}</td><td>${esc(i.nombre)}</td>
        <td class="text-end">${i.cantidad}</td><td class="text-end">${fmt(i.costo_unitario)}</td><td class="text-end">${fmt(i.subtotal)}</td></tr>`).join('');
    bootstrap.Modal.getOrCreateInstance($c('modalDetalle')).show();
}));

document.querySelectorAll('[data-anular]').forEach(b => b.addEventListener('click', async () => {
    const res = await Swal.fire({title: 'Anular compra #' + b.dataset.anular, input: 'text', inputLabel: 'Motivo (mínimo 5 caracteres)',
        showCancelButton: true, confirmButtonText: 'Anular', confirmButtonColor: '#b8913a',
        inputValidator: v => (v || '').trim().length < 5 ? 'Escribe al menos 5 caracteres.' : null});
    if (!res.isConfirmed) return;
    const r = await api('compra', 'anular', {id_compra: +b.dataset.anular, motivo: res.value});
    aviso(r.ok, r.msg, () => location.reload());
}));
pintarLineas();
