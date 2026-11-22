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

/* Dueña: regenerar llave maestra (pide la contraseña actual; la llave nueva se muestra una sola vez). */
(() => {
    const f = document.getElementById('frm-llave');
    if (!f) return;
    const $ = id => document.getElementById(id);
    f.addEventListener('submit', async ev => {
        ev.preventDefault();
        if (f.dataset.enviando === '1') return;
        if (!f.checkValidity()) { f.reportValidity(); return; }
        const ok = await Swal.fire({icon: 'warning', title: '¿Regenerar la llave maestra?',
            text: 'La llave actual dejará de funcionar. Asegúrate de poder imprimir o anotar la nueva ahora.',
            showCancelButton: true, confirmButtonText: 'Sí, regenerar', cancelButtonText: 'Cancelar', confirmButtonColor: '#b8913a'});
        if (!ok.isConfirmed) return;
        f.dataset.enviando = '1';
        const b = f.querySelector('[type=submit]'); b.disabled = true;
        const r = await api('auth', 'regenerarLlave', {actual: f.elements.actual.value});
        f.dataset.enviando = ''; b.disabled = false;
        if (!r.ok) return aviso(false, r.msg);
        f.reset();
        $('bloque-llave').classList.add('d-none');
        $('frm-cambiar').classList.add('d-none');
        $('llave-nueva').textContent = r.llave;          // solo en pantalla: no se guarda en el navegador
        $('hoja-codigo').textContent = r.llave;
        $('hoja-fecha').textContent = new Date().toLocaleString('es-SV');
        $('vista-llave').classList.remove('d-none');
    });
    $('btn-imprimir')?.addEventListener('click', () => window.print());
    $('btn-listo')?.addEventListener('click', () => {
        $('llave-nueva').textContent = ''; $('hoja-codigo').textContent = '';   // la llave ya no queda en el DOM
        location.reload();
    });
})();
