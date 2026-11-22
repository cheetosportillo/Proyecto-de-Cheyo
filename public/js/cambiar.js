/* Cambio de contraseña (obligatorio o voluntario) vía api() + CSRF. */
(() => {
    const f = document.getElementById('frm-cambiar');
    if (!f) return;
    f.addEventListener('submit', async ev => {
        ev.preventDefault();
        if (f.dataset.enviando === '1') return;
        if (!f.checkValidity()) { f.reportValidity(); return; }
        f.dataset.enviando = '1';
        const b = f.querySelector('[type=submit]'); b.disabled = true;
        const r = await api('auth', 'cambiarPassword', Object.fromEntries(new FormData(f).entries()));
        if (!r.ok) { f.dataset.enviando = ''; b.disabled = false; }
        aviso(r.ok, r.msg, () => { location.href = r.ir || f.dataset.inicio; });
    });
})();
