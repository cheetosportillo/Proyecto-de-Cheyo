<?php
require_once __DIR__ . '/../models/Reporte.php';

class DashboardController extends Controller
{
    public function index(): void
    {
        $this->requireRol(['duena', 'cajero']);
        $val = Reporte::valoracion();
        $alertas = array_filter($val, fn($c) => $c['stock_minimo'] > 0 && $c['piezas'] <= $c['stock_minimo']);
        $this->render('dashboard/index', [
            'titulo'   => 'Dashboard',
            'resumen'  => Reporte::resumen(),
            'val'      => $val,
            'alertas'  => $alertas,
            'ultimas'  => Reporte::ultimasVentas(),
        ]);
    }
}
