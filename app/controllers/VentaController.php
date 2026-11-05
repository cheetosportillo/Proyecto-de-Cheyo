<?php
require_once __DIR__ . '/../models/Venta.php';
require_once __DIR__ . '/../models/Producto.php';
require_once __DIR__ . '/../models/Categoria.php';

class VentaController extends Controller
{
    /** Pantalla de caja + historial. */
    public function index(): void
    {
        $this->requireRol(['duena', 'cajero']);
        $desde = $_GET['desde'] ?? date('Y-m-d');
        $hasta = $_GET['hasta'] ?? date('Y-m-d');
        if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $desde)) $desde = date('Y-m-d');
        if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $hasta)) $hasta = date('Y-m-d');

        $esDuena = $this->usuario()['rol'] === 'duena';
        $hist = Venta::historial($desde, $hasta, $esDuena ? null : $this->usuario()['id']);
        $this->render('ventas/index', [
            'titulo'     => 'Ventas (Caja)',
            'categorias' => Categoria::listar(),
            'historial'  => $hist,
            'totalPeriodo' => array_sum(array_column($hist, 'total')),
            'desde' => $desde, 'hasta' => $hasta,
            'tabHistorial' => isset($_GET['desde']),
        ]);
    }

    /** Búsqueda para la caja (JSON). */
    public function buscar(): void
    {
        $this->requireRol(['duena', 'cajero'], true);
        $this->json(['ok' => true, 'items' => Producto::paraVenta(trim($_GET['q'] ?? ''), (int)($_GET['cat'] ?? 0))]);
    }

    public function cobrar(): void
    {
        $this->requireRol(['duena', 'cajero'], true);
        $this->verificarCsrf();
        $d = $this->entrada();

        // Agrupa líneas repetidas del mismo producto
        $items = [];
        foreach (($d['items'] ?? []) as $it) {
            $id = (int)($it['id'] ?? 0);
            $cant = (int)($it['cantidad'] ?? 0);
            if ($id <= 0 || $cant <= 0) $this->json(['ok' => false, 'msg' => 'Cantidad no válida en el carrito.']);
            $items[$id] = ['id' => $id, 'cantidad' => ($items[$id]['cantidad'] ?? 0) + $cant];
        }
        if (!$items) $this->json(['ok' => false, 'msg' => 'El carrito está vacío.']);

        try {
            $id = Venta::crear($this->usuario()['id'], array_values($items), (float)($d['efectivo'] ?? 0));
            $v = Venta::obtener($id);
            $this->json(['ok' => true, 'id_venta' => $id, 'total' => (float)$v['total'], 'cambio' => (float)$v['cambio'],
                         'ticket' => url('venta', 'ticket', ['id' => $id])]);
        } catch (RuntimeException $e) {
            $this->json(['ok' => false, 'msg' => $e->getMessage()]);
        }
    }

    /** Ticket imprimible. */
    public function ticket(): void
    {
        $this->requireRol(['duena', 'cajero']);
        $venta = Venta::obtener((int)($_GET['id'] ?? 0));
        if (!$venta) { http_response_code(404); exit('Venta no encontrada'); }
        // El cajero solo puede reimprimir sus propias ventas
        if ($this->usuario()['rol'] === 'cajero' && (int)$venta['id_usuario'] !== $this->usuario()['id']) {
            http_response_code(403); exit('Sin permiso');
        }
        $this->render('ventas/ticket', ['venta' => $venta, 'detalle' => Venta::detalle((int)$venta['id_venta'])], false);
    }
}
