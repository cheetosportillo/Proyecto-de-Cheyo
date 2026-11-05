/* ===== Utilidades comunes ===== */
const CSRF = document.querySelector('meta[name="csrf-token"]')?.content || '';
const fmt = n => MONEDA + Number(n).toLocaleString('en-US', {minimumFractionDigits: 2, maximumFractionDigits: 2});

async function api(c, a, datos = null, query = '') {
    const opt = datos === null ? {} : {
        method: 'POST',
        headers: {'Content-Type': 'application/json', 'X-CSRF-TOKEN': CSRF},
        body: JSON.stringify(datos)
    };
    const r = await fetch(`${BASE_URL}?c=${c}&a=${a}${query}`, {...opt, headers: {Accept: 'application/json', ...(opt.headers || {})}});
    try { return await r.json(); } catch { return {ok: false, msg: 'Respuesta inválida del servidor.'}; }
}

function aviso(ok, msg, despues) {
    Swal.fire({icon: ok ? 'success' : 'error', title: ok ? 'Listo' : 'Atención', text: msg,
               confirmButtonColor: '#b8913a', timer: ok ? 1600 : undefined}).then(() => ok && despues && despues());
}

/* ===== Formularios genéricos: <form data-c="producto" data-a="guardar"> ===== */
document.querySelectorAll('form[data-c]').forEach(f => {
    f.addEventListener('submit', async e => {
        e.preventDefault();
        if (!f.checkValidity()) { f.reportValidity(); return; }
        const datos = Object.fromEntries(new FormData(f).entries());
        const r = await api(f.dataset.c, f.dataset.a, datos);
        aviso(r.ok, r.msg, () => location.reload());
    });
});

/* ===== Botón "nuevo": limpia el modal ===== */
document.querySelectorAll('[data-nuevo]').forEach(b => b.addEventListener('click', () => {
    const m = document.querySelector(b.dataset.nuevo);
    m.querySelector('form').reset();
    m.querySelectorAll('input[type=hidden]:not([name=csrf])').forEach(h => h.value = '');
    m.querySelector('.modal-title').textContent = m.dataset.tituloNuevo || 'Nuevo';
}));

/* ===== Botón "editar": llena el modal con data-json ===== */
document.querySelectorAll('[data-editar]').forEach(b => b.addEventListener('click', () => {
    const m = document.querySelector(b.dataset.editar);
    const f = m.querySelector('form');
    f.reset();
    const d = JSON.parse(b.dataset.json);
    Object.entries(d).forEach(([k, v]) => { if (f.elements[k]) f.elements[k].value = v ?? ''; });
    m.querySelector('.modal-title').textContent = m.dataset.tituloEditar || 'Editar';
    bootstrap.Modal.getOrCreateInstance(m).show();
}));

/* ===== Botón "accion": confirmar y enviar (eliminar, activar/desactivar) ===== */
document.querySelectorAll('[data-accion]').forEach(b => b.addEventListener('click', async () => {
    const ok = await Swal.fire({icon: 'question', title: '¿Confirmas?', text: b.dataset.confirm,
        showCancelButton: true, confirmButtonText: 'Sí, continuar', cancelButtonText: 'Cancelar', confirmButtonColor: '#b8913a'});
    if (!ok.isConfirmed) return;
    const datos = JSON.parse(b.dataset.datos);
    const r = await api(b.dataset.c, b.dataset.accion, datos);
    aviso(r.ok, r.msg, () => location.reload());
}));
