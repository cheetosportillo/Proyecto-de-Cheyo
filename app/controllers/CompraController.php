<?php
require_once __DIR__ . '/../models/Kardex.php';
require_once __DIR__ . '/../models/Bitacora.php';
require_once __DIR__ . '/../models/Compra.php';
require_once __DIR__ . '/../models/Proveedor.php';
require_once __DIR__ . '/../models/Producto.php';

/** Compras y proveedores: protegido con compras.gestionar. */
class CompraController extends Controller
{
    public function index(): void
    {
        $this->requirePermiso('compras.gestionar');
        $pag = Compra::listar(max(1, (int)($_GET['p'] ?? 1)));
        $this->render('compras/index', [
            'titulo'      => 'Compras y Proveedores',
            'compras'     => $pag['filas'], 'pag' => $pag,
            'proveedores' => Proveedor::listar(false),
            'productos'   => Producto::listar('', 0),
        ]);
    }

    public function guardar(): void
    {
        $this->requirePermiso('compras.gestionar', true);
        $this->verificarCsrf();
        $d = $this->entrada();

        $idProv = (int)($d['id_proveedor'] ?? 0);
        $items = [];
        foreach (($d['items'] ?? []) as $it) {
            $cant = (int)($it['cantidad'] ?? 0);
            $cost = (float)($it['costo'] ?? 0);
            $id   = (int)($it['id'] ?? 0);
            if ($id <= 0 || $cant <= 0 || $cant > 100000 || $cost < 0 || $cost > 9999999.99)
                $this->json(['ok' => false, 'msg' => 'Hay productos con cantidad o costo no válido.']);
            $items[] = ['id' => $id, 'cantidad' => $cant, 'costo' => $cost];
        }
        if ($idProv <= 0) $this->json(['ok' => false, 'msg' => 'Selecciona un proveedor.']);
        if (!$items)      $this->json(['ok' => false, 'msg' => 'Agrega al menos un producto a la compra.']);
        $factura = trim($d['numero_factura'] ?? '');
        if (largo_texto($factura) > 40) $this->json(['ok' => false, 'msg' => 'El N.º de factura admite máximo 40 caracteres.']);

        try {
            $r = Compra::crear($this->usuario()['id'], $idProv, trim($d['observacion'] ?? ''), $items, (string)($d['uuid_operacion'] ?? ''), $factura);
            $this->json(['ok' => true, 'msg' => $r['duplicada']
                ? "Esta compra ya estaba registrada (#{$r['id_compra']}); no se duplicó."
                : "Compra #{$r['id_compra']} registrada. El stock y el costo promedio fueron actualizados."]);
        } catch (RuntimeException $e) {
            $this->json(['ok' => false, 'msg' => $e->getMessage()]);
        }
    }

    public function anular(): void
    {
        $this->requirePermiso('compras.gestionar', true);
        $this->verificarCsrf();
        $d = $this->entrada();
        try { Compra::anular((int)($d['id_compra'] ?? 0), (string)($d['motivo'] ?? ''), $this->usuario()['id']); }
        catch (RuntimeException $e) { $this->json(['ok' => false, 'msg' => $e->getMessage()]); }
        $this->json(['ok' => true, 'msg' => 'Compra anulada. El stock fue revertido en el Kardex.']);
    }

    public function detalle(): void
    {
        $this->requirePermiso('compras.gestionar', true);
        $id = (int)($_GET['id'] ?? 0);
        $c = Compra::obtener($id);
        if (!$c) $this->json(['ok' => false, 'msg' => 'La compra no existe.'], 404);
        $this->json(['ok' => true, 'compra' => $c, 'items' => Compra::detalle($id)]);
    }
}
