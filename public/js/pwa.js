/* PWA: registra el Service Worker (solo https:// o localhost), ofrece «Instalar app» y borra la copia sin conexión
 * del POS al cerrar sesión. Sin dependencias: también se carga en la pantalla de login. */
(() => {
    'use strict';
    // La carpeta del sistema se deduce de este mismo script: .../<carpeta>/public/js/pwa.js
    const yo = document.currentScript && document.currentScript.src;
    const raiz = yo ? yo.replace(/public\/js\/pwa\.js(\?.*)?$/, '') : null;

    // isSecureContext es true en https:// y en localhost / 127.0.0.1; en http://192.168.x.x (LAN) es false.
    if (raiz && 'serviceWorker' in navigator && window.isSecureContext) {
        window.addEventListener('load', () => {
            navigator.serviceWorker.register(raiz + 'sw.js', {scope: raiz})
                .catch(err => console.warn('[pwa] no se pudo registrar el Service Worker:', err));
        });
    }

    // Quien puede cobrar guarda desde el primer momento la copia sin conexión del POS (no hace falta haber visitado el POS antes).
    // Como mucho una vez cada 20 min por navegador; si ya no hay sesión/permiso el SW borra la copia anterior.
    if (raiz && 'serviceWorker' in navigator && window.isSecureContext) {
        navigator.serviceWorker.ready.then(reg => {
            if (typeof ONIX_USR === 'undefined' || !ONIX_USR.puedeVender || !reg.active) return;
            let ultima = 0;
            try { ultima = Number(localStorage.getItem('onix_pos_shell')) || 0; } catch (e) { /* sin localStorage */ }
            if (Date.now() - ultima < 20 * 60 * 1000) return;
            try { localStorage.setItem('onix_pos_shell', String(Date.now())); } catch (e) { /* nada */ }
            reg.active.postMessage({type: 'ACTUALIZAR_POS'});
        }).catch(() => null);
    }

    // Cerrar sesión: la copia sin conexión del POS (nombre de usuario, categorías) no debe quedar para la siguiente persona.
    // offline.js intercepta este formulario si hay ventas pendientes (preventDefault); en ese caso aquí no se borra nada.
    // (Este script se carga DESPUÉS de offline.js, así que su manejador corre cuando defaultPrevented ya está decidido.)
    window.onixPurgarPaginas = () => {
        try { localStorage.removeItem('onix_pos_shell'); } catch (e) { /* nada */ }
        return ('caches' in window)
            ? caches.keys().then(ks => Promise.all(ks.filter(k => k.startsWith('onix-paginas-')).map(k => caches.delete(k)))).catch(() => null)
            : Promise.resolve();
    };
    document.addEventListener('submit', e => {
        const f = e.target;
        if (e.defaultPrevented || !(f instanceof HTMLFormElement) || !/[?&]a=logout(&|$)/.test(f.getAttribute('action') || '')) return;
        window.onixPurgarPaginas();
    });

    // Botón «Instalar app» (Chrome/Edge/Android lo permiten cuando hay manifest + SW + íconos).
    let instalador = null;
    const boton = () => document.getElementById('btnInstalarPwa');
    window.addEventListener('beforeinstallprompt', e => {
        e.preventDefault();
        instalador = e;
        const b = boton();
        if (b) b.classList.remove('d-none');
    });
    document.addEventListener('click', async e => {
        if (!e.target.closest || !e.target.closest('#btnInstalarPwa') || !instalador) return;
        instalador.prompt();
        await instalador.userChoice.catch(() => null);
        instalador = null;
        const b = boton();
        if (b) b.classList.add('d-none');
    });
    window.addEventListener('appinstalled', () => { const b = boton(); if (b) b.classList.add('d-none'); });
})();
