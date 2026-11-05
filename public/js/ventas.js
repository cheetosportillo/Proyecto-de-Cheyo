/* ===== Caja: búsqueda, carrito y cobro en efectivo ===== */
const carrito = new Map(); // id -> {id, nombre, codigo, peso, unidad, precio, stock, cantidad}
const $ = id => document.getElementById(id);
let temporizador;

async function buscar() {
    const q = encodeURIComponent($('buscador').value.trim());
    const cat = $('categoria').value;
    const r = await api('venta', 'buscar', null, `&q=${q}&cat=${cat}`);
    const cont = $('resultados');
    if (!r.ok || !r.items.length) { cont.innerHTML = '<div class="text-muted p-3">No se encontraron productos con stock.</div>'; return; }
    cont.innerHTML = r.items.map(p => `
        <button type="button" class="list-group-item list-group-item-action d-flex justify-content-between align-items-center" data-id="${p.id_producto}">
            <div><div class="fw-semibold">${esc(p.nombre)}</div>
                <small class="text-muted">${esc(p.codigo)} · ${esc(p.categoria)} · ${Number(p.peso).toFixed(2)} ${p.unidad_peso === 'kilates' ? 'kt' : 'g'} · Stock: ${p.stock}</small></div>
            <span class="badge bg-dark fs-6">${fmt(p.precio_venta)}</span>
        </button>`).join('');
    cont.querySelectorAll('button').forEach((btn, i) => btn.addEventListener('click', () => agregar(r.items[i])));
}

function esc(s) { const d = document.createElement('div'); d.textContent = s ?? ''; return d.innerHTML; }

function agregar(p) {
    const ex = carrito.get(p.id_producto);
    if (ex) { if (ex.cantidad < ex.stock) ex.cantidad++; else return aviso(false, 'No hay más stock de esta pieza.'); }
    else carrito.set(p.id_producto, {id: p.id_producto, nombre: p.nombre, codigo: p.codigo, peso: p.peso,
        unidad: p.unidad_peso, precio: +p.precio_venta, stock: +p.stock, cantidad: 1});
    pintar();
}

function pintar() {
    const tb = $('carrito');
    if (!carrito.size) tb.innerHTML = '<tr><td colspan="5" class="text-center text-muted py-4">Carrito vacío</td></tr>';
    else tb.innerHTML = [...carrito.values()].map(i => `
        <tr><td><div class="fw-semibold small">${esc(i.nombre)}</div><small class="text-muted">${Number(i.peso).toFixed(2)} ${i.unidad === 'kilates' ? 'kt' : 'g'}</small></td>
            <td style="width:90px"><input type="number" min="1" max="${i.stock}" value="${i.cantidad}" class="form-control form-control-sm" data-q="${i.id}"></td>
            <td class="text-end">${fmt(i.precio)}</td><td class="text-end fw-semibold">${fmt(i.precio * i.cantidad)}</td>
            <td><button class="btn btn-sm btn-outline-danger" data-x="${i.id}"><i class="bi bi-x-lg"></i></button></td></tr>`).join('');
    tb.querySelectorAll('[data-q]').forEach(inp => inp.addEventListener('change', () => {
        const it = carrito.get(+inp.dataset.q);
        let v = parseInt(inp.value) || 1; v = Math.max(1, Math.min(v, it.stock));
        it.cantidad = v; pintar();
    }));
    tb.querySelectorAll('[data-x]').forEach(b => b.addEventListener('click', () => { carrito.delete(+b.dataset.x); pintar(); }));
    calcular();
}

const total = () => [...carrito.values()].reduce((s, i) => s + Math.round(i.precio * i.cantidad * 100) / 100, 0);

function calcular() {
    const t = total(), ef = parseFloat($('efectivo').value) || 0;
    $('total').textContent = fmt(t);
    const cambio = ef - t;
    $('cambio').textContent = fmt(cambio > 0 ? cambio : 0);
    $('cambio').className = 'fs-4 fw-bold ' + (ef >= t && t > 0 ? 'text-success' : 'text-muted');
    $('btnCobrar').disabled = !(carrito.size && ef + 0.00001 >= t);
}

async function cobrar() {
    $('btnCobrar').disabled = true;
    const r = await api('venta', 'cobrar', {
        efectivo: parseFloat($('efectivo').value) || 0,
        items: [...carrito.values()].map(i => ({id: i.id, cantidad: i.cantidad}))
    });
    if (!r.ok) { aviso(false, r.msg); calcular(); return; }
    const res = await Swal.fire({icon: 'success', title: 'Venta registrada',
        html: `Total: <b>${fmt(r.total)}</b><br>Vuelto a entregar: <b>${fmt(r.cambio)}</b>`,
        showCancelButton: true, confirmButtonText: '<i class="bi bi-printer"></i> Imprimir ticket',
        cancelButtonText: 'Cerrar', confirmButtonColor: '#b8913a', allowOutsideClick: false});
    if (res.isConfirmed) window.open(r.ticket, '_blank');
    carrito.clear(); $('efectivo').value = ''; pintar(); buscar();
}

$('buscador').addEventListener('input', () => { clearTimeout(temporizador); temporizador = setTimeout(buscar, 250); });
$('categoria').addEventListener('change', buscar);
$('efectivo').addEventListener('input', calcular);
$('btnCobrar').addEventListener('click', cobrar);
$('btnVaciar').addEventListener('click', () => { carrito.clear(); pintar(); });
pintar(); buscar();
