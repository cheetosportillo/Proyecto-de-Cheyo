/* ===== POS: búsqueda, carrito, cobro en efectivo, offline PWA ===== */
const carrito = new Map(); // id -> {id, nombre, codigo, peso, unidad, precio, stock, cantidad}
const $ = id => document.getElementById(id);
let temporizador, ultimosItems = [], cobrando = false;
const nuevoUuid = () => (crypto.randomUUID ? crypto.randomUUID() : 'xxxxxxxx-xxxx-4xxx-yxxx-xxxxxxxxxxxx'.replace(/[xy]/g, c => { const r = Math.random() * 16 | 0; return (c === 'x' ? r : (r & 3 | 8)).toString(16); }));
let uuidOperacion = nuevoUuid();   // 1 uuid por venta: si el cobro se reintenta, el servidor no duplica
const cents = n => Math.round(n * 100);
const peso3 = (p, u) => Number(p).toFixed(3) + (u === 'kilates' ? ' kt' : ' g');
function esc(s) { const d = document.createElement('div'); d.textContent = s ?? ''; return d.innerHTML; }
const hayOffline = () => typeof Offline !== 'undefined';   // módulo de la Parte 1 (Offline.js)
const sinRed = () => !navigator.onLine;
function marcarOffline() { $('avisoOffline')?.classList.toggle('d-none', navigator.onLine); }
addEventListener('online', marcarOffline); addEventListener('offline', marcarOffline);

async function buscar() {
    const q = $('buscador').value.trim(), cat = $('categoria').value;
    let items = null;
    if (!sinRed()) {
        try {
            const r = await api('venta', 'buscar', null, `&q=${encodeURIComponent(q)}&cat=${cat}`);
            if (r.ok) { items = r.items; if (hayOffline() && !q && cat == 0) Offline.guardarCatalogo(items); }
        } catch (e) { /* cae a catálogo local */ }
    }
    if (items === null && hayOffline()) items = await Offline.buscarCatalogo(q, cat);
    ultimosItems = items || [];
    const cont = $('resultados');
    if (!ultimosItems.length) { cont.innerHTML = '<div class="text-muted p-3">No se encontraron productos con stock.</div>'; return; }
    cont.innerHTML = ultimosItems.map(p => `
        <button type="button" class="list-group-item list-group-item-action d-flex justify-content-between align-items-center">
            <div><div class="fw-semibold">${esc(p.nombre)}</div>
                <small class="text-muted">${esc(p.codigo)} · ${esc(p.categoria)} · ${peso3(p.peso, p.unidad_peso)} · Stock: ${p.stock}</small></div>
            <span class="badge bg-dark fs-6">${fmt(p.precio_venta)}</span></button>`).join('');
    cont.querySelectorAll('button').forEach((btn, i) => btn.addEventListener('click', () => agregar(ultimosItems[i])));
}

function agregar(p) {
    const ex = carrito.get(+p.id_producto);
    if (ex) { if (ex.cantidad < ex.stock) ex.cantidad++; else return aviso(false, 'No hay más stock de esta pieza.'); }
    else carrito.set(+p.id_producto, {id: +p.id_producto, nombre: p.nombre, codigo: p.codigo, peso: p.peso,
        unidad: p.unidad_peso, precio: +p.precio_venta, stock: +p.stock, cantidad: 1});
    pintar();
}

