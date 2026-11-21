<?php
require_once __DIR__ . '/../models/Kardex.php';
require_once __DIR__ . '/../models/Bitacora.php';
require_once __DIR__ . '/../models/Caja.php';
require_once __DIR__ . '/../models/Venta.php';
require_once __DIR__ . '/../models/Producto.php';
require_once __DIR__ . '/../models/Categoria.php';

class VentaController extends Controller
{
    /** ¿Ve todo el historial (dashboard.ver) o solo sus propias ventas (ventas.cobrar)? */
    private function idFiltroUsuario(): ?int
    {
        return $this->puede('dashboard.ver') ? null : $this->usuario()['id'];
    }

    /** Pantalla de caja + historial. */
    public function index(): void
    {
        $this->requirePermiso('ventas.cobrar');
        $h = Venta::historial($_GET['desde'] ?? date('Y-m-d'), $_GET['hasta'] ?? date('Y-m-d'), $this->idFiltroUsuario(), max(1, (int)($_GET['p'] ?? 1)));
        $this->render('ventas/index', [
            'titulo'     => 'Ventas (Caja)',
            'categorias' => Categoria::listar(true),
            'h'          => $h,
            'desde' => $h['desde'], 'hasta' => $h['hasta'],
            'tabHistorial' => isset($_GET['desde']),
            'cajaAbierta'  => Caja::abierta() !== null,
            'verTodas'     => $this->idFiltroUsuario() === null,
            'puedeAnular'  => $this->puede('ventas.anular'),
            'puedeReimprimir' => $this->puede('ventas.reimprimir'),
        ]);
    }

    public function buscar(): void
    {
        $this->requirePermiso('ventas.cobrar', true);
        $this->json(['ok' => true, 'items' => Producto::paraVenta(trim($_GET['q'] ?? ''), (int)($_GET['cat'] ?? 0))]);
    }

    public function cobrar(): void
    {
        $this->requirePermiso('ventas.cobrar', true);
        $this->verificarCsrf();
        $d = $this->entrada();

        $items = [];
        foreach (($d['items'] ?? []) as $it) {
            $id = (int)($it['id'] ?? 0);
            $cant = (int)($it['cantidad'] ?? 0);
            if ($id <= 0 || $cant <= 0 || $cant > 100000) $this->json(['ok' => false, 'msg' => 'Cantidad no válida en el carrito.']);
            $items[$id] = ['id' => $id, 'cantidad' => ($items[$id]['cantidad'] ?? 0) + $cant];
        }
        if (!$items) $this->json(['ok' => false, 'msg' => 'El carrito está vacío.']);
        $efectivo = $d['efectivo'] ?? 0;
        if (!is_numeric($efectivo) || (float)$efectivo < 0 || (float)$efectivo > 99999999) $this->json(['ok' => false, 'msg' => 'Efectivo no válido.']);

        try {
            $r = Venta::crear($this->usuario()['id'], array_values($items), a_centavos($efectivo), (string)($d['uuid_operacion'] ?? ''));
            $v = Venta::obtener($r['id_venta']);
            $this->json(['ok' => true, 'id_venta' => $r['id_venta'], 'duplicada' => $r['duplicada'],
                         'numero_ticket' => (int)$v['numero_ticket'], 'total' => (float)$v['total'], 'cambio' => (float)$v['cambio'],
                         'ticket' => url('venta', 'ticket', ['id' => $r['id_venta']])]);
        } catch (RuntimeException $e) {
            $this->json(['ok' => false, 'msg' => $e->getMessage()]);
        }
    }

    /** Anulación (ventas.anular). */
    public function anular(): void
    {
        $this->requirePermiso('ventas.anular', true);
        $this->verificarCsrf();
        $d = $this->entrada();
        try { Venta::anular((int)($d['id_venta'] ?? 0), (string)($d['motivo'] ?? ''), $this->usuario()['id']); }
        catch (RuntimeException $e) { $this->json(['ok' => false, 'msg' => $e->getMessage()]); }
        $this->json(['ok' => true, 'msg' => 'Venta anulada. El inventario y la caja fueron ajustados.']);
    }

    /** Modal «Ver detalle» (JSON). El cajero solo ve sus propias ventas. */
    public function detalle(): void
    {
        $this->requirePermiso('ventas.cobrar', true);
        $v = Venta::obtener((int)($_GET['id'] ?? 0));
        if (!$v || ($this->idFiltroUsuario() !== null && (int)$v['id_usuario'] !== $this->idFiltroUsuario()))
            $this->json(['ok' => false, 'msg' => 'Venta no encontrada.'], 404);
        $this->json(['ok' => true, 'venta' => $v, 'items' => Venta::detalle((int)$v['id_venta']),
                     'ticket' => url('venta', 'ticket', ['id' => (int)$v['id_venta']])]);
    }

    /** Ticket: la primera impresión es el original; las siguientes salen como COPIA (ventas.reimprimir). */
    public function ticket(): void
    {
        $this->requirePermiso('ventas.cobrar');
        $venta = Venta::obtener((int)($_GET['id'] ?? 0));
        if (!$venta) { http_response_code(404); exit('Venta no encontrada'); }
        // Quien no es admin (sin dashboard.ver) solo imprime sus propias ventas
        if ($this->idFiltroUsuario() !== null && (int)$venta['id_usuario'] !== $this->idFiltroUsuario()) {
            http_response_code(403); exit('Sin permiso');
        }
        $copia = (int)$venta['impresiones'] > 0;
        if ($copia) $this->requirePermiso('ventas.reimprimir');
        Venta::marcarImpresa((int)$venta['id_venta']);
        $this->render('ventas/ticket', ['venta' => $venta, 'detalle' => Venta::detalle((int)$venta['id_venta']), 'copia' => $copia], false);
    }
}
