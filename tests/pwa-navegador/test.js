const {chromium} = require('playwright');
const {S, start} = require('./mock.js');
let ok = 0, fail = 0;
const t = (n, c, extra='') => { if (c) { ok++; console.log('  ✔', n); } else { fail++; console.log('  ✘', n, extra); } };
const sleep = ms => new Promise(r => setTimeout(r, ms));
(async () => {
  const {server, port} = await start();
  const B = `http://localhost:${port}/p/`;
  const browser = await chromium.launch({args:['--no-sandbox']});
  const ctx = await browser.newContext({serviceWorkers:'allow'});
  const page = await ctx.newPage();
  const errs = []; page.on('pageerror', e => errs.push(e.message));
  const estadoTxt = () => page.locator('[data-onix-estado] .onix-texto').first().innerText();
  const cola = () => page.evaluate(() => Offline.listar());
  const waitFor = async (fn, ms = 8000) => { const t0 = Date.now(); while (Date.now() - t0 < ms) { try { if (await fn()) return true; } catch {} await sleep(150); } return false; };
  const cachesKeys = () => page.evaluate(async () => { const o = {}; for (const k of await caches.keys()) o[k] = (await (await caches.open(k)).keys()).map(r => r.url); return o; });

  console.log('\n▶ Instalación, App Shell y catálogo local');
  await page.goto(B + 'index.php?c=venta&a=index');
  t('SW registrado y controlando la página (scope en subcarpeta /p/)', await waitFor(() => page.evaluate(() => !!navigator.serviceWorker.controller)));
  t('copia sin historial del POS guardada (a=pos)', await waitFor(async () => (Object.entries(await cachesKeys()).find(([k]) => k.startsWith('onix-paginas'))?.[1] || []).some(u => u.includes('a=pos'))));
  const ks = await cachesKeys();
  const est = Object.entries(ks).find(([k]) => k.startsWith('onix-estatico'))?.[1] || [];
  t('App Shell precacheado (bootstrap, icons, sweetalert2, js propios, manifest, íconos)', ['bootstrap.min.css','bootstrap-icons.min.css','sweetalert2.all.min.js','offline.js','ventas.js','manifest.json','icon-192.png','bootstrap-icons.woff2'].every(f => est.some(u => u.includes(f))), JSON.stringify(est.map(u=>u.split('/').pop())));
  t('catálogo guardado en IndexedDB (2 productos)', await waitFor(async () => (await page.evaluate(() => Offline.buscarCatalogo('', 0))).length === 2));
  const man = await (await page.request.get(B + 'manifest.json')).json();
  t('manifest enlazado y válido', await page.locator('link[rel=manifest]').count() === 1 && man.display === 'standalone');
  t('indicador: En línea', (await estadoTxt()) === 'En línea', await estadoTxt());

  console.log('\n▶ Venta sin conexión (red caída) y reapertura offline');
  S.down = true; await ctx.setOffline(true);
  await page.reload();
  t('recarga sin internet sirve el POS desde la copia (SW)', (await page.title()) === 'POS-SHELL', await page.title());
  t('estáticos offline: Swal/Bootstrap cargaron desde caché', await page.evaluate(() => typeof Swal !== 'undefined' && typeof bootstrap !== 'undefined' && typeof Offline !== 'undefined'));
  t('indicador: Sin conexión (amarillo)', (await estadoTxt()).startsWith('Sin conexión'), await estadoTxt());
  await page.fill('#buscador', 'anillo'); await sleep(600);
  t('búsqueda sin internet desde IndexedDB (sin tildes/mayúsculas)', (await page.locator('#resultados button').count()) === 1);
  await page.locator('#resultados button').first().click();
  await page.fill('#efectivo', '50'); await sleep(100);
  await page.click('#btnCobrar');
  await page.waitForSelector('.swal2-title');
  t('aviso «Venta guardada localmente»', (await page.locator('.swal2-title').innerText()).includes('guardada localmente'));
  t('el aviso menciona pendiente de sincronizar y TEMP-', /pendiente de sincronizar/i.test(await page.locator('.swal2-html-container').innerText()) && /TEMP-/.test(await page.locator('.swal2-html-container').innerText()));
  await page.click('.swal2-cancel');
  let q = await cola();
  t('cola: 1 venta pendiente con uuid v4', q.length === 1 && q[0].estado === 'pendiente' && /^[0-9a-f-]{36}$/.test(q[0].uuid), JSON.stringify(q));
  const uuid1 = q[0].uuid;
  t('indicador: Sin conexión · 1 pendiente', (await estadoTxt()) === 'Sin conexión · 1 pendiente', await estadoTxt());
  t('stock local reservado: ya no se puede revender más de lo que hay', (await page.evaluate(() => Offline.buscarCatalogo('anillo', 0)))[0].stock === 4);
  const cat = await ctx.newPage(); await cat.goto(B + 'index.php?c=caja&a=index');
  t('otra pantalla sin internet: aviso «Sin conexión» con enlace al POS', (await cat.content()).includes('Ir a Ventas (POS)'));
  await cat.close();

  console.log('\n▶ Logout con ventas pendientes');
  await page.click('#btnLogout'); await page.waitForSelector('.swal2-title');
  t('alerta fuerte al cerrar sesión', /sin subir/.test(await page.locator('.swal2-title').innerText()));
  t('advierte que solo están en este equipo', /solo en este equipo/.test(await page.locator('.swal2-html-container').innerText()));
  await page.click('.swal2-cancel'); await sleep(300);
  t('cancelar deja la sesión (no navegó)', page.url().includes('c=venta') && !S.loggedOut);
  t('cancelar NO borra la copia offline del POS', Object.values(await cachesKeys()).flat().some(u => u.includes('a=pos')));

  console.log('\n▶ Vuelve la conexión: sincronización automática, FIFO e idempotencia');
  S.down = false; await ctx.setOffline(false);
  t('sincroniza sola al volver (venta pasa a sincronizada con ticket real)', await waitFor(async () => (await cola())[0]?.estado === 'sincronizada', 12000), JSON.stringify(await cola()));
  q = await cola();
  t('ticket real asignado y TEMP conservado como referencia', q[0].numero_ticket === 1 && q[0].ticket.startsWith('TEMP-'));
  t('CSRF renovado antes de enviar (la página traía uno viejo)', (await page.evaluate(() => CSRF)) === 'CSRF1');
  t('indicador vuelve a En línea', await waitFor(async () => (await estadoTxt()) === 'En línea'));

  console.log('\n▶ Servidor caído pero con wifi (navigator.onLine = true): igual se guarda');
  S.down = true;
  await page.locator('#buscador').fill(''); await sleep(500);
  await page.fill('#buscador', 'collar'); await sleep(700);
  await page.locator('#resultados button').first().click(); await page.fill('#efectivo', '20'); await sleep(100);
  t('navigator.onLine sigue true', await page.evaluate(() => navigator.onLine));
  await page.click('#btnCobrar'); await page.waitForSelector('.swal2-title');
  t('se guardó localmente', (await page.locator('.swal2-title').innerText()).includes('guardada localmente'));
  await page.click('.swal2-cancel');
  q = await cola(); const uuid2 = q.find(x => x.estado === 'pendiente')?.uuid;
  t('2.ª venta en cola', !!uuid2);
  t('indicador refleja servidor caído', await waitFor(async () => (await estadoTxt()).startsWith('Sin conexión'), 4000), await estadoTxt());

  console.log('\n▶ Sesión vencida: se pausa y NO se borra nada');
  S.down = false; S.session = false;
  const r1 = await page.evaluate(() => Offline.sincronizar({manual: true}));
  t('pausa por sesión', r1.pausa === 'sesion', JSON.stringify(r1));
  q = await cola();
  t('la venta sigue pendiente en IndexedDB', q.find(x => x.uuid === uuid2)?.estado === 'pendiente');
  t('indicador: sincronización en pausa · 1 pendiente', (await estadoTxt()) === 'Sincronización en pausa · 1 pendiente', await estadoTxt());
  t('no hubo POST de venta con sesión vencida', !S.log.slice(-6).some(l => l.startsWith('POST sincronizar') && l.includes(uuid2.slice(0,8))));
  S.session = true; S.sync401 = true;
  const r2 = await page.evaluate(() => Offline.sincronizar({manual: true}));
  t('401 durante el envío también pausa (sin perder la venta)', r2.pausa === 'sesion' && (await cola()).find(x => x.uuid === uuid2).estado === 'pendiente', JSON.stringify(r2));
  S.sync401 = false;

  console.log('\n▶ Error 5xx: sigue pendiente (FIFO, sin saltarse el orden)');
  S.sync = '500';
  const r3 = await page.evaluate(() => Offline.sincronizar({manual: true}));
  t('5xx -> red=true y pendiente', r3.red === true && (await cola()).find(x => x.uuid === uuid2).estado === 'pendiente', JSON.stringify(r3));

  console.log('\n▶ Conflicto de negocio: «Requiere revisión» con el motivo exacto');
  S.sync = 'stock';
  const r4 = await page.evaluate(() => Offline.sincronizar({manual: true}));
  q = await cola(); const e2 = q.find(x => x.uuid === uuid2);
  t('queda en revisión', r4.revision === 1 && e2.estado === 'revision', JSON.stringify(r4));
  t('motivo exacto del servidor', e2.motivo === 'Stock insuficiente de «Anillo Oro» (disponible: 0).' && e2.codigo === 'stock_insuficiente', e2.motivo);
  t('indicador muestra venta por revisar', (await page.locator('.onix-estado__rev').first().innerText()).includes('1 venta requiere revisión'));
  await page.click('[data-onix-abrir]'); await page.waitForSelector('#onixPanelCola.show');
  const panelTxt = await page.locator('#onixPanelCola').innerText();
  t('panel de la cola muestra motivo y botones Reintentar/Descartar', panelTxt.includes('Stock insuficiente de «Anillo Oro»') && panelTxt.includes('Reintentar') && panelTxt.includes('Descartar'));
  S.sync = 'ok';
  await page.click('#onixPanelCola [data-acc=reintentar]');
  t('reintentar tras resolver el problema la sincroniza', await waitFor(async () => (await cola()).find(x => x.uuid === uuid2)?.estado === 'sincronizada', 8000));
  t('tickets en orden de envío (1 y luego 2)', (await cola()).map(x => x.numero_ticket).join() === '1,2', JSON.stringify((await cola()).map(x => x.numero_ticket)));
  await page.click('#onixPanelCola .modal-footer [data-bs-dismiss=modal]'); await page.waitForSelector('#onixPanelCola', {state: 'hidden'});

  console.log('\n▶ Reenvío del mismo uuid (timeout previo): duplicada, no duplica');
  const dup = await page.evaluate(async uuid => {
    await Offline.guardarVentaPendiente({uuid_operacion: crypto.randomUUID(), efectivo: 50, total: 25, items: [{id: 1, cantidad: 1}], detalle: []});
    const e = (await Offline.listar()).find(x => x.estado === 'pendiente');
    return e.uuid; }, uuid1);
  S.vendidos.set(dup, 99);                                   // el servidor SÍ la había registrado antes del timeout
  const r5 = await page.evaluate(() => Offline.sincronizar({manual: true}));
  t('detectada como duplicada con su ticket real', r5.duplicadas === 1 && (await cola()).find(x => x.uuid === dup).numero_ticket === 99, JSON.stringify(r5));

  console.log('\n▶ Nada sensible en caché + limpieza al cerrar sesión');
  const todas = Object.values(await cachesKeys()).flat();
  t('ninguna respuesta de api() cacheada (catalogo/buscar/sincronizar/cobrar/estadoSesion)', !todas.some(u => /a=(catalogo|buscar|sincronizar|cobrar|descartar|estadoSesion)/.test(u)), todas.join('\n'));
  t('solo se guarda a=pos (nunca a=index con historial)', todas.some(u => u.includes('a=pos')) && !todas.some(u => u.includes('a=index')));
  await page.click('#btnLogout');
  await page.waitForURL(/a=login/);
  t('sin pendientes: cierra sesión directo', S.loggedOut);
  t('cerrar sesión borra la copia offline del POS', await waitFor(async () => !Object.values(await cachesKeys()).flat().some(u => u.includes('a=pos'))));

  t('sin errores JS no capturados', errs.length === 0, errs.join(' | '));
  console.log(`\n  Resultado: ${ok} ok, ${fail} fallidas`);
  await browser.close(); server.close(); process.exit(fail ? 1 : 0);
})().catch(e => { console.error('ERROR DE PRUEBA', e); process.exit(2); });
