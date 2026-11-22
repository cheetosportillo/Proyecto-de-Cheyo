/* Usuarios y roles (Bloque 1.3). Las claves temporales / llaves se muestran UNA vez y no se guardan en el navegador. */
(() => {
    const esc = s => String(s ?? '').replace(/[&<>"']/g, c => ({'&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;'}[c]));
    const caja = (t, v) => `<div class="text-start mb-2"><div class="small text-muted">${t}</div>
        <div style="font-family:Consolas,'Courier New',monospace;font-size:1.3rem;letter-spacing:.08em;border:2px dashed #b8913a;border-radius:.5rem;padding:.4rem;text-align:center;user-select:all">${esc(v)}</div></div>`;

    /** Muestra credenciales entregadas por el servidor y recarga al cerrar. */
    function mostrar(r, titulo) {
        let html = '';
        if (r.clave_temporal) html += caja('Clave temporal de «' + esc(r.usuario) + '»', r.clave_temporal)
            + '<p class="small">Entrégasela en persona. Deberá cambiarla al iniciar sesión.</p>';
        if (r.llave) html += caja('Llave maestra (recuperación sin internet)', r.llave)
            + '<p class="small text-danger">Se muestra una sola vez. Guárdala fuera del equipo de la tienda.</p>';
        if (!html) return aviso(true, r.msg, () => location.reload());
        Swal.fire({icon: 'success', title: titulo, html, confirmButtonText: 'Ya la anoté', confirmButtonColor: '#b8913a',
                   allowOutsideClick: false, allowEscapeKey: false}).then(() => location.reload());
    }

    const f = document.getElementById('frm-usuario');
    f.addEventListener('submit', async ev => {
        ev.preventDefault();
        if (f.dataset.enviando === '1') return;
        if (!f.checkValidity()) { f.reportValidity(); return; }
        const b = f.querySelector('[type=submit], .btn-gold'); f.dataset.enviando = '1'; b.disabled = true;
        const r = await api('usuario', 'guardar', Object.fromEntries(new FormData(f).entries()));
        if (!r.ok) { f.dataset.enviando = ''; b.disabled = false; return aviso(false, r.msg); }
        bootstrap.Modal.getInstance(document.getElementById('modalUsuario'))?.hide();
        mostrar(r, 'Usuario guardado');
    });

    document.querySelectorAll('[data-reiniciar]').forEach(btn => btn.addEventListener('click', async () => {
        const ok = await Swal.fire({icon: 'warning', title: 'Restablecer contraseña',
            text: 'Se generará una clave temporal para ' + btn.dataset.nombre + ' y deberá cambiarla al entrar. Su contraseña actual dejará de servir.',
            showCancelButton: true, confirmButtonText: 'Sí, restablecer', cancelButtonText: 'Cancelar', confirmButtonColor: '#b8913a'});
        if (!ok.isConfirmed || btn.disabled) return;
        btn.disabled = true;
        const r = await api('usuario', 'reiniciarPassword', {id_usuario: Number(btn.dataset.reiniciar)});
        if (!r.ok) { btn.disabled = false; return aviso(false, r.msg); }
        mostrar(r, 'Clave temporal generada');
    }));

    const fp = document.getElementById('frm-permisos');
    fp.addEventListener('submit', async ev => {
        ev.preventDefault();
        const permisos = [...fp.querySelectorAll('input[type=checkbox]:checked:not(:disabled)')].map(c => c.value);
        const b = fp.querySelector('.btn-gold'); b.disabled = true;
        const r = await api('usuario', 'permisos', {rol: 'cajero', permisos});
        b.disabled = false;
        aviso(r.ok, r.msg, () => location.reload());
    });
})();
