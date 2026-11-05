<?php
require_once __DIR__ . '/../models/Producto.php';
require_once __DIR__ . '/../models/Categoria.php';

class ProductoController extends Controller
{
    /** Dueña: CRUD completo. Cajero: solo consulta de existencias. */
    public function index(): void
    {
        $this->requireRol(['duena', 'cajero']);
        $q   = trim($_GET['q'] ?? '');
        $cat = (int)($_GET['cat'] ?? 0);
        $this->render('productos/index', [
            'titulo'     => 'Inventario',
            'productos'  => Producto::listar($q, $cat),
            'categorias' => Categoria::listar(),
            'q' => $q, 'cat' => $cat,
            'puedeEditar' => $this->usuario()['rol'] === 'duena',
        ]);
    }

    public function guardar(): void
    {
        $this->requireRol(['duena'], true);
        $this->verificarCsrf();
        $d = $this->entrada();

        $id     = (int)($d['id_producto'] ?? 0);
        $codigo = strtoupper(trim($d['codigo'] ?? ''));
        $nombre = trim($d['nombre'] ?? '');
        $cat    = (int)($d['id_categoria'] ?? 0);
        $unidad = $d['unidad_peso'] ?? 'gramos';
        $peso   = (float)($d['peso'] ?? 0);
        $costo  = (float)($d['costo'] ?? 0);
        $precio = (float)($d['precio_venta'] ?? 0);
        $stock  = (int)($d['stock'] ?? 0);

        if ($codigo === '' || $nombre === '' || $cat <= 0)
            $this->json(['ok' => false, 'msg' => 'Código, nombre y categoría son obligatorios.']);
        if (!in_array($unidad, ['gramos', 'kilates'], true))
            $this->json(['ok' => false, 'msg' => 'Unidad de peso no válida.']);
        if ($peso < 0 || $costo < 0 || $precio <= 0 || $stock < 0)
            $this->json(['ok' => false, 'msg' => 'Revisa peso, costo, precio y stock (no pueden ser negativos; el precio debe ser mayor a 0).']);
        if (Producto::codigoExiste($codigo, $id))
            $this->json(['ok' => false, 'msg' => 'Ya existe un producto con ese código.']);

        $datos = ['codigo' => $codigo, 'nombre' => $nombre, 'descripcion' => trim($d['descripcion'] ?? ''),
                  'id_categoria' => $cat, 'peso' => $peso, 'unidad_peso' => $unidad,
                  'costo' => $costo, 'precio_venta' => $precio, 'stock' => $stock];
        $id > 0 ? Producto::actualizar($id, $datos) : Producto::crear($datos);
        $this->json(['ok' => true, 'msg' => 'Producto guardado correctamente.']);
    }

    public function eliminar(): void
    {
        $this->requireRol(['duena'], true);
        $this->verificarCsrf();
        $d = $this->entrada();
        Producto::quitar((int)($d['id_producto'] ?? 0));
        $this->json(['ok' => true, 'msg' => 'Producto eliminado del inventario.']);
    }
}
