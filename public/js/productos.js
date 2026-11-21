/* ===== Inventario: ganancia en vivo, generar código y unidad según categoría ===== */
(() => {
    const modal = document.getElementById('modalProducto');
    if (!modal) return;
    const f = modal.querySelector('form'), $g = document.getElementById('ganancia');

    function ganancia() {
        const costo = parseFloat(f.elements.costo.value) || 0, precio = parseFloat(f.elements.precio_venta.value) || 0;
        if (!precio) { $g.textContent = '—'; $g.className = 'form-control-plaintext fw-semibold'; return; }
        if (!costo) { $g.textContent = 'Aún sin costo: se fija con la primera compra.'; $g.className = 'form-control-plaintext text-muted'; return; }
        const g = precio - costo, pct = g / costo * 100;
        $g.textContent = `${fmt(g)} (${pct.toFixed(1)}%)` + (precio <= costo ? ' ⚠ El precio no cubre el costo' : '');
        $g.className = 'form-control-plaintext fw-semibold ' + (precio <= costo ? 'text-danger' : 'text-success');
    }
    function unidad() {
        const o = f.elements.id_categoria.selectedOptions[0];
        document.getElementById('lblUnidad').textContent = o && o.dataset.unidad ? `(${o.dataset.unidad === 'kilates' ? 'kilates' : 'gramos'})` : '';
    }
    f.elements.precio_venta.addEventListener('input', ganancia);
    f.elements.id_categoria.addEventListener('change', unidad);
    modal.addEventListener('shown.bs.modal', () => { ganancia(); unidad(); });
    document.querySelectorAll('[data-nuevo="#modalProducto"]').forEach(b => b.addEventListener('click', () => setTimeout(() => { ganancia(); unidad(); }, 0)));

    document.getElementById('btnGenCodigo').addEventListener('click', async () => {
        const cat = f.elements.id_categoria.value;
        if (!cat) return aviso(false, 'Primero elige la categoría.');
        const r = await api('producto', 'codigo', null, '&cat=' + cat);
        if (r.ok) f.elements.codigo.value = r.codigo; else aviso(false, r.msg);
    });
})();
