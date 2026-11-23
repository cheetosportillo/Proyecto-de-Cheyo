# Prueba de navegador del modo offline (opcional)

Abre Chromium real contra un servidor simulado (`mock.js`, mismo contrato que `Sincronizacion.php`) y comprueba Service Worker,
App Shell, IndexedDB, cola FIFO, conflictos, pausa por sesión, protección al cerrar sesión y que nada sensible quede en caché.
No necesita PHP ni MariaDB. Requiere Node y Playwright:

    npm i -D playwright && npx playwright install chromium
    node tests/pwa-navegador/test.js        # esperado: 46 ok, 0 fallidas

No forma parte de `tests/run.php` (que es solo PHP).
