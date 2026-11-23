/* Joyería Onix · Service Worker (Bloque 2 · PWA híbrida)
 *
 * Qué hace:
 *  - App Shell: precachea CSS/JS locales, Bootstrap, Bootstrap Icons y SweetAlert2 (todo local, public/vendor).
 *      · public/vendor/ e img/  -> Cache First (no cambian).
 *      · public/css/ y js/      -> Cache First con renovación en segundo plano (la próxima carga ya trae lo nuevo).
 *  - Navegación (páginas): Network First. Solo el POS tiene copia sin conexión, y NO es la página real
 *    (traería historial de ventas): es la variante «?c=venta&a=pos», sin historial, que se refresca cada vez que
 *    el POS carga con internet. Se borra al cerrar sesión (pwa.js) y caduca a los 7 días.
 *  - API/JSON, POST y cualquier respuesta con Cache-Control: no-store/private: NUNCA se cachean ni se interceptan.
 *
 * Al cambiar este archivo o la lista PRECACHE, sube VERSION.
 * Funciona en cualquier subcarpeta: todo se calcula a partir del scope donde se registró.
 */
'use strict';

const VERSION = 'v1';
const CACHE_ESTATICO = 'onix-estatico-' + VERSION;
const CACHE_PAGINAS  = 'onix-paginas-' + VERSION;
const BASE = new URL('./', self.registration.scope).href;          // https://host/carpeta/
const BASE_RUTA = new URL(BASE).pathname;                          // /carpeta/
const CLAVE_POS = BASE + 'index.php?c=venta&a=pos';                // copia sin historial del POS
const VIDA_POS_MS = 7 * 24 * 3600 * 1000;

const PRECACHE = [
    'manifest.json',
    'public/css/estilos.css',
    'public/js/app.js',
    'public/js/sesion.js',
    'public/js/offline.js',
    'public/js/pwa.js',
    'public/js/ventas.js',
    'public/vendor/bootstrap/css/bootstrap.min.css',
    'public/vendor/bootstrap/js/bootstrap.bundle.min.js',
    'public/vendor/bootstrap-icons/font/bootstrap-icons.min.css',
    'public/vendor/bootstrap-icons/font/fonts/bootstrap-icons.woff2',
    'public/vendor/bootstrap-icons/font/fonts/bootstrap-icons.woff',
    'public/vendor/sweetalert2/sweetalert2.all.min.js',
    'public/img/icon-192.png',
    'public/img/icon-512.png',
];

/* ------------------------------------------------------------------ ciclo de vida */

self.addEventListener('install', e => {
    e.waitUntil((async () => {
        const cache = await caches.open(CACHE_ESTATICO);
        // Uno por uno y tolerante: si falta un archivo (p. ej. public/vendor sin descargar) el SW igual se instala.
        await Promise.all(PRECACHE.map(r =>
            cache.add(new Request(BASE + r, {cache: 'reload'})).catch(() => console.warn('[sw] no se pudo precachear', r))));
        await self.skipWaiting();
    })());
});

self.addEventListener('activate', e => {
    e.waitUntil((async () => {
        const vigentes = [CACHE_ESTATICO, CACHE_PAGINAS];
        for (const k of await caches.keys()) {
            if (k.startsWith('onix-') && !vigentes.includes(k)) await caches.delete(k);
        }
        await self.clients.claim();
    })());
});

self.addEventListener('message', e => {
    const t = e.data && e.data.type;
    if (t === 'LIMPIAR_PAGINAS') e.waitUntil(caches.delete(CACHE_PAGINAS));   // cierre de sesión
    if (t === 'ACTUALIZAR_POS') e.waitUntil(actualizarPos());                  // primera sesión / entró por otra pantalla
    if (t === 'SKIP_WAITING') self.skipWaiting();
});

/* ------------------------------------------------------------------ utilidades */

/** Solo se guarda lo que es seguro: 200, mismo origen y sin no-store / private. */
function cacheable(resp) {
    if (!resp || resp.status !== 200 || resp.type !== 'basic') return false;
    return !/no-store|private/i.test(resp.headers.get('Cache-Control') || '');
}

function esControlador(url, c, a) {
    return url.searchParams.get('c') === c && (url.searchParams.get('a') || 'index') === a;
}

/* ------------------------------------------------------------------ estrategias */

async function cacheFirst(req, ignorarQuery) {
    const cache = await caches.open(CACHE_ESTATICO);
    const hit = await cache.match(req, {ignoreSearch: !!ignorarQuery});
    if (hit) return hit;
    const resp = await fetch(req);
    if (cacheable(resp)) cache.put(req, resp.clone());
    return resp;
}

