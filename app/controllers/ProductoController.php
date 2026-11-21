<?php
require_once __DIR__ . '/../models/Kardex.php';
require_once __DIR__ . '/../models/Producto.php';
require_once __DIR__ . '/../models/Categoria.php';
require_once __DIR__ . '/../models/Bitacora.php';

class ProductoController extends Controller
{
    /** inventario.gestionar: CRUD completo. inventario.consultar (Cajero): solo existencias, sin costos. */
    public function index(): void
    {
        $this->requirePermiso('inventario.consultar');
        $gestiona = $this->puede('inventario.gestionar');
        $q   = trim($_GET['q'] ?? '');
        $cat = (int)($_GET['cat'] ?? 0);
        $inactivos = $gestiona && !empty($_GET['inactivos']);

        $this->render('productos/index', [
            'titulo'      => 'Inventario',
            'productos'   => Producto::listar($q, $cat, !$inactivos, $gestiona),   // sin costo si no gestiona
            'categorias'  => Categoria::listar(true),
            'todasCategorias' => $gestiona ? Categoria::listar(false) : [],
            'q' => $q, 'cat' => $cat, 'inactivos' => $inactivos,
            'puedeEditar' => $gestiona,
            'puedeAjustar' => $this->puede('kardex.ajustar'),
        ]);
    }

    public function guardar(): void
    {
        $this->requirePermiso('inventario.gestionar', true);
        $this->verificarCsrf();
        $d = $this->entrada();

        $id     = (int)($d['id_producto'] ?? 0);
        $codigo = strtoupper(trim($d['codigo'] ?? ''));
        $nombre = trim($d['nombre'] ?? '');
        $cat    = (int)($d['id_categoria'] ?? 0);
        $peso   = (float)($d['peso'] ?? 0);
        $precio = (float)($d['precio_venta'] ?? 0);

        if ($codigo === '' || $nombre === '' || $cat <= 0) $this->json(['ok' => false, 'msg' => 'Código, nombre y categoría son obligatorios.']);
        if (!preg_match('/^[A-Z0-9._-]{1,30}$/', $codigo)) $this->json(['ok' => false, 'msg' => 'El código solo admite letras, números, punto, guion y guion bajo (máx. 30).']);
        if (largo_texto($nombre) > 120) $this->json(['ok' => false, 'msg' => 'El nombre admite máximo 120 caracteres.']);
        // N10: límites máximos
        if ($peso < 0 || $peso > 99999.999) $this->json(['ok' => false, 'msg' => 'El peso debe estar entre 0 y 99,999.999.']);
        if ($precio <= 0 || $precio > 9999999.99) $this->json(['ok' => false, 'msg' => 'El precio debe ser mayor a 0 y menor a 10,000,000.']);
        $c = Categoria::obtener($cat);
        if (!$c || (int)$c['estado'] !== 1) $this->json(['ok' => false, 'msg' => 'La categoría no es válida.']);
        if (Producto::codigoExiste($codigo, $id)) $this->json(['ok' => false, 'msg' => 'Ya existe un producto con ese código.']);

        if ($id > 0) {
            $actual = Producto::obtener($id);
            if (!$actual) $this->json(['ok' => false, 'msg' => 'El producto no existe.']);
            if (Kardex::tieneMovimientos($id)) {   // bloqueo permanente tras el primer movimiento
                if (abs((float)$actual['peso'] - $peso) > 0.0005)
                    $this->json(['ok' => false, 'msg' => 'El peso ya no se puede modificar: el producto tiene movimientos en el Kardex.']);
                if ((int)$actual['id_categoria'] !== $cat)
                    $this->json(['ok' => false, 'msg' => 'No se puede cambiar de categoría un producto con movimientos en el Kardex.']);
            }
        }

        $datos = ['codigo' => $codigo, 'nombre' => $nombre, 'descripcion' => trim($d['descripcion'] ?? ''), 'id_categoria' => $cat,
                  'peso' => number_format($peso, 3, '.', ''), 'precio_venta' => number_format($precio, 2, '.', '')];
        $id > 0 ? Producto::actualizar($id, $datos) : Producto::crear($datos);
        $this->json(['ok' => true, 'msg' => 'Producto guardado. El stock se carga desde Compras, Saldo inicial o Ajustes de inventario.']);
    }

    /** Botón «Generar código»: siguiente correlativo de la categoría (ORO-0001…). */
    public function codigo(): void
    {
        $this->requirePermiso('inventario.gestionar', true);
        try { $this->json(['ok' => true, 'codigo' => Producto::siguienteCodigo((int)($_GET['cat'] ?? 0))]); }
        catch (RuntimeException $e) { $this->json(['ok' => false, 'msg' => $e->getMessage()]); }
    }

    public function eliminar(): void
    {
        $this->requirePermiso('inventario.gestionar', true);
        $this->verificarCsrf();
        try { Producto::quitar((int)($this->entrada()['id_producto'] ?? 0)); }
        catch (RuntimeException $e) { $this->json(['ok' => false, 'msg' => $e->getMessage()]); }
        $this->json(['ok' => true, 'msg' => 'Producto dado de baja (el historial se conserva).']);
    }

    public function reactivar(): void
    {
        $this->requirePermiso('inventario.gestionar', true);
        $this->verificarCsrf();
        Producto::reactivar((int)($this->entrada()['id_producto'] ?? 0));
        $this->json(['ok' => true, 'msg' => 'Producto reactivado.']);
    }

    /** Actualización masiva de precios por % (todo el catálogo o una categoría) con bitácora. */
    public function preciosMasivos(): void
    {
        $this->requirePermiso('inventario.gestionar', true);
        $this->verificarCsrf();
        $d = $this->entrada();
        $pct = (float)($d['porcentaje'] ?? 0);
        $cat = (int)($d['id_categoria'] ?? 0);
        if ($pct == 0.0 || $pct < -90 || $pct > 500) $this->json(['ok' => false, 'msg' => 'El porcentaje debe ser distinto de 0 y estar entre -90 y 500.']);
        $n = Producto::ajustarPrecios($pct, $cat);
        Bitacora::registrar($this->usuario()['id'], 'PRECIOS_MASIVOS', sprintf('%+.2f%% · categoría %s · %d producto(s)', $pct, $cat ?: 'todas', $n));
        $this->json(['ok' => true, 'msg' => "Precios actualizados en $n producto(s)."]);
    }
}
