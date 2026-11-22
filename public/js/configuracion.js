/* Configuración: datos de la joyería y SMTP (api() + CSRF). */
(() => {
    function ligar(id, accion) {
        const f = document.getElementById(id);
        f.addEventListener('submit', async ev => {
            ev.preventDefault();
            if (f.dataset.enviando === '1') return;
            if (!f.checkValidity()) { f.reportValidity(); return; }
            f.dataset.enviando = '1';
            const b = f.querySelector('[type=submit]'); b.disabled = true;
            const r = await api('configuracion', accion, Object.fromEntries(new FormData(f).entries()));
            f.dataset.enviando = ''; b.disabled = false;
            aviso(r.ok, r.msg, () => location.reload());
        });
    }
    ligar('frm-negocio', 'guardarNegocio');
    ligar('frm-smtp', 'guardarSmtp');

    const btn = document.getElementById('btn-probar'), f = document.getElementById('frm-smtp');
    btn.addEventListener('click', async () => {
        if (btn.disabled) return;
        btn.disabled = true;
        const r = await api('configuracion', 'probarSmtp', Object.fromEntries(new FormData(f).entries()));
        btn.disabled = false;
        aviso(r.ok, r.msg);
    });
})();
