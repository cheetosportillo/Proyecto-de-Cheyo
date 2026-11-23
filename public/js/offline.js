/* Joyería Onix · Modo offline híbrido (Bloque 2)
 *
 * IndexedDB «onix-pos»:
 *   catalogo : copia local de los productos vendibles (se actualiza por cambios, usando updated_at del servidor).
 *   cola     : ventas hechas sin conexión, en orden FIFO (seq). Cada una lleva su uuid_operacion: el servidor es idempotente.
 *   meta     : cursor del catálogo y marcas de tiempo.
 *
 * Reglas de sincronización:
 *   - 401 / 403 / 419 sin remedio: se PAUSA y se avisa; nada se borra de la cola.
 *   - Conflicto de negocio (caja cerrada, sin stock, precio cambiado…): la venta pasa a «Requiere revisión» con el motivo
 *     EXACTO del servidor; no se descarta en silencio. Se puede reintentar; descartar exige permiso, motivo y queda en bitácora.
 *   - Red caída / error 5xx / respuesta rara: sigue «Pendiente» y se reintenta con espera creciente (FIFO: no se salta el orden).
 *
 * API pública (window.Offline): guardarCatalogo, buscarCatalogo, guardarVentaPendiente, sincronizar, sincronizarCatalogo,
 * contar, listar, reintentar, descartar, refrescarSesion, sinConexion, abrirPanel, estado.
 * No usa api() de app.js a propósito: api() redirige al login en un 401 y aquí eso perdería la pantalla.
 */
