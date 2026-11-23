<?php
require_once __DIR__ . '/../models/Kardex.php';
require_once __DIR__ . '/../models/Categoria.php';
require_once __DIR__ . '/../models/Bitacora.php';

/** CRUD web de categorías (pestaña «Categorías» del Inventario). Permiso: inventario.gestionar */
class CategoriaController extends Controller
{
    public function guardar(): void
    {
        $this->requirePermiso('inventario.gestionar', true);
        $this->verificarCsrf();
        $d = $this->entrada();

        $id     = (int)($d['id_categoria'] ?? 0);
        $nombre = trim($d['nombre'] ?? '');
        $unidad = $d['unidad_peso'] ?? 'gramos';
        $stockMin = (int)($d['stock_minimo'] ?? 0);
        $pesoMin  = (float)($d['peso_minimo'] ?? 0);

        if ($nombre === '' || largo_texto($nombre) > 100) $this->json(['ok' => false, 'msg' => 'El nombre es obligatorio (máx. 100 caracteres).']);
        if (!in_array($unidad, ['gramos', 'kilates'], true)) $this->json(['ok' => false, 'msg' => 'Unidad de peso no válida.']);
        if ($stockMin < 0 || $stockMin > 1000000 || $pesoMin < 0 || $pesoMin > 99999999.999)
            $this->json(['ok' => false, 'msg' => 'El stock mínimo y el peso mínimo deben ser números positivos razonables.']);
        if (Categoria::existe($nombre, $id)) $this->json(['ok' => false, 'msg' => 'Ya existe una categoría con ese nombre.']);

        $datos = ['nombre' => $nombre, 'unidad_peso' => $unidad, 'stock_minimo' => $stockMin, 'peso_minimo' => number_format($pesoMin, 3, '.', '')];
        try {
            if ($id > 0) Categoria::actualizar($id, $datos); else Categoria::crear($datos);
        } catch (RuntimeException $e) { $this->json(['ok' => false, 'msg' => $e->getMessage()]); }
        Bitacora::registrar($this->usuario()['id'], $id > 0 ? 'CATEGORIA_EDITADA' : 'CATEGORIA_CREADA', $nombre);
        $this->json(['ok' => true, 'msg' => 'Categoría guardada correctamente.']);
    }

    public function baja(): void
    {
        $this->requirePermiso('inventario.gestionar', true);
        $this->verificarCsrf();
        $id = (int)($this->entrada()['id_categoria'] ?? 0);
        try { Categoria::baja($id); }
        catch (RuntimeException $e) { $this->json(['ok' => false, 'msg' => $e->getMessage()]); }
        Bitacora::registrar($this->usuario()['id'], 'CATEGORIA_BAJA', "id $id");
        $this->json(['ok' => true, 'msg' => 'Categoría dada de baja.']);
    }

    public function reactivar(): void
    {
        $this->requirePermiso('inventario.gestionar', true);
        $this->verificarCsrf();
        Categoria::reactivar((int)($this->entrada()['id_categoria'] ?? 0));
        $this->json(['ok' => true, 'msg' => 'Categoría reactivada.']);
    }
}
