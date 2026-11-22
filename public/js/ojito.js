/* Botón «ojito» (mostrar/ocultar) para TODOS los <input type="password">. Sin dependencias: usa Bootstrap Icons.
 * Opt-out: data-sin-ojito en el input. El campo vuelve a ocultarse solo al enviar el formulario o al cambiar de pestaña. */
(() => {
    const campos = [];

    function poner(estado, input, btn) {
        input.type = estado ? 'text' : 'password';
        btn.setAttribute('aria-pressed', estado ? 'true' : 'false');
        btn.setAttribute('aria-label', estado ? 'Ocultar contraseña' : 'Mostrar contraseña');
        btn.title = estado ? 'Ocultar contraseña' : 'Mostrar contraseña';
        btn.firstElementChild.className = 'bi ' + (estado ? 'bi-eye-slash' : 'bi-eye');
    }

    function agregar(input) {
        if (input.dataset.ojito === '1' || input.hasAttribute('data-sin-ojito')) return;
        input.dataset.ojito = '1';
        const btn = document.createElement('button');
        btn.type = 'button';
        btn.tabIndex = 0;
        btn.innerHTML = '<i class="bi bi-eye"></i>';
        const grupo = input.parentElement && input.parentElement.classList.contains('input-group') ? input.parentElement : null;
        if (grupo) {
            btn.className = 'btn btn-outline-secondary';
            grupo.appendChild(btn);
        } else {
            const caja = document.createElement('div');
            caja.className = 'position-relative';
            input.parentNode.insertBefore(caja, input);
            caja.appendChild(input);
            btn.className = 'btn btn-link text-secondary position-absolute top-50 end-0 translate-middle-y py-0 px-2';
            btn.style.zIndex = '5';
            input.style.paddingRight = '2.5rem';
            caja.appendChild(btn);
        }
        poner(false, input, btn);
        btn.addEventListener('click', () => { poner(input.type === 'password', input, btn); input.focus({preventScroll: true}); });
        campos.push([input, btn]);
        if (input.form) input.form.addEventListener('submit', () => poner(false, input, btn));
    }

    document.querySelectorAll('input[type=password]').forEach(agregar);
    document.addEventListener('visibilitychange', () => { if (document.hidden) campos.forEach(([i, b]) => poner(false, i, b)); });
})();