(() => {
    'use strict';
    const USR = typeof ONIX_USR !== 'undefined' ? ONIX_USR : null;   // {id, nombre, puedeVender, puedeDescartar}
    if (!USR) return;                                               // solo páginas con sesión (layout)

    const DB_NOMBRE = 'onix-pos', DB_VERSION = 1;
    const CATALOGO_CADA_MS = 3 * 60 * 1000;        // no más de 1 consulta de catálogo cada 3 min (salvo forzar)
    const TICK_MS = 20000;
    const ZONA = 'America/El_Salvador';

    const esc = s => { const d = document.createElement('div'); d.textContent = s ?? ''; return d.innerHTML; };
    const dinero = n => (typeof fmt === 'function') ? fmt(n) : '$ ' + Number(n).toFixed(2);
    const norm = s => String(s ?? '').normalize('NFD').replace(/[\u0300-\u036f]/g, '').toLowerCase();
    const hora = iso => { try { return new Date(iso).toLocaleString('es-SV', {timeZone: ZONA, day: '2-digit', month: '2-digit', hour: '2-digit', minute: '2-digit'}); } catch { return iso; } };
    const plural = (n, s, p) => n + ' ' + (n === 1 ? s : p);
    /** Aviso propio (NO SweetAlert): Swal solo muestra un cuadro a la vez y un aviso de fondo cerraría, p. ej., el «imprimir ticket». */
    const toast = (icon, title, ms = 5000) => {
        let cont = document.getElementById('onixToasts');
        if (!cont) {
            cont = document.createElement('div');
            cont.id = 'onixToasts';
            cont.setAttribute('aria-live', 'polite');
            document.body.appendChild(cont);
        }
        const clases = {success: 'success', error: 'danger', warning: 'warning', info: 'info'};
        const el = document.createElement('div');
        el.className = 'onix-toast alert alert-' + (clases[icon] || 'info') + ' shadow-sm py-2 mb-2';
        el.setAttribute('role', 'status');
        el.textContent = title;
        cont.appendChild(el);
        setTimeout(() => el.remove(), ms);
    };

    /* =============================================================== IndexedDB */
    let _db = null;
    const rq = r => new Promise((res, rej) => { r.onsuccess = () => res(r.result); r.onerror = () => rej(r.error); });
    const fin = t => new Promise((res, rej) => {
        t.oncomplete = () => res();
        t.onerror = () => rej(t.error);
        t.onabort = () => rej(t.error || new Error('Transacción cancelada'));
    });

    function abrirDb() {
        if (_db) return Promise.resolve(_db);
        return new Promise((res, rej) => {
            if (!('indexedDB' in window)) return rej(new Error('IndexedDB no disponible'));
            const o = indexedDB.open(DB_NOMBRE, DB_VERSION);
            o.onupgradeneeded = () => {
                const db = o.result;
                if (!db.objectStoreNames.contains('catalogo')) {
                    const s = db.createObjectStore('catalogo', {keyPath: 'id_producto'});
                    s.createIndex('codigo', 'codigo');
                }
                if (!db.objectStoreNames.contains('cola')) {
                    const s = db.createObjectStore('cola', {keyPath: 'seq', autoIncrement: true});
                    s.createIndex('uuid', 'uuid', {unique: true});
                    s.createIndex('estado', 'estado');
                }
                if (!db.objectStoreNames.contains('meta')) db.createObjectStore('meta', {keyPath: 'clave'});
            };
            o.onsuccess = () => {
                _db = o.result;
                _db.onversionchange = () => { _db.close(); _db = null; };
                res(_db);
            };
            o.onerror = () => rej(o.error);
            o.onblocked = () => rej(new Error('IndexedDB bloqueada por otra pestaña'));
        });
    }

    async function metaGet(clave) {
        const db = await abrirDb();
        const r = await rq(db.transaction('meta').objectStore('meta').get(clave));
        return r ? r.valor : undefined;
    }
    async function metaSet(clave, valor) {
        const db = await abrirDb();
        const t = db.transaction('meta', 'readwrite'), f = fin(t);
        t.objectStore('meta').put({clave, valor});
        await f;
    }

    /* =============================================================== estado + indicador */
    const estado = {servidor: true, sincronizando: false, pausa: null, pend: 0, rev: 0, idb: true, catalogo: 0, catalogoEn: 0};
    let proximoIntento = 0, espera = 15000, proximoChequeoPausa = 0;

    const enLinea = () => navigator.onLine && estado.servidor;
    const sinConexion = () => !enLinea();

    function servidor(ok) {
        if (estado.servidor === ok) return;
        estado.servidor = ok;
        pintar();
    }

    const MSG_PAUSA = {
        sesion: 'Tu sesión venció. Inicia sesión de nuevo para subir tus ventas; siguen guardadas en este equipo.',
        csrf: 'La sesión cambió y el servidor rechazó el envío. Inicia sesión de nuevo; tus ventas siguen guardadas.',
        permiso: 'Tu usuario ya no tiene permiso para cobrar. Pide a la Dueña que lo revise; tus ventas siguen guardadas.',
        cambiar_password: 'Debes cambiar tu contraseña antes de seguir. Tus ventas siguen guardadas y se subirán después.',
        otro_usuario: 'Hay otra persona con la sesión abierta en este navegador. Entra con tu usuario para subir tus ventas.',
    };

    function pintar() {
        const pend = estado.pend, rev = estado.rev;
        let clase = 'ok', texto = 'En línea';
        if (!estado.idb) { clase = 'off'; texto = 'Sin modo offline'; }
        else if (sinConexion()) { clase = 'warn'; texto = 'Sin conexión' + (pend ? ' · ' + plural(pend, 'pendiente', 'pendientes') : ''); }
        else if (estado.pausa) { clase = 'warn'; texto = 'Sincronización en pausa' + (pend ? ' · ' + plural(pend, 'pendiente', 'pendientes') : ''); }
        else if (pend) { clase = 'warn'; texto = (estado.sincronizando ? 'Sincronizando · ' : 'En línea · ') + plural(pend, 'pendiente', 'pendientes'); }

        document.querySelectorAll('[data-onix-estado]').forEach(el => {
            const compacto = el.hasAttribute('data-compacto');
            const botones = USR.puedeVender && estado.idb;
            el.innerHTML =
                `<div class="onix-estado onix-estado--${clase}" role="status" aria-live="polite">`
                + (botones
                    ? `<button type="button" class="onix-estado__main" data-onix-abrir title="Ver ventas sin conexión"><span class="onix-punto"></span><span class="onix-texto">${esc(compacto ? texto.replace('Sincronización en pausa', 'En pausa') : texto)}</span></button>`
                      + (compacto ? '' : `<button type="button" class="onix-estado__btn" data-onix-sync title="Sincronizar ahora" aria-label="Sincronizar ahora"><i class="bi bi-arrow-repeat${estado.sincronizando ? ' onix-gira' : ''}"></i></button>`)
                    : `<span class="onix-estado__main"><span class="onix-punto"></span><span class="onix-texto">${esc(texto)}</span></span>`)
                + '</div>'
                + (rev ? `<div class="onix-estado__rev"><i class="bi bi-exclamation-triangle-fill"></i> ${esc(plural(rev, 'venta requiere revisión', 'ventas requieren revisión'))}</div>` : '');
        });
        document.dispatchEvent(new CustomEvent('onix:estado', {detail: {...estado, sinConexion: sinConexion()}}));
        if (panelVisible()) pintarPanel();
    }

    /* =============================================================== red (sin api(): no redirige) */
    async function pedir(c, a, datos = null, query = '', timeoutMs = 25000) {
        const ctl = new AbortController();
        const temporizador = setTimeout(() => ctl.abort(), timeoutMs);
        const opt = {credentials: 'same-origin', cache: 'no-store', signal: ctl.signal, headers: {Accept: 'application/json'}};
        if (datos !== null) {
            opt.method = 'POST';
            opt.headers['Content-Type'] = 'application/json';
            opt.headers['X-CSRF-TOKEN'] = typeof CSRF !== 'undefined' ? CSRF : '';
            opt.body = JSON.stringify(datos);
        }
        let r;
        try {
            r = await fetch(`${BASE_URL}?c=${c}&a=${a}${query}`, opt);
        } catch (err) {
            servidor(false);
            return {ok: false, sinConexion: true, msg: ctl.signal.aborted ? 'El servidor tardó demasiado en responder.' : 'Sin conexión con el servidor.'};
        } finally {
            clearTimeout(temporizador);
        }
        servidor(true);   // hubo respuesta HTTP: el servidor está alcanzable (aunque falle)
        let j = null;
        try { j = await r.json(); } catch { /* HTML de error, portal cautivo, etc. */ }
        if (!j || typeof j !== 'object') j = {ok: false, invalida: true, msg: 'Respuesta inválida del servidor.'};
        j.http = r.status;
        return j;
    }

    /** Estado de la sesión SIN renovarla (auth/estadoSesion) y CSRF vigente. */
    async function refrescarSesion() {
        const r = await pedir('auth', 'estadoSesion');
        if (r.sinConexion) return {sinConexion: true};
        if (r.http === 200 && r.ok && r.activa === false) return {activa: false};
        if (r.http === 200 && r.ok && r.activa) {
            if (r.csrf && typeof actualizarCsrf === 'function') actualizarCsrf(r.csrf);
            return {activa: true, usuario: r.usuario};
        }
        return {inesperada: true, http: r.http};
    }

    /* =============================================================== catálogo local */
    const CAMPOS = ['id_producto', 'codigo', 'nombre', 'peso', 'unidad_peso', 'precio_venta', 'stock', 'id_categoria', 'categoria'];

    /** Upsert; los que el servidor marca vigente=false (sin stock / inactivos) se eliminan de la copia local. */
    async function guardarCatalogo(items) {
        if (!Array.isArray(items) || !items.length) return 0;
        const db = await abrirDb();
        const t = db.transaction('catalogo', 'readwrite'), f = fin(t), s = t.objectStore('catalogo');
        let n = 0;
        for (const p of items) {
            const id = +p.id_producto;
            if (!id) continue;
            if (p.vigente === false || p.vigente === 0 || p.vigente === '0') { s.delete(id); continue; }
            const fila = {};
            CAMPOS.forEach(k => { fila[k] = p[k]; });
            fila.id_producto = id; fila.id_categoria = +p.id_categoria || 0;
            fila.stock = +p.stock; fila.precio_venta = +p.precio_venta;
            s.put(fila); n++;
        }
        await f;
        return n;
    }

    /** Cantidades ya vendidas en la cola y aún no confirmadas por el servidor (para no vender dos veces lo mismo offline). */
    async function reservas() {
        const m = new Map();
        for (const e of await listar()) {
            if (e.estado === 'sincronizada') continue;
            for (const i of e.items) m.set(+i.id, (m.get(+i.id) || 0) + (+i.cantidad));
        }
        return m;
    }

    /** Misma forma que venta/buscar: stock disponible = stock local − lo ya vendido en la cola. Máx. 60, orden por nombre. */
    async function buscarCatalogo(q, cat) {
        const db = await abrirDb();
        const todos = await rq(db.transaction('catalogo').objectStore('catalogo').getAll());
        const res = await reservas();
        const nq = norm(q), c = +cat || 0;
        return todos
            .filter(p => (!c || +p.id_categoria === c) && (!nq || norm(p.nombre).includes(nq) || norm(p.codigo).includes(nq)))
            .map(p => ({...p, stock: p.stock - (res.get(+p.id_producto) || 0)}))
            .filter(p => p.stock > 0)
            .sort((a, b) => String(a.nombre).localeCompare(String(b.nombre), 'es'))
            .slice(0, 60);
    }

    let catalogoEnCurso = null;
    function sincronizarCatalogo(forzar = false) {
        if (!USR.puedeVender) return Promise.resolve({omitido: true});
        if (catalogoEnCurso) return catalogoEnCurso;
        const correr = async () => {
            const ultima = (await metaGet('catalogo_ultima')) || 0;
            if (!forzar && Date.now() - ultima < CATALOGO_CADA_MS) return {omitido: true};
            const cursor = await metaGet('catalogo_cursor');
            const completo = forzar || !cursor;
            let desde = completo ? '' : cursor, desdeId = 0, primero = null, total = 0;
            const vistos = new Set();
            for (let pagina = 0; pagina < 200; pagina++) {
                const r = await pedir('venta', 'catalogo', null, `&desde=${encodeURIComponent(desde)}&desde_id=${desdeId}&completo=${completo ? 1 : 0}&limite=1000`);
                if (!r.ok) return {ok: false, sinConexion: !!r.sinConexion, http: r.http, msg: r.msg};
                if (primero === null) primero = r.cursor_sugerido;
                total += await guardarCatalogo(r.items);
                r.items.forEach(p => vistos.add(+p.id_producto));
                if (!r.hay_mas) break;
                desde = r.ultimo.cambio; desdeId = r.ultimo.id;
            }
            if (completo) {   // copia completa: lo que ya no es vendible desaparece
                const db = await abrirDb();
                const t = db.transaction('catalogo', 'readwrite'), f = fin(t), s = t.objectStore('catalogo');
                for (const k of await rq(s.getAllKeys())) if (!vistos.has(k)) s.delete(k);
                await f;
            }
            await metaSet('catalogo_cursor', primero);
            await metaSet('catalogo_ultima', Date.now());
            estado.catalogo = await contarCatalogo(); estado.catalogoEn = Date.now();
            if (panelVisible()) pintarPanel();
            return {ok: true, completo, recibidos: total};
        };
        const correrConCandado = () => (navigator.locks
            ? navigator.locks.request('onix-catalogo', {ifAvailable: true}, l => l ? correr() : {ocupado: true})
            : correr());
        catalogoEnCurso = correrConCandado().catch(err => ({ok: false, msg: String(err && err.message || err)})).finally(() => { catalogoEnCurso = null; });
        return catalogoEnCurso;
    }

    async function contarCatalogo() {
        const db = await abrirDb();
        return rq(db.transaction('catalogo').objectStore('catalogo').count());
    }

    /* =============================================================== cola de ventas */
    async function listar() {
        const db = await abrirDb();
        return rq(db.transaction('cola').objectStore('cola').getAll());   // orden por seq = FIFO
    }

    async function contar() {
        const todas = await listar();
        const mias = todas.filter(e => e.usuario_id === USR.id);
        return {
            pendientes: mias.filter(e => e.estado === 'pendiente' || e.estado === 'sincronizando').length,
            revision: mias.filter(e => e.estado === 'revision').length,
            sincronizadas: mias.filter(e => e.estado === 'sincronizada').length,
            deOtros: todas.filter(e => e.usuario_id !== USR.id && e.estado !== 'sincronizada').length,
        };
    }

    async function recontar() {
        try { const c = await contar(); estado.pend = c.pendientes; estado.rev = c.revision; estado.otros = c.deOtros; }
        catch { estado.idb = false; }
        pintar();
    }

    async function actualizar(uuid, cambios) {
        const db = await abrirDb();
        const t = db.transaction('cola', 'readwrite'), f = fin(t), s = t.objectStore('cola');
        const e = await rq(s.index('uuid').get(uuid));
        if (e) { Object.assign(e, cambios); s.put(e); }
        await f;
        return e || null;
    }

    async function borrar(uuid) {
        const db = await abrirDb();
        const t = db.transaction('cola', 'readwrite'), f = fin(t), s = t.objectStore('cola');
        const e = await rq(s.index('uuid').get(uuid));
        if (e) s.delete(e.seq);
        await f;
    }

    /** Guarda la venta (idempotente por uuid). Lanza error si no se pudo escribir: quien llama NO debe vaciar el carrito. */
    async function guardarVentaPendiente(d) {
        const uuid = String(d.uuid_operacion || '');
        if (!/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/i.test(uuid)) throw new Error('uuid_operacion no válido');
        const e = {
            uuid, usuario_id: USR.id, usuario_nombre: USR.nombre,
            creado: d.fecha || new Date().toISOString(),
            total: Number(d.total), efectivo: Number(d.efectivo),
            items: (d.items || []).map(i => ({id: +i.id, cantidad: +i.cantidad})),
            detalle: (d.detalle || []).map(i => ({id: +i.id, codigo: i.codigo, nombre: i.nombre, cantidad: +i.cantidad, precio: +i.precio})),
            estado: 'pendiente', motivo: '', codigo: '', intentos: 0, ultimo_intento: 0,
            ticket: 'TEMP-' + uuid.replace(/-/g, '').slice(0, 6).toUpperCase(),
        };
        const db = await abrirDb();
        const t = db.transaction('cola', 'readwrite'), f = fin(t), s = t.objectStore('cola');
        const ya = await rq(s.index('uuid').get(uuid));
        if (ya) { await f; return ya; }
        e.seq = await rq(s.add(e));
        await f;
        await recontar();
        proximoIntento = 0;
        setTimeout(() => { if (navigator.onLine) sincronizar(); }, 1500);
        return e;
    }

    const cuerpo = e => ({
        uuid_operacion: e.uuid, efectivo: e.efectivo, items: e.items, fecha_iso: e.creado,
        total_esperado: e.total, ticket_temp: e.ticket, id_usuario_origen: e.usuario_id,
    });

    /* =============================================================== sincronización FIFO */
    let enCurso = false;

    function sincronizar(opciones = {}) {
        const correr = () => ejecutarSync(!!opciones.manual);
        if (navigator.locks) return navigator.locks.request('onix-sync', {ifAvailable: true}, l => l ? correr() : {ocupado: true});
        if (enCurso) return Promise.resolve({ocupado: true});
        enCurso = true;
        return correr().finally(() => { enCurso = false; });
    }

    function pausar(motivo, manual) {
        const nuevo = estado.pausa !== motivo;
        estado.pausa = motivo;
        proximoChequeoPausa = Date.now() + 60000;
        if (nuevo || manual) toast('warning', MSG_PAUSA[motivo] || 'Sincronización en pausa.', 9000);
    }

    async function ejecutarSync(manual) {
        const res = {enviadas: 0, duplicadas: 0, revision: 0, pausa: null, red: false, pendientes: 0, otros: 0};
        estado.sincronizando = true; pintar();
        try {
            const todas = await listar();
            const lista = todas.filter(e => e.usuario_id === USR.id && (e.estado === 'pendiente' || e.estado === 'sincronizando'));
            res.otros = todas.filter(e => e.usuario_id !== USR.id && e.estado !== 'sincronizada').length;
            if (!lista.length) { estado.pausa = null; return res; }

            // 1) ¿hay sesión? (no la renueva) y CSRF vigente; también detecta que otra persona haya entrado en este navegador
            const s = await refrescarSesion();
            if (s.sinConexion) { res.red = true; return res; }
            if (s.inesperada) { res.red = true; return res; }       // respuesta rara/5xx: reintentar luego
            if (s.activa === false) { res.pausa = 'sesion'; pausar('sesion', manual); return res; }
            if (s.usuario && +s.usuario !== USR.id) { res.pausa = 'otro_usuario'; pausar('otro_usuario', manual); return res; }
            estado.pausa = null;

            // 2) una por una, en orden: así el stock se reparte como ocurrió en la tienda
            for (const e of lista) {
                await actualizar(e.uuid, {estado: 'sincronizando', intentos: (e.intentos || 0) + 1, ultimo_intento: Date.now()});
                let r = await pedir('venta', 'sincronizar', cuerpo(e));
                if (r.http === 419) { await refrescarSesion(); r = await pedir('venta', 'sincronizar', cuerpo(e)); }

                if (r.sinConexion || r.http >= 500 || r.invalida) {        // transitorio: sigue pendiente y se corta (FIFO)
                    await actualizar(e.uuid, {estado: 'pendiente'}); res.red = true; break;
                }
                if (r.http === 401 || r.http === 419) {                       // sesión vencida / CSRF sin remedio: pausa, no se pierde nada
                    await actualizar(e.uuid, {estado: 'pendiente'});
                    res.pausa = r.http === 401 ? 'sesion' : 'csrf'; pausar(res.pausa, manual); break;
                }
                if (r.http === 403) {                                          // permiso retirado o cambio de contraseña obligatorio
                    await actualizar(e.uuid, {estado: 'pendiente'});
                    res.pausa = r.cambiar_password ? 'cambiar_password' : 'permiso'; pausar(res.pausa, manual); break;
                }
                if (r.ok && (r.estado === 'registrada' || r.estado === 'duplicada')) {
                    await actualizar(e.uuid, {estado: 'sincronizada', motivo: '', codigo: '', id_venta: r.id_venta,
                        numero_ticket: r.numero_ticket, ticket_url: r.ticket, duplicada: r.estado === 'duplicada', sincronizada_en: Date.now()});
                    r.estado === 'duplicada' ? res.duplicadas++ : res.enviadas++;
                    continue;
                }
                if (r.estado === 'conflicto' && r.codigo === 'usuario_distinto') {
                    await actualizar(e.uuid, {estado: 'pendiente'});
                    res.pausa = 'otro_usuario'; pausar('otro_usuario', manual); break;
                }
                if (r.estado === 'conflicto') {                              // negocio: NO se descarta, queda para revisar
                    await actualizar(e.uuid, {estado: 'revision', motivo: r.msg || 'El servidor rechazó la venta.', codigo: r.codigo || 'rechazada'});
                    res.revision++;
                    continue;
                }
                await actualizar(e.uuid, {estado: 'pendiente', motivo: ''});  // respuesta inesperada: no adivinar
                res.red = true; break;
            }
        } finally {
            await limpiarSincronizadas().catch(() => null);
            await recontar();
            estado.sincronizando = false;
            res.pendientes = estado.pend;
            if (res.red) { espera = Math.min(espera * 2, 120000); proximoIntento = Date.now() + espera; }
            else { espera = 15000; proximoIntento = 0; }
            pintar();
        }
        if (res.enviadas || res.duplicadas) {
            toast('success', plural(res.enviadas + res.duplicadas, 'venta sincronizada', 'ventas sincronizadas') + (res.duplicadas ? ' (alguna ya estaba registrada)' : '') + '.');
            sincronizarCatalogo(true);   // el stock cambió: se refresca la copia local
            document.dispatchEvent(new CustomEvent('onix:sincronizado', {detail: res}));
        }
        if (res.revision) toast('error', plural(res.revision, 'venta requiere revisión', 'ventas requieren revisión') + '. Abre la lista para ver el motivo.', 9000);
        return res;
    }

    async function limpiarSincronizadas() {
        const hechas = (await listar()).filter(e => e.estado === 'sincronizada').sort((a, b) => (b.sincronizada_en || 0) - (a.sincronizada_en || 0));
        const viejo = Date.now() - 3 * 24 * 3600 * 1000;
        for (const [i, e] of hechas.entries()) if (i >= 20 || (e.sincronizada_en || 0) < viejo) await borrar(e.uuid);
    }

    async function reintentar(uuid) {
        await actualizar(uuid, {estado: 'pendiente', motivo: '', codigo: ''});
        await recontar();
        proximoIntento = 0;
        return sincronizar({manual: true});
    }

    /** Descartar una venta «por revisar»: solo con internet (queda en bitácora) y solo si el servidor confirma que NO existe. */
    async function descartar(uuid, motivo) {
        const e = (await listar()).find(x => x.uuid === uuid);
        if (!e) return {ok: false, msg: 'La venta ya no está en la cola.'};
        const s = await refrescarSesion();
        if (!s.activa) return {ok: false, msg: s.sinConexion ? 'Necesitas conexión para descartar (queda registrado en la bitácora).' : 'Inicia sesión de nuevo para poder descartar.'};
        const r = await pedir('venta', 'descartar', {uuid_operacion: uuid, motivo, ticket_temp: e.ticket, total: e.total, fecha_iso: e.creado, codigo: e.codigo, detalle_motivo: e.motivo});
        if (r.estado === 'ya_registrada') {
            await actualizar(uuid, {estado: 'sincronizada', id_venta: r.id_venta, numero_ticket: r.numero_ticket, ticket_url: r.ticket, sincronizada_en: Date.now(), motivo: ''});
            await recontar();
            return {ok: true, yaRegistrada: true, numero_ticket: r.numero_ticket, msg: r.msg};
        }
        if (r.ok) { await borrar(uuid); await recontar(); }
        return r;
    }

    /* =============================================================== panel de la cola */
    let panel = null;
    const panelVisible = () => !!panel && panel.classList.contains('show');

    function crearPanel() {
        if (panel) return panel;
        panel = document.createElement('div');
        panel.className = 'modal fade';
        panel.id = 'onixPanelCola';
        panel.tabIndex = -1;
        panel.innerHTML = '<div class="modal-dialog modal-lg modal-dialog-scrollable"><div class="modal-content">'
            + '<div class="modal-header"><h5 class="modal-title"><i class="bi bi-cloud-arrow-up"></i> Ventas sin conexión</h5>'
            + '<button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Cerrar"></button></div>'
            + '<div class="modal-body"><div id="onixPanelAviso"></div><div id="onixPanelCuerpo"></div>'
            + '<div class="small text-muted mt-3" id="onixPanelCatalogo"></div></div>'
            + '<div class="modal-footer"><button type="button" class="btn btn-gold" id="onixPanelSync"><i class="bi bi-arrow-repeat"></i> Sincronizar ahora</button>'
            + '<button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Cerrar</button></div></div></div>';
        document.body.appendChild(panel);
        panel.addEventListener('click', alClicPanel);
        return panel;
    }

    const BADGE = {pendiente: ['secondary', 'Pendiente'], sincronizando: ['info', 'Enviando…'], revision: ['danger', 'Requiere revisión'], sincronizada: ['success', 'Sincronizada']};

    function filaCola(e) {
        const [color, texto] = BADGE[e.estado] || ['secondary', e.estado];
        const piezas = (e.detalle && e.detalle.length ? e.detalle : e.items)
            .map(i => `${esc(i.nombre || ('#' + i.id))} × ${i.cantidad}`).join(', ');
        const propia = e.usuario_id === USR.id;
        let acciones = '';
        if (e.estado === 'revision' && propia) {
            acciones = `<button class="btn btn-sm btn-outline-dark me-1" data-acc="reintentar" data-uuid="${esc(e.uuid)}"><i class="bi bi-arrow-repeat"></i> Reintentar</button>`
                + (USR.puedeDescartar ? `<button class="btn btn-sm btn-outline-danger" data-acc="descartar" data-uuid="${esc(e.uuid)}"><i class="bi bi-trash"></i> Descartar</button>` : '');
        }
        const extra = e.estado === 'sincronizada'
            ? `<div class="small text-success">Ticket #${String(e.numero_ticket || 0).padStart(6, '0')}${e.duplicada ? ' (ya estaba registrada)' : ''}</div>`
            : (e.estado === 'revision' ? `<div class="small text-danger fw-semibold">${esc(e.motivo)}</div>` : '')
            + (!propia ? `<div class="small text-muted">De ${esc(e.usuario_nombre)}: solo ${esc(e.usuario_nombre)} puede sincronizarla.</div>` : '');
        return `<tr><td class="text-nowrap"><b>${esc(e.ticket)}</b><div class="small text-muted">${esc(hora(e.creado))}</div></td>`
            + `<td>${piezas}${extra}</td><td class="text-end text-nowrap">${dinero(e.total)}</td>`
            + `<td><span class="badge text-bg-${color}">${esc(texto)}</span></td><td class="text-end text-nowrap">${acciones}</td></tr>`;
    }

    async function pintarPanel() {
        if (!panel) return;
        let todas = [];
        try { todas = await listar(); } catch { /* sin IndexedDB */ }
        const aviso = [];
        if (estado.pausa) aviso.push(`<div class="alert alert-warning py-2 mb-2"><i class="bi bi-pause-circle"></i> ${esc(MSG_PAUSA[estado.pausa] || 'Sincronización en pausa.')}`
            + (estado.pausa === 'sesion' || estado.pausa === 'csrf' ? ` <a class="alert-link" target="_blank" rel="noopener" href="${esc(BASE_URL)}?c=auth&a=login">Iniciar sesión</a>` : '') + '</div>');
        if (sinConexion()) aviso.push('<div class="alert alert-info py-2 mb-2"><i class="bi bi-wifi-off"></i> Sin conexión con el servidor. Las ventas se guardan aquí y se suben solas al volver.</div>');
        panel.querySelector('#onixPanelAviso').innerHTML = aviso.join('');
        const filas = todas.slice().reverse();
        panel.querySelector('#onixPanelCuerpo').innerHTML = filas.length
            ? '<div class="table-responsive"><table class="table table-sm align-middle mb-0"><thead><tr><th>Comprobante</th><th>Piezas</th><th class="text-end">Total</th><th>Estado</th><th></th></tr></thead><tbody>'
              + filas.map(filaCola).join('') + '</tbody></table></div>'
            : '<div class="text-center text-muted py-4">No hay ventas guardadas en este equipo.</div>';
        const cuando = estado.catalogoEn ? ' · actualizado ' + new Date(estado.catalogoEn).toLocaleTimeString('es-SV', {timeZone: ZONA, hour: '2-digit', minute: '2-digit'}) : '';
        panel.querySelector('#onixPanelCatalogo').textContent = `Catálogo guardado en este equipo: ${estado.catalogo} productos${cuando}.`;
        const b = panel.querySelector('#onixPanelSync');
        b.disabled = estado.sincronizando;
    }

    async function alClicPanel(ev) {
        const sync = ev.target.closest('#onixPanelSync');
        if (sync) { await accionSync(); return; }
        const b = ev.target.closest('[data-acc]');
        if (!b) return;
        const uuid = b.dataset.uuid;
        if (b.dataset.acc === 'reintentar') { b.disabled = true; resumenToast(await reintentar(uuid)); pintarPanel(); }
        if (b.dataset.acc === 'descartar') await flujoDescartar(uuid);
    }

    async function flujoDescartar(uuid) {
        if (typeof Swal === 'undefined') return;
        const e = (await listar()).find(x => x.uuid === uuid);
        const r = await Swal.fire({
            icon: 'warning', title: 'Descartar esta venta',
            html: `El cliente ya se llevó las piezas de <b>${esc(e ? e.ticket : '')}</b>. Descartarla <b>no</b> devuelve el inventario ni el dinero: `
                + 'solo úsalo si la venta ya se corrigió por otro medio. Quedará anotado en la bitácora.',
            input: 'text', inputLabel: 'Motivo (mínimo 5 caracteres)', showCancelButton: true,
            confirmButtonText: 'Descartar', confirmButtonColor: '#dc3545', cancelButtonText: 'Cancelar',
            inputValidator: v => (v || '').trim().length < 5 ? 'Escribe al menos 5 caracteres.' : null,
        });
        if (!r.isConfirmed) return;
        const x = await descartar(uuid, r.value.trim());
        toast(x.ok ? 'success' : 'error', x.ok ? (x.yaRegistrada ? 'Esa venta sí estaba registrada: ticket #' + String(x.numero_ticket).padStart(6, '0') : 'Venta descartada y registrada en la bitácora.') : (x.msg || 'No se pudo descartar.'), 7000);
        pintarPanel();
    }

    function resumenToast(r) {
        if (!r || r.ocupado) return;
        if (r.red) toast('info', 'Sin conexión con el servidor: se reintentará solo.', 4000);
    }

    async function accionSync() {
        const r = await sincronizar({manual: true});
        if (r && !r.ocupado && !r.enviadas && !r.duplicadas && !r.revision && !r.pausa && !r.red && !r.pendientes) toast('success', 'No hay ventas pendientes.', 2500);
        resumenToast(r);
        pintarPanel();
    }

    function abrirPanel() {
        crearPanel();
        pintarPanel();
        if (typeof bootstrap !== 'undefined') bootstrap.Modal.getOrCreateInstance(panel).show();
    }

    /* =============================================================== protección al cerrar sesión */
    document.addEventListener('submit', async ev => {
        const f = ev.target;
        if (!(f instanceof HTMLFormElement) || !/[?&]a=logout(&|$)/.test(f.getAttribute('action') || '')) return;
        if (f.dataset.confirmado === '1') return;
        ev.preventDefault();
        const salir = async () => {
            f.dataset.confirmado = '1';
            if (window.onixPurgarPaginas) await window.onixPurgarPaginas();
            HTMLFormElement.prototype.submit.call(f);   // submit() no dispara este evento otra vez
        };
        let c = {pendientes: 0, revision: 0};
        try { c = await contar(); } catch { /* sin IndexedDB: nada que proteger */ }
        for (let vuelta = 0; vuelta < 5; vuelta++) {
            const n = c.pendientes + c.revision;
            if (!n) return salir();
            if (typeof Swal === 'undefined') { if (confirm(`Hay ${n} venta(s) sin subir al servidor. ¿Cerrar sesión de todos modos?`)) return salir(); return; }
            const r = await Swal.fire({
                icon: 'warning', title: plural(n, 'venta sin subir', 'ventas sin subir') + ' al servidor',
                html: 'Si cierras sesión ahora, ' + (n === 1 ? 'esa venta queda' : 'esas ventas quedan') + ' <b>solo en este equipo</b>: no aparecerán en los reportes, '
                    + 'el inventario ni la caja hasta que vuelvas a entrar <b>con este mismo usuario</b> y se sincronicen. '
                    + 'Si se borran los datos del navegador, se pierden.' + (c.revision ? `<br><br><b>${plural(c.revision, 'requiere', 'requieren')} revisión.</b>` : ''),
                showDenyButton: true, showCancelButton: true, confirmButtonText: 'Sincronizar ahora',
                denyButtonText: 'Cerrar sesión de todos modos', cancelButtonText: 'Cancelar',
                confirmButtonColor: '#b8913a', denyButtonColor: '#dc3545', focusCancel: true, allowOutsideClick: false,
            });
            if (r.isDenied) return salir();
            if (!r.isConfirmed) return;                   // cancelar: se queda en el sistema
            await sincronizar({manual: true});
            c = await contar();
        }
    });

    /* =============================================================== eventos y arranque */
    document.addEventListener('click', ev => {
        if (ev.target.closest('[data-onix-abrir]')) { abrirPanel(); return; }
        if (ev.target.closest('[data-onix-sync]')) { accionSync(); }
    });

    document.addEventListener('onix:red', ev => servidor(!!(ev.detail && ev.detail.ok)));   // api() de app.js también informa
    addEventListener('online', () => { pintar(); proximoIntento = 0; setTimeout(() => { if (estado.pend) sincronizar(); sincronizarCatalogo(); }, 800); });
    addEventListener('offline', pintar);
    document.addEventListener('visibilitychange', () => { if (!document.hidden && estado.pend && navigator.onLine) sincronizar(); });

    setInterval(() => {
        if (document.hidden || !navigator.onLine) return;
        if (!estado.servidor) { pedir('auth', 'estadoSesion').then(() => { if (estado.servidor && estado.pend) sincronizar(); }); return; }   // ¿volvió el servidor?
        if (estado.sincronizando || !estado.pend) return;
        if (estado.pausa) { if (Date.now() >= proximoChequeoPausa) sincronizar(); return; }   // ¿ya inició sesión en otra pestaña?
        if (Date.now() >= proximoIntento) sincronizar();
    }, TICK_MS);

    async function iniciar() {
        try { await abrirDb(); } catch { estado.idb = false; pintar(); return; }
        await recontar();
        try { estado.catalogo = await contarCatalogo(); estado.catalogoEn = (await metaGet('catalogo_ultima')) || 0; } catch { /* nada */ }
        pintar();
        if (navigator.storage && navigator.storage.persist) navigator.storage.persist().catch(() => null);   // evita que el navegador borre la cola por falta de espacio
        if (USR.puedeVender) setTimeout(() => { sincronizarCatalogo(); if (estado.pend) sincronizar(); }, 1200);
    }
    if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', iniciar); else iniciar();

    window.Offline = {
        guardarCatalogo, buscarCatalogo, guardarVentaPendiente, sincronizar, sincronizarCatalogo,
        contar, listar, reintentar, descartar, refrescarSesion, abrirPanel,
        sinConexion, estado: () => ({...estado, sinConexion: sinConexion()}),
    };
})();
