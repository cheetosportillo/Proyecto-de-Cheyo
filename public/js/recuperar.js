/* Recuperación híbrida: método 1 (correo) y método 2 (llave maestra offline). Todo por api() + CSRF. */
(() => {
    const $ = id => document.getElementById(id);
    function enviar(form, accion, alExito) {
        form.addEventListener('submit', async ev => {
            ev.preventDefault();
            if (form.dataset.enviando === '1') return;
            if (!form.checkValidity()) { form.reportValidity(); return; }
            form.dataset.enviando = '1';
            const b = form.querySelector('[type=submit]'); b.disabled = true;
            const r = await api('auth', accion, Object.fromEntries(new FormData(form).entries()));
            form.dataset.enviando = ''; b.disabled = false;
            if (r.ok) alExito(r); else aviso(false, r.msg);
        });
    }

    const pedir = $('frm-pedir'), codigo = $('frm-codigo'), llave = $('frm-llave');

    enviar(pedir, 'recuperarCorreo', r => {
        codigo.elements.usuario.value = pedir.elements.usuario.value.trim();
        codigo.classList.remove('d-none');
        codigo.elements.codigo.focus();
        aviso(true, r.msg);
    });
    enviar(codigo, 'restablecerCorreo', r => aviso(true, r.msg, () => { location.href = r.login; }));
    enviar(llave, 'restablecerLlave', r => {
        $('vista-form').classList.add('d-none');
        $('llave-nueva').textContent = r.llave;      // se muestra una sola vez; no se guarda en el navegador
        $('vista-llave').classList.remove('d-none');
        llave.reset();
    });
})();
