<?php
require_once __DIR__ . '/../models/Proveedor.php';

class ProveedorController extends Controller
{
    public function guardar(): void
    {
        $this->requireRol(['duena'], true);
        $this->verificarCsrf();
        $d = $this->entrada();

        $id     = (int)($d['id_proveedor'] ?? 0);
        $nombre = trim($d['nombre'] ?? '');
        $correo = trim($d['correo'] ?? '');
        if ($nombre === '') $this->json(['ok' => false, 'msg' => 'El nombre del proveedor es obligatorio.']);
        if ($correo !== '' && !filter_var($correo, FILTER_VALIDATE_EMAIL))
            $this->json(['ok' => false, 'msg' => 'El correo no es válido.']);

        $datos = ['nombre' => $nombre, 'telefono' => trim($d['telefono'] ?? ''),
                  'correo' => $correo, 'direccion' => trim($d['direccion'] ?? '')];
        $id > 0 ? Proveedor::actualizar($id, $datos) : Proveedor::crear($datos);
        $this->json(['ok' => true, 'msg' => 'Proveedor guardado correctamente.']);
    }

    public function eliminar(): void
    {
        $this->requireRol(['duena'], true);
        $this->verificarCsrf();
        $d = $this->entrada();
        Proveedor::quitar((int)($d['id_proveedor'] ?? 0));
        $this->json(['ok' => true, 'msg' => 'Proveedor eliminado.']);
    }
}
