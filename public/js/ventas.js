/* ===== POS: búsqueda, carrito, cobro en efectivo, offline PWA ===== */
const carrito = new Map(); // id -> {id, nombre, codigo, peso, unidad, precio, stock, cantidad}
const $ = id => document.getElementById(id);
let temporizador, ultimosItems = [], cobrando = false;
const nuevoUuid = () => (crypto.randomUUID ? crypto.randomUUID() : 'xxxxxxxx-xxxx-4xxx-yxxx-xxxxxxxxxxxx'.replace(/[xy]/g, c => { const r = Math.random() * 16 | 0; return (c === 'x' ? r : (r & 3 | 8)).toString(16); }));
let uuidOperacion = nuevoUuid();   // 1 uuid por venta: si el cobro se reintenta, el servidor no duplica
const cents = n => Math.round(n * 100);
const peso3 = (p, u) => Number(p).toFixed(3) + (u === 'kilates' ? ' kt' : ' g');
function esc(s) { const d = document.createElement('div'); d.textContent = s ?? ''; return d.innerHTML; }
const hayOffline = () => typeof Offline !== 'undefined';   // public/js/offline.js (Bloque 2)
/** Sin internet O servidor inalcanzable (wifi sin salida, hosting caído): en ambos casos se vende en modo local. */
const sinRed = () => hayOffline() ? Offline.sinConexion() : !navigator.onLine;
function marcarOffline() { $('avisoOffline')?.classList.toggle('d-none', !sinRed()); calcular(); }
document.addEventListener('onix:estado', marcarOffline);
addEventListener('online', marcarOffline); addEventListener('offline', marcarOffline);
document.addEventListener('onix:sincronizado', () => buscar());   // el stock cambió

async function buscar() {
    const q = $('buscador').value.trim(), cat = $('categoria').value;
    let items = null;
    if (!sinRed()) {
        const r = await api('venta', 'buscar', null, `&q=${encodeURIComponent(q)}&cat=${cat}`, {sinRedirigir: true});
        if (r.ok) items = r.items;   // si falla (sin red, sesión vencida…) cae al catálogo local
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

/** Comprobante provisional (no fiscal) para ventas guardadas sin conexión. */
async function comprobanteLocal(e, efectivo, total, sesionVencida) {
    const vuelto = Math.max(0, cents(efectivo) - cents(total)) / 100;
    const res = await Swal.fire({
        icon: 'warning', title: 'Venta guardada localmente',
        html: `<b>Pendiente de sincronizar</b> · Comprobante <b>${esc(e.ticket)}</b><br>Total: <b>${fmt(total)}</b> · Vuelto: <b>${fmt(vuelto)}</b><br>`
            + `<span class="small text-muted">${sesionVencida ? 'Tu sesión venció: inicia sesión de nuevo y se subirá sola. ' : 'Se subirá sola al volver la conexión. '}`
            + 'El número de ticket oficial se asigna al sincronizar.</span>',
        showCancelButton: true, confirmButtonText: '<i class="bi bi-printer"></i> Imprimir comprobante provisional',
        cancelButtonText: 'Cerrar', confirmButtonColor: '#b8913a', allowOutsideClick: false});
    if (res.isConfirmed) imprimirComprobanteLocal(e, efectivo, total, vuelto);
}

function imprimirComprobanteLocal(e, efectivo, total, vuelto) {
    const filas = (e.detalle || []).map(i => `<tr><td>${esc(i.nombre)}</td><td align="right">${i.cantidad} × ${fmt(i.precio)}</td><td align="right">${fmt(i.cantidad * i.precio)}</td></tr>`).join('');
    const f = document.createElement('iframe');
    f.style.cssText = 'position:fixed;width:0;height:0;border:0;visibility:hidden';
    f.srcdoc = `<!DOCTYPE html><meta charset="utf-8"><style>body{font:12px monospace;width:72mm;margin:0 auto}td{padding:1px 0}h3,p{text-align:center;margin:2px}</style>`
        + `<h3>${esc(APP.negocio || '')}</h3><p><b>COMPROBANTE PROVISIONAL · NO FISCAL</b></p><p>${esc(e.ticket)} · ${esc(new Date(e.creado).toLocaleString('es-SV', {timeZone: 'America/El_Salvador'}))}</p>`
        + `<table width="100%">${filas}</table><hr><p>Total ${fmt(total)}<br>Efectivo ${fmt(efectivo)}<br>Vuelto ${fmt(vuelto)}</p>`
        + `<p>Venta pendiente de sincronizar.<br>El ticket oficial se emite al subirla.</p><script>onload=()=>print()<\/script>`;
    document.body.appendChild(f);
    setTimeout(() => f.remove(), 60000);
}

async function cobrar() {
    if (cobrando) return;
    cobrando = true; $('btnCobrar').disabled = true;
    const payload = {uuid_operacion: uuidOperacion, efectivo: cents(parseFloat($('efectivo').value) || 0) / 100,
                     items: [...carrito.values()].map(i => ({id: i.id, cantidad: i.cantidad}))};
    const detalle = [...carrito.values()].map(i => ({id: i.id, codigo: i.codigo, nombre: i.nombre, cantidad: i.cantidad, precio: i.precio}));
    const total = totalCents() / 100, ahora = new Date().toISOString();
    try {
        let r = null;
        if (!sinRed()) {
            r = await api('venta', 'cobrar', payload, '', {sinRedirigir: true});
            if (r.http === 419 && hayOffline()) { await Offline.refrescarSesion(); r = await api('venta', 'cobrar', payload, '', {sinRedirigir: true}); }   // token viejo: se renueva y se reintenta una vez
        }
        // Se guarda localmente si no hubo servidor (red caída, timeout, 5xx, respuesta rara) o la sesión venció.
        // Es seguro: se enviará con el MISMO uuid; si el cobro sí llegó al servidor, la sincronización lo detecta como duplicada.
        const alCola = r === null || r.sinConexion || r.invalida || r.http === 401 || r.http >= 500;
        if (alCola) {
            if (!hayOffline()) { aviso(false, 'Sin conexión y el modo offline no está disponible en este navegador.'); return; }
            let e;
            try { e = await Offline.guardarVentaPendiente({...payload, fecha: ahora, total, detalle}); }
            catch (err) { aviso(false, 'No se pudo guardar la venta en este equipo (' + (err.message || err) + '). NO entregues las piezas; la venta no quedó registrada.'); return; }
            await comprobanteLocal(e, payload.efectivo, total, r && r.http === 401);
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
