// Servidor simulado: sirve el proyecto bajo /p/ y emula los endpoints PHP con el MISMO contrato que Sincronizacion.php.
const http = require('http'), fs = require('fs'), path = require('path'), url = require('url');
const ROOT = require('path').resolve(__dirname, '../..');
const MIME = {'.js':'text/javascript','.css':'text/css','.png':'image/png','.json':'application/json','.woff2':'font/woff2','.woff':'font/woff','.html':'text/html'};
const S = {session:true, down:false, sync:'ok', sync401:false, vendidos:new Map(), tickets:0, log:[], csrfOk:'CSRF1', loggedOut:false,
  prods:[{id_producto:1,codigo:'ORO-1',nombre:'Anillo Oro',peso:'4.000',unidad_peso:'gramos',precio_venta:'25.00',stock:5,id_categoria:1,categoria:'Oro',vigente:true,cambio:'2026-11-01 10:00:00'},
         {id_producto:2,codigo:'PLA-1',nombre:'Collar Plata',peso:'2.000',unidad_peso:'gramos',precio_venta:'12.50',stock:3,id_categoria:2,categoria:'Plata',vigente:true,cambio:'2026-11-01 10:00:00'}]};
const html = (titulo, shell) => `<!DOCTYPE html><html><head><meta charset="utf-8"><title>${titulo}</title>
<meta name="csrf-token" content="CSRF0"><link rel="manifest" href="/p/manifest.json"><link href="/p/public/vendor/bootstrap/css/bootstrap.min.css" rel="stylesheet">
<link href="/p/public/vendor/bootstrap-icons/font/bootstrap-icons.min.css" rel="stylesheet"><link href="/p/public/css/estilos.css" rel="stylesheet"></head><body>
<nav class="sidebar"><div data-onix-estado></div><form method="post" action="/p/index.php?c=auth&a=logout"><input type="hidden" name="csrf" value="CSRF0"><button id="btnLogout" type="submit">Cerrar sesión</button></form></nav>
<main><div id="avisoOffline" class="d-none">offline</div><select id="categoria"><option value="0">Todas</option></select><input id="buscador"><div id="resultados"></div>
<table><tbody id="carrito"></tbody></table><span id="total"></span><span id="cambio"></span><input id="efectivo" type="number"><button id="btnCobrar" disabled>Cobrar</button>
<button id="btnVaciar"></button><button id="btnExacto"></button><button id="btnLimpiarEf"></button>
<div id="modalVenta"><span id="vTitulo"></span><div id="vInfo"></div><table><tbody id="vBody"></tbody></table><button id="vAnular"></button><button id="vImprimir"></button></div></main>
<script src="/p/public/vendor/bootstrap/js/bootstrap.bundle.min.js"></script><script src="/p/public/vendor/sweetalert2/sweetalert2.all.min.js"></script>
<script>const BASE_URL="/p/index.php",MONEDA="$ ";const SESION_SEG=1800;const ONIX_USR={id:1,nombre:'Cajera',puedeVender:true,puedeDescartar:true};
const APP={cajaAbierta:true,puedeAnular:true,puedeReimprimir:true,usuarioId:1,negocio:'Joyería Onix',sinHistorial:${shell}};</script>
<script src="/p/public/js/app.js"></script><script src="/p/public/js/sesion.js"></script><script src="/p/public/js/offline.js"></script><script src="/p/public/js/pwa.js"></script>
<script src="/p/public/js/ventas.js"></script></body></html>`;
const json = (res, o, code=200) => { res.writeHead(code, {'Content-Type':'application/json','Cache-Control':'no-store'}); res.end(JSON.stringify(o)); };
function handler(req, res) {
  const u = url.parse(req.url, true), p = u.pathname;
  if (S.down) return req.socket.destroy();
  if (p === '/p/index.php') {
    const c = u.query.c, a = u.query.a || 'index';
    S.log.push(req.method + ' ' + c + '/' + a);
    const accept = req.headers.accept || '';
    if (c === 'auth' && a === 'logout') { S.loggedOut = true; res.writeHead(302, {Location:'/p/index.php?c=auth&a=login'}); return res.end(); }
    if (c === 'auth' && a === 'login') { res.writeHead(200, {'Content-Type':'text/html'}); return res.end('<title>LOGIN</title>'); }
    if (c === 'auth' && a === 'estadoSesion') return json(res, S.session ? {ok:true,activa:true,restante:1800,total:1800,csrf:S.csrfOk,usuario:S.userId||1} : {ok:true,activa:false});
    if (c === 'venta' && (a === 'index' || a === 'pos')) {
      if (!S.session) { res.writeHead(302, {Location:'/p/index.php?c=auth&a=login'}); return res.end(); }
      res.writeHead(200, {'Content-Type':'text/html; charset=utf-8'}); return res.end(html(a === 'pos' ? 'POS-SHELL' : 'POS-INDEX', a === 'pos'));
    }
    if (c === 'caja') { res.writeHead(200, {'Content-Type':'text/html'}); return res.end('<title>CAJA</title>'); }
    if (c === 'venta' && a === 'catalogo') {
      const comp = u.query.completo === '1', d = u.query.desde || '';
      const items = S.prods.filter(x => comp ? x.vigente : x.cambio >= d);
      return json(res, {ok:true, items, hay_mas:false, ultimo: items.length ? {cambio: items[items.length-1].cambio, id: items[items.length-1].id_producto} : null, ahora:'2026-11-22 10:00:05', cursor_sugerido:'2026-11-22 10:00:00', zona:'-06:00'});
    }
    if (c === 'venta' && a === 'buscar') return json(res, {ok:true, items: S.prods.filter(x => x.vigente && x.stock > 0)});
    if (['sincronizar','cobrar','descartar'].includes(a)) {
      let body = ''; req.on('data', d => body += d); req.on('end', () => {
        if (!S.session) return json(res, {ok:false,msg:'Tu sesión expiró.'}, 401);
        if ((req.headers['x-csrf-token'] || '') !== S.csrfOk) return json(res, {ok:false,msg:'Solicitud no válida. Recarga la página.'}, 419);
        const d = JSON.parse(body || '{}'); S.log.push('POST ' + a + ' ' + (d.uuid_operacion || '').slice(0,8));
        if (a === 'descartar') return json(res, {ok:true, estado:'descartada', msg:'Venta descartada y registrada en la bitácora.'});
        if (a === 'cobrar') { const t = ++S.tickets; S.vendidos.set(d.uuid_operacion, t); return json(res, {ok:true,id_venta:t,duplicada:false,numero_ticket:t,total:25,cambio:25,ticket:'/p/index.php?c=venta&a=ticket&id='+t}); }
        if (S.sync401) return json(res, {ok:false,msg:'Tu sesión expiró.'}, 401);
        if (S.sync === '500') { res.writeHead(500, {'Content-Type':'text/html'}); return res.end('<h1>Error</h1>'); }
        if (S.sync === 'stock') return json(res, {ok:false,estado:'conflicto',codigo:'stock_insuficiente',msg:'Stock insuficiente de «Anillo Oro» (disponible: 0).'});
        if (S.vendidos.has(d.uuid_operacion)) { const t = S.vendidos.get(d.uuid_operacion); return json(res, {ok:true,estado:'duplicada',id_venta:t,numero_ticket:t,total:25,cambio:25,ticket:'#'}); }
        const t = ++S.tickets; S.vendidos.set(d.uuid_operacion, t);
        return json(res, {ok:true,estado:'registrada',id_venta:t,numero_ticket:t,total:25,cambio:25,ticket:'/p/index.php?c=venta&a=ticket&id='+t});
      }); return;
    }
    return json(res, {ok:false,msg:'?'}, 404);
  }
  if (p === '/p/sw.js' || p === '/p/manifest.json' || p.startsWith('/p/public/')) {
    const f = path.join(ROOT, p.slice(3));
    if (!fs.existsSync(f)) { res.writeHead(404); return res.end(); }
    res.writeHead(200, {'Content-Type': MIME[path.extname(f)] || 'application/octet-stream', 'Cache-Control': p === '/p/sw.js' ? 'no-cache' : 'public, max-age=60'});
    return res.end(fs.readFileSync(f));
  }
  res.writeHead(404); res.end();
}
module.exports = {S, start: () => new Promise(r => { const s = http.createServer(handler).listen(0, 'localhost', () => r({server: s, port: s.address().port})); })};
