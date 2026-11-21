/* ===== Utilidades comunes ===== */
const CSRF = document.querySelector('meta[name="csrf-token"]')?.content || '';
const fmt = n => MONEDA + Number(n).toLocaleString('en-US', {minimumFractionDigits: 2, maximumFractionDigits: 2});

const API_TIMEOUT_MS = 30000;
let _sesionExpirada = false;

/** ERR-06: sesión vencida/desactivada -> al login (una sola vez, aunque fallen varias peticiones a la vez). */
function sesionExpirada() {
    if (_sesionExpirada) return;
    _sesionExpirada = true;
    location.href = `${BASE_URL}?c=auth&a=login`;
}

/**
 * Llamada al servidor. NUNCA lanza excepción (N1): si cae la red o el servidor tarda demasiado
 * devuelve {ok:false, sinConexion:true, msg}, así ningún botón queda bloqueado para siempre.
 * Devuelve siempre {ok, msg, ...} y, cuando hay respuesta HTTP, `http` con el código de estado.
 */
async function api(c, a, datos = null, query = '') {
    const opt = datos === null ? {} : {
        method: 'POST',
        headers: {'Content-Type': 'application/json', 'X-CSRF-TOKEN': CSRF},
        body: JSON.stringify(datos)
    };
    const ctl = new AbortController();
    const timer = setTimeout(() => ctl.abort(), API_TIMEOUT_MS);
    let r;
    try {
        r = await fetch(`${BASE_URL}?c=${c}&a=${a}${query}`, {
            ...opt, signal: ctl.signal, credentials: 'same-origin',
            headers: {Accept: 'application/json', ...(opt.headers || {})}
        });
    } catch (err) {
        const agotado = ctl.signal.aborted;
        return {ok: false, sinConexion: true, msg: agotado
            ? 'El servidor tardó demasiado en responder. Revisa si la operación quedó registrada antes de repetirla.'
            : 'Sin conexión con el servidor. Verifica la red e inténtalo de nuevo.'};
    } finally {
        clearTimeout(timer);
    }
    if (r.status === 401) { sesionExpirada(); return {ok: false, http: 401, msg: 'Tu sesión expiró. Inicia sesión de nuevo.'}; }
    try {
        const j = await r.json();
        if (j && typeof j === 'object') j.http = r.status;
        return j;
    } catch {
        return {ok: false, http: r.status, msg: 'Respuesta inválida del servidor.'};
    }
}

function aviso(ok, msg, despues) {
    if (typeof Swal === 'undefined') {   // librería local ausente (public/vendor): no dejar la app muda
        alert(msg);
        if (ok && despues) despues();
        return;
    }
    Swal.fire({icon: ok ? 'success' : 'error', title: ok ? 'Listo' : 'Atención', text: msg,
               confirmButtonColor: '#b8913a', timer: ok ? 1600 : undefined}).then(() => ok && despues && despues());
}

/* ===== Formularios genéricos: <form data-c="producto" data-a="guardar"> ===== */
document.querySelectorAll('form[data-c]').forEach(f => {
    f.addEventListener('submit', async e => {
        e.preventDefault();
        if (f.dataset.enviando === '1') return;          // ERR-04: evita doble envío
        if (!f.checkValidity()) { f.reportValidity(); return; }
        const botones = f.querySelectorAll('[type=submit]');
        f.dataset.enviando = '1';
        botones.forEach(b => b.disabled = true);
        const datos = Object.fromEntries(new FormData(f).entries());
        const r = await api(f.dataset.c, f.dataset.a, datos);
        if (!r.ok) {                                      // si falló, se puede corregir y reintentar
            f.dataset.enviando = '';
            botones.forEach(b => b.disabled = false);
        }                                                 // si fue bien, queda bloqueado hasta recargar
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
    if (b.disabled) return;
    b.disabled = true;                                    // ERR-04: sin doble clic
    const datos = JSON.parse(b.dataset.datos);
    const r = await api(b.dataset.c, b.dataset.accion, datos);
    if (!r.ok) b.disabled = false;
    aviso(r.ok, r.msg, () => location.reload());
}));
