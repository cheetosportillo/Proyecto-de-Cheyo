/* ===== Caja única ===== */
document.getElementById('btnAbrir')?.addEventListener('click', async e => {
    e.target.disabled = true;
    const r = await api('caja', 'abrir', {monto_inicial: document.getElementById('montoInicial').value});
    aviso(r.ok, r.msg, () => location.reload()); e.target.disabled = false;
});
document.getElementById('formMov')?.addEventListener('submit', async e => {
    e.preventDefault();
    const r = await api('caja', 'movimiento', Object.fromEntries(new FormData(e.target).entries()));
    aviso(r.ok, r.msg, () => location.reload());
});
document.getElementById('btnCerrar')?.addEventListener('click', async e => {
    const res = await Swal.fire({title: 'Cierre y arqueo', html: `Efectivo esperado: <b>${fmt(e.currentTarget.dataset.esperado)}</b><br>Cuenta el efectivo y escríbelo:`,
        input: 'number', inputAttributes: {step: '0.01', min: '0'}, showCancelButton: true, confirmButtonText: 'Cerrar caja', confirmButtonColor: '#b8913a',
        inputValidator: v => v === '' ? 'Ingresa el efectivo contado.' : null});
    if (!res.isConfirmed) return;
    const r = await api('caja', 'cerrar', {efectivo_contado: res.value});
    aviso(r.ok, r.msg, () => location.reload());
});
