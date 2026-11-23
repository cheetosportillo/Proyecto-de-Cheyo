/* Aviso de sesión por expirar (pensado para el POS). NO acorta la sesión: solo avisa 2 minutos antes de que venza la
 * inactividad y deja «Seguir trabajando», que renueva por api() sin recargar ni tocar lo que hay en pantalla.
 * El aviso es un recuadro propio (no un modal): no interfiere con un cobro o un SweetAlert que esté abierto. */
(() => {
    if (typeof SESION_SEG === 'undefined' || typeof api !== 'function') return;
    const AVISO_MS = 120000;
    let vence = Date.now() + SESION_SEG * 1000;   // cada petición autenticada renueva la sesión en el servidor
    let caja = null, confirmando = false, expirada = false;

    function fmt(ms) {
        const s = Math.max(0, Math.ceil(ms / 1000));
        return Math.floor(s / 60) + ':' + String(s % 60).padStart(2, '0');
    }

    function mostrar() {
        if (caja) return;
        caja = document.createElement('div');
        caja.className = 'alert alert-warning shadow border-warning';
        caja.setAttribute('role', 'alert');
        caja.setAttribute('aria-live', 'assertive');
        caja.style.cssText = 'position:fixed;right:1rem;bottom:1rem;z-index:2000;max-width:22rem';
        caja.innerHTML = '<div class="fw-semibold"><i class="bi bi-hourglass-split"></i> Tu sesión está por expirar</div>' +
            '<div class="small my-1">Por inactividad se cerrará en <b id="ses-cuenta"></b>. Lo que tienes en pantalla no se pierde si sigues trabajando.</div>' +
            '<button type="button" class="btn btn-sm btn-dark" id="ses-seguir">Seguir trabajando</button>';
        document.body.appendChild(caja);
        caja.querySelector('#ses-seguir').addEventListener('click', seguir);
    }

    function ocultar() { if (caja) { caja.remove(); caja = null; } }

    function vencida() {
        expirada = true;
        mostrar();
        caja.className = 'alert alert-danger shadow border-danger';
        caja.innerHTML = '<div class="fw-semibold"><i class="bi bi-lock"></i> La sesión venció</div>' +
            '<div class="small my-1">Para no perder lo que ves en pantalla, inicia sesión en otra pestaña y luego vuelve a intentar.</div>' +
            '<a class="btn btn-sm btn-dark" target="_blank" rel="noopener" href="' + BASE_URL + '?c=auth&a=login">Iniciar sesión</a>';
    }

    async function seguir() {
        const b = caja && caja.querySelector('#ses-seguir');
        if (b) b.disabled = true;
        const r = await api('auth', 'renovar', {});
        if (r.ok) { expirada = false; vence = Date.now() + SESION_SEG * 1000; ocultar(); }
        else if (r.expirada) vencida();
        else if (b) { b.disabled = false; }   // sin conexión: se puede reintentar
    }

    /** Pregunta al servidor el tiempo real (otra pestaña pudo haber renovado) sin renovar nada. */
    async function confirmar() {
        if (confirmando) return;
        confirmando = true;
        const r = await api('auth', 'estadoSesion');
        confirmando = false;
        if (r.sinConexion) return;           // sin red: se sigue con el cálculo local
        if (r.activa === false) return vencida();
        if (r.ok && typeof r.restante === 'number') vence = Date.now() + r.restante * 1000;
    }

    document.addEventListener('onix:actividad', () => {
        if (expirada) return;
        vence = Date.now() + SESION_SEG * 1000;
        ocultar();
    });

    let preguntado = false;
    setInterval(async () => {
        if (expirada) return;
        const falta = vence - Date.now();
        if (falta > AVISO_MS) { preguntado = false; ocultar(); return; }
        if (!preguntado) { preguntado = true; await confirmar(); return; }
        if (vence - Date.now() > AVISO_MS) { preguntado = false; ocultar(); return; }   // otra pestaña renovó
        if (vence - Date.now() <= 0) { await confirmar(); if (!expirada && vence - Date.now() <= 0) vencida(); return; }   // última confirmación con el servidor
        mostrar();
        const c = caja && caja.querySelector('#ses-cuenta');
        if (c) c.textContent = fmt(vence - Date.now());
    }, 1000);
})();
