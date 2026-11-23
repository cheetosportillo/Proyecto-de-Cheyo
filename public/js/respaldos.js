/* Respaldos: genera por api() + CSRF y dispara la descarga sin salir de la página. */
(() => {
    const btn = document.getElementById('btn-respaldo');
    btn.addEventListener('click', async () => {
        if (btn.disabled) return;
        btn.disabled = true;
        const html = btn.innerHTML;
        btn.innerHTML = '<span class="spinner-border spinner-border-sm"></span> Generando…';
        const r = await api('respaldo', 'generar', {});
        if (!r.ok) { btn.disabled = false; btn.innerHTML = html; return aviso(false, r.msg); }
        const a = document.createElement('a');
        a.href = r.descarga;
        a.rel = 'noopener';
        document.body.appendChild(a);
        a.click();
        a.remove();
        aviso(true, r.msg, () => location.reload());
    });
})();
