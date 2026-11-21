/* ===== Kardex: ajuste, toma física y carga inicial CSV ===== */
document.getElementById('formAjuste')?.addEventListener('submit', async e => {
    e.preventDefault();
    if (!e.target.checkValidity()) return e.target.reportValidity();
    const d = Object.fromEntries(new FormData(e.target).entries());
    const r = await api('kardex', 'ajustar', d);
    aviso(r.ok, r.msg, () => location.reload());
});
document.getElementById('btnToma')?.addEventListener('click', async () => {
    const conteos = [...document.querySelectorAll('[data-conteo]')].filter(i => i.value !== '').map(i => ({id: +i.dataset.conteo, contado: +i.value}));
    const r = await api('kardex', 'tomaFisica', {conteos});
    aviso(r.ok, r.msg, () => location.reload());
});
document.getElementById('btnCarga')?.addEventListener('click', async () => {
    const f = document.getElementById('archivoCsv').files[0];
    if (!f) return aviso(false, 'Elige un archivo CSV.');
    const r = await api('kardex', 'cargaCsv', {csv: await f.text()});
    aviso(r.ok, r.msg, () => location.reload());
});