/** Sirve lo guardado al instante y renueva en segundo plano. */
async function cacheFirstRenovando(e, req) {
    const cache = await caches.open(CACHE_ESTATICO);
    const hit = await cache.match(req);
    const renovar = fetch(req, {cache: 'no-cache'}).then(resp => {
        if (cacheable(resp)) cache.put(req, resp.clone());
        return resp;
    }).catch(() => null);
    if (hit) { e.waitUntil(renovar); return hit; }
    return (await renovar) || Response.error();
}

/** Guarda la variante del POS sin historial. Si ya no hay sesión o permiso, borra la copia anterior. */
async function actualizarPos() {
    const cache = await caches.open(CACHE_PAGINAS);
    try {
        const r = await fetch(CLAVE_POS, {credentials: 'same-origin', headers: {Accept: 'text/html'}, cache: 'no-store'});
        const html = (r.headers.get('Content-Type') || '').includes('text/html');
        if (r.ok && !r.redirected && html) {
            const h = new Headers(r.headers);
            h.set('X-Onix-Guardado', String(Date.now()));
            await cache.put(CLAVE_POS, new Response(await r.blob(), {status: 200, headers: h}));
        } else if (r.redirected || r.status === 401 || r.status === 403) {
            await cache.delete(CLAVE_POS);
        }
    } catch (err) { /* sin conexión: se conserva la copia que haya */ }
}

async function paginaPosGuardada() {
    const cache = await caches.open(CACHE_PAGINAS);
    const r = await cache.match(CLAVE_POS);
    if (!r) return null;
    const cuando = Number(r.headers.get('X-Onix-Guardado') || 0);
    if (!cuando || Date.now() - cuando > VIDA_POS_MS) { await cache.delete(CLAVE_POS); return null; }
    return r;
}

function paginaSinConexion() {
    const pos = BASE + 'index.php?c=venta&a=index';
    const html = '<!DOCTYPE html><html lang="es"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1">'
        + '<title>Sin conexión · Joyería Onix</title><style>body{font-family:system-ui,sans-serif;background:#17171c;color:#eee;display:flex;'
        + 'min-height:100vh;align-items:center;justify-content:center;margin:0;padding:1rem}main{max-width:26rem;text-align:center}'
        + 'h1{color:#b8913a;font-size:1.4rem}a,button{display:inline-block;margin:.3rem;padding:.6rem 1rem;border-radius:.5rem;border:0;'
        + 'background:#b8913a;color:#fff;text-decoration:none;font-size:1rem;cursor:pointer}</style></head><body><main>'
        + '<h1>Sin conexión</h1><p>Esta pantalla necesita internet. Las ventas se pueden seguir haciendo sin conexión desde el punto de venta; '
        + 'quedan guardadas en este equipo y se sincronizan solas al volver la red.</p>'
        + '<a href="' + pos + '">Ir a Ventas (POS)</a><button onclick="location.reload()">Reintentar</button></main></body></html>';
    return new Response(html, {status: 503, headers: {'Content-Type': 'text/html; charset=utf-8', 'Cache-Control': 'no-store'}});
}

async function navegacion(e, req, url) {
    const esPos = esControlador(url, 'venta', 'index') && [...url.searchParams.keys()].every(k => k === 'c' || k === 'a');
    const esInicio = esControlador(url, 'auth', 'login') || url.pathname === BASE_RUTA || url.pathname === BASE_RUTA + 'index.php' && !url.search;
    try {
        const resp = await fetch(req);
        if (esPos && resp.ok) e.waitUntil(actualizarPos());
        return resp;
    } catch (err) {
        if (esPos || esInicio) {            // el POS (o la pantalla de inicio de la app instalada) abre sin internet
            const pos = await paginaPosGuardada();
            if (pos) return pos;
        }
        return paginaSinConexion();
    }
}

/* ------------------------------------------------------------------ fetch */

self.addEventListener('fetch', e => {
    const req = e.request;
    if (req.method !== 'GET') return;                                  // POST (api, cobros, login…): nunca
    const url = new URL(req.url);
    if (url.origin !== self.location.origin || !url.pathname.startsWith(BASE_RUTA)) return;   // otras apps del mismo servidor

    if (req.mode === 'navigate') {
        if (req.destination !== 'document') return;                    // iframe de impresión de tickets: sin tocar
        e.respondWith(navegacion(e, req, url));
        return;
    }

    const ruta = url.pathname.slice(BASE_RUTA.length);
    if ((req.headers.get('Accept') || '').includes('application/json') || ruta === 'index.php') return;   // api(): jamás

    if (ruta.startsWith('public/vendor/') || ruta.startsWith('public/img/')) {
        e.respondWith(cacheFirst(req, true));
    } else if (ruta.startsWith('public/css/') || ruta.startsWith('public/js/') || ruta === 'manifest.json') {
        e.respondWith(cacheFirstRenovando(e, req));
    }
});
