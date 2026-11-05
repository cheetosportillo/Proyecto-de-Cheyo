<?php
require_once __DIR__ . '/../models/Compra.php';
require_once __DIR__ . '/../models/Proveedor.php';
require_once __DIR__ . '/../models/Producto.php';

class CompraController extends Controller
{
    /** Pestaña "Compras / Proveedores" (solo dueña). */
    public function index(): void
    {
        $this->requireRol(['duena']);
        $this->render('compras/index', [
            'titulo'      => 'Compras y Proveedores',
            'compras'     => Compra::listar(),
            'proveedores' => Proveedor::listar(),
            'productos'   => Producto::listar('', 0),
        ]);
    }

    public function guardar(): void
    {
        $this->requireRol(['duena'], true);
        $this->verificarCsrf();
        $d = $this->entrada();

        $idProv = (int)($d['id_proveedor'] ?? 0);
        $items = [];
        foreach (($d['items'] ?? []) as $it) {
            $cant = (int)($it['cantidad'] ?? 0);
            $cost = (float)($it['costo'] ?? 0);
            $id   = (int)($it['id'] ?? 0);
            if ($id <= 0 || $cant <= 0 || $cost < 0)
                $this->json(['ok' => false, 'msg' => 'Hay productos con cantidad o costo no válido.']);
            $items[] = ['id' => $id, 'cantidad' => $cant, 'costo' => $cost];
        }
        if ($idProv <= 0) $this->json(['ok' => false, 'msg' => 'Selecciona un proveedor.']);
        if (!$items)      $this->json(['ok' => false, 'msg' => 'Agrega al menos un producto a la compra.']);

        try {
            $id = Compra::crear($this->usuario()['id'], $idProv, trim($d['observacion'] ?? ''), $items);
            $this->json(['ok' => true, 'msg' => "Compra #$id registrada. El stock fue actualizado."]);
        } catch (RuntimeException $e) {
            $this->json(['ok' => false, 'msg' => $e->getMessage()]);
        }
    }

    public function detalle(): void
    {
        $this->requireRol(['duena'], true);
        $this->json(['ok' => true, 'items' => Compra::detalle((int)($_GET['id'] ?? 0))]);
    }
}
