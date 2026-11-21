<?php
require_once __DIR__ . '/../models/Bitacora.php';
require_once __DIR__ . '/../models/Caja.php';

/** Caja única: apertura, movimientos de efectivo y cierre/arqueo. Permiso: caja.operar */
class CajaController extends Controller
{
    public function index(): void
    {
        $this->requirePermiso('caja.operar');
        $caja = Caja::abierta();
        $this->render('caja/index', [
            'titulo'      => 'Caja',
            'caja'        => $caja,
            'resumen'     => $caja ? Caja::resumen((int)$caja['id_caja']) : null,
            'movimientos' => $caja ? Caja::movimientos((int)$caja['id_caja']) : [],
            'historial'   => Caja::historial(),
        ]);
    }

    public function abrir(): void
    {
        $this->requirePermiso('caja.operar', true);
        $this->verificarCsrf();
        $monto = $this->entrada()['monto_inicial'] ?? '';
        if (!is_numeric($monto) || (float)$monto < 0 || (float)$monto > 9999999.99) $this->json(['ok' => false, 'msg' => 'Ingresa un monto inicial válido (0 o más).']);
        try { Caja::abrir($this->usuario()['id'], a_centavos($monto)); }
        catch (RuntimeException $e) { $this->json(['ok' => false, 'msg' => $e->getMessage()]); }
        $this->json(['ok' => true, 'msg' => 'Caja abierta. Ya puedes cobrar.']);
    }

    public function movimiento(): void
    {
        $this->requirePermiso('caja.operar', true);
        $this->verificarCsrf();
        $d = $this->entrada();
        $monto = $d['monto'] ?? '';
        if (!is_numeric($monto) || (float)$monto <= 0 || (float)$monto > 9999999.99) $this->json(['ok' => false, 'msg' => 'Ingresa un monto mayor a 0.']);
        try { Caja::movimiento($this->usuario()['id'], (string)($d['tipo'] ?? ''), a_centavos($monto), (string)($d['motivo'] ?? '')); }
        catch (RuntimeException $e) { $this->json(['ok' => false, 'msg' => $e->getMessage()]); }
        $this->json(['ok' => true, 'msg' => 'Movimiento registrado.']);
    }

    public function cerrar(): void
    {
        $this->requirePermiso('caja.operar', true);
        $this->verificarCsrf();
        $contado = $this->entrada()['efectivo_contado'] ?? '';
        if (!is_numeric($contado) || (float)$contado < 0) $this->json(['ok' => false, 'msg' => 'Ingresa el efectivo contado.']);
        try { $r = Caja::cerrar($this->usuario()['id'], a_centavos($contado)); }
        catch (RuntimeException $e) { $this->json(['ok' => false, 'msg' => $e->getMessage()]); }
        $dif = $r['diferencia'];
        $this->json(['ok' => true,
            'msg' => 'Caja cerrada. Esperado ' . money(de_centavos($r['resumen']['esperado'])) . ' · contado ' . money(de_centavos($r['contado']))
                   . ' · ' . ($dif === 0 ? 'cuadra exacto' : ($dif > 0 ? 'sobrante ' : 'faltante ') . money(de_centavos(abs($dif))))
                   . ($r['backup'] ? ' · respaldo automático generado.' : '')]);
    }
}