function pintar() {
    const tb = $('carrito');
    if (!carrito.size) tb.innerHTML = '<tr><td colspan="5" class="text-center text-muted py-4">Carrito vacío</td></tr>';
    else tb.innerHTML = [...carrito.values()].map(i => `
        <tr><td><div class="fw-semibold small">${esc(i.nombre)}</div><small class="text-muted">${peso3(i.peso, i.unidad)}</small></td>
            <td style="width:90px"><input type="number" min="1" max="${i.stock}" value="${i.cantidad}" class="form-control form-control-sm" data-q="${i.id}"></td>
            <td class="text-end">${fmt(i.precio)}</td><td class="text-end fw-semibold">${fmt(i.precio * i.cantidad)}</td>
            <td><button class="btn btn-sm btn-outline-danger" data-x="${i.id}"><i class="bi bi-x-lg"></i></button></td></tr>`).join('');
    tb.querySelectorAll('[data-q]').forEach(inp => inp.addEventListener('change', () => {
        const it = carrito.get(+inp.dataset.q);
        it.cantidad = Math.max(1, Math.min(parseInt(inp.value) || 1, it.stock)); pintar();
    }));
    tb.querySelectorAll('[data-x]').forEach(b => b.addEventListener('click', () => { carrito.delete(+b.dataset.x); pintar(); }));
    calcular();
}

const totalCents = () => [...carrito.values()].reduce((s, i) => s + cents(i.precio) * i.cantidad, 0);

function calcular() {
    const t = totalCents(), ef = cents(parseFloat($('efectivo').value) || 0);
    $('total').textContent = fmt(t / 100);
    $('cambio').textContent = fmt(ef > t ? (ef - t) / 100 : 0);
    $('cambio').className = 'fs-4 fw-bold ' + (ef >= t && t > 0 ? 'text-success' : 'text-muted');
    $('btnCobrar').disabled = cobrando || !(carrito.size && ef >= t) || !(APP.cajaAbierta || sinRed());
}

function imprimirTicket(url) {   // iframe oculto: el ticket se imprime solo al cargar, sin abrir pestañas
    const f = document.createElement('iframe');
    f.style.cssText = 'position:fixed;width:0;height:0;border:0;visibility:hidden';
    f.src = url; document.body.appendChild(f);
    setTimeout(() => f.remove(), 60000);
}

async function cobrar() {
    if (cobrando) return;
    cobrando = true; $('btnCobrar').disabled = true;
    const payload = {uuid_operacion: uuidOperacion, efectivo: cents(parseFloat($('efectivo').value) || 0) / 100,
                     items: [...carrito.values()].map(i => ({id: i.id, cantidad: i.cantidad}))};
    try {
        let r = null;
        if (!sinRed()) { try { r = await api('venta', 'cobrar', payload); } catch (e) { r = null; } }
        if (r === null) {   // sin internet: guardar pendiente (se sincroniza con el MISMO uuid, sin duplicar)
            if (!hayOffline()) { aviso(false, 'Sin conexión y el modo offline no está disponible.'); return; }
            const temp = await Offline.guardarVentaPendiente({...payload, fecha: new Date().toISOString(), total: totalCents() / 100});
            await Swal.fire({icon: 'info', title: 'Venta guardada sin conexión', html: `Ticket <b>${esc(temp?.ticket || 'TEMP-' + uuidOperacion.slice(0, 6).toUpperCase())}</b><br>Se sincronizará al volver internet.`, confirmButtonColor: '#b8913a'});
        } else {
            if (!r.ok) { aviso(false, r.msg); return; }
            const res = await Swal.fire({icon: 'success', title: 'Venta registrada · Ticket #' + String(r.numero_ticket).padStart(6, '0'),
                html: `Total: <b>${fmt(r.total)}</b><br>Vuelto a entregar: <b>${fmt(r.cambio)}</b>`, showCancelButton: true,
                confirmButtonText: '<i class="bi bi-printer"></i> Imprimir ticket', cancelButtonText: 'Cerrar', confirmButtonColor: '#b8913a', allowOutsideClick: false});
            if (res.isConfirmed) imprimirTicket(r.ticket);
        }
        carrito.clear(); $('efectivo').value = ''; uuidOperacion = nuevoUuid(); pintar(); buscar(); $('buscador').focus();
    } finally { cobrando = false; calcular(); }
}

