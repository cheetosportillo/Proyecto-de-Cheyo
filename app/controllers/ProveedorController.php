<?php
require_once __DIR__ . '/../models/Proveedor.php';

/** Proveedores: protegido con compras.gestionar. */
class ProveedorController extends Controller
{
    public function guardar(): void
    {
        $this->requirePermiso('compras.gestionar', true);
        $this->verificarCsrf();
        $d = $this->entrada();

        $id     = (int)($d['id_proveedor'] ?? 0);
        $nombre = trim($d['nombre'] ?? '');
        $correo = trim($d['correo'] ?? '');
        $tel    = trim($d['telefono'] ?? '');
        if ($nombre === '' || largo_texto($nombre) > 150) $this->json(['ok' => false, 'msg' => 'El nombre del proveedor es obligatorio (máx. 150 caracteres).']);
        if (largo_texto($tel) > 30) $this->json(['ok' => false, 'msg' => 'El teléfono admite máximo 30 caracteres.']);
        if ($correo !== '' && !filter_var($correo, FILTER_VALIDATE_EMAIL)) $this->json(['ok' => false, 'msg' => 'El correo no es válido.']);

        $datos = ['nombre' => $nombre, 'telefono' => $tel, 'correo' => $correo, 'direccion' => trim($d['direccion'] ?? '')];
        $dup = Proveedor::existe($nombre, $id);
        if ($dup) {
            if ((int)$dup['estado'] === 1) $this->json(['ok' => false, 'msg' => 'Ya existe un proveedor con ese nombre.']);
            // Existía pero estaba dado de baja: se reactiva con los datos nuevos
            if ($id === 0) {
                Proveedor::actualizar((int)$dup['id_proveedor'], $datos);
                Proveedor::reactivar((int)$dup['id_proveedor']);
                $this->json(['ok' => true, 'msg' => 'Ese proveedor estaba inactivo: fue reactivado con los datos ingresados.']);
            }
            $this->json(['ok' => false, 'msg' => 'Ya existe un proveedor (inactivo) con ese nombre. Reactívalo desde la lista.']);
        }
        $id > 0 ? Proveedor::actualizar($id, $datos) : Proveedor::crear($datos);
        $this->json(['ok' => true, 'msg' => 'Proveedor guardado correctamente.']);
    }

    public function eliminar(): void
    {
        $this->requirePermiso('compras.gestionar', true);
        $this->verificarCsrf();
        Proveedor::quitar((int)($this->entrada()['id_proveedor'] ?? 0));
        $this->json(['ok' => true, 'msg' => 'Proveedor dado de baja.']);
    }

    public function reactivar(): void
    {
        $this->requirePermiso('compras.gestionar', true);
        $this->verificarCsrf();
        Proveedor::reactivar((int)($this->entrada()['id_proveedor'] ?? 0));
        $this->json(['ok' => true, 'msg' => 'Proveedor reactivado.']);
    }
}