/* ---------- Eventos ---------- */
$('buscador').addEventListener('input', () => { clearTimeout(temporizador); temporizador = setTimeout(buscar, 250); });
$('buscador').addEventListener('keydown', async e => {   // Enter = lector de código de barras
    if (e.key !== 'Enter') return;
    e.preventDefault(); clearTimeout(temporizador);
    await buscar();
    const q = $('buscador').value.trim().toUpperCase();
    const exacto = ultimosItems.find(p => p.codigo.toUpperCase() === q) || (ultimosItems.length === 1 ? ultimosItems[0] : null);
    if (exacto) { agregar(exacto); $('buscador').value = ''; buscar(); }
});
$('categoria').addEventListener('change', buscar);
$('efectivo').addEventListener('input', calcular);
$('efectivo').addEventListener('keydown', e => { if (e.key === 'Enter' && !$('btnCobrar').disabled) { e.preventDefault(); cobrar(); } });
$('btnCobrar').addEventListener('click', cobrar);
$('btnVaciar').addEventListener('click', () => { carrito.clear(); pintar(); });
$('btnExacto').addEventListener('click', () => { $('efectivo').value = (totalCents() / 100).toFixed(2); calcular(); $('btnCobrar').focus(); });
$('btnLimpiarEf').addEventListener('click', () => { $('efectivo').value = ''; calcular(); });
document.querySelectorAll('[data-billete]').forEach(b => b.addEventListener('click', () => {
    $('efectivo').value = ((cents(parseFloat($('efectivo').value) || 0) + cents(+b.dataset.billete)) / 100).toFixed(2); calcular();
}));

/* ---------- Historial: modal «Ver detalle», reimprimir y anular ---------- */
let ventaActual = null;
document.querySelectorAll('[data-ver]').forEach(b => b.addEventListener('click', async () => {
    const r = await api('venta', 'detalle', null, '&id=' + b.dataset.ver);
    if (!r.ok) return aviso(false, r.msg);
    ventaActual = r; const v = r.venta;
    $('vTitulo').textContent = 'Ticket #' + String(v.numero_ticket).padStart(6, '0') + (v.estado === 'ANULADA' ? ' (ANULADA)' : '');
    $('vInfo').innerHTML = `${esc(v.fecha)} · Atendió: ${esc(v.cajero)} · Total ${fmt(v.total)} · Efectivo ${fmt(v.efectivo)} · Vuelto ${fmt(v.cambio)}`
        + (v.estado === 'ANULADA' ? `<br><span class="text-danger">Motivo de anulación: ${esc(v.motivo_anulacion)}</span>` : '');
    $('vBody').innerHTML = r.items.map(i => `<tr><td>${esc(i.codigo)}</td><td>${esc(i.nombre)}</td><td class="text-end">${i.cantidad}</td>
        <td class="text-end">${peso3(i.peso, i.unidad_peso)}</td><td class="text-end">${fmt(i.precio_unitario)}</td><td class="text-end">${fmt(i.subtotal)}</td></tr>`).join('');
    $('vAnular').classList.toggle('d-none', !(APP.puedeAnular && v.estado === 'ACTIVA'));
    $('vImprimir').classList.toggle('d-none', !APP.puedeReimprimir);
    bootstrap.Modal.getOrCreateInstance($('modalVenta')).show();
}));
$('vImprimir').addEventListener('click', () => ventaActual && imprimirTicket(ventaActual.ticket));
$('vAnular').addEventListener('click', async () => {
    const res = await Swal.fire({title: 'Anular venta', input: 'text', inputLabel: 'Motivo (mínimo 5 caracteres)', showCancelButton: true,
        confirmButtonText: 'Anular', confirmButtonColor: '#b8913a', inputValidator: v => (v || '').trim().length < 5 ? 'Escribe al menos 5 caracteres.' : null});
    if (!res.isConfirmed) return;
    const r = await api('venta', 'anular', {id_venta: +ventaActual.venta.id_venta, motivo: res.value});
    aviso(r.ok, r.msg, () => location.reload());
});

marcarOffline(); pintar(); buscar();
