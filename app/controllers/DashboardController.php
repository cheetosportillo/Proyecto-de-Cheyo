<?php
require_once __DIR__ . '/../models/Reporte.php';

/** Dashboard y reportes gerenciales: solo con dashboard.ver (ERR-01). */
class DashboardController extends Controller
{
    public function index(): void
    {
        $this->requirePermiso('dashboard.ver');
        $val = Reporte::valoracion();
        [$desde, $hasta] = [$_GET['desde'] ?? date('Y-m-d'), $_GET['hasta'] ?? date('Y-m-d')];
        $lunes = (new DateTime('monday this week'))->format('Y-m-d');
        $this->render('dashboard/index', [
            'titulo'   => 'Dashboard',
            'resumen'  => Reporte::resumen(),
            'val'      => $val,
            'alertas'  => array_filter($val, fn($c) => $c['alerta']),
            'ultimas'  => Reporte::ultimasVentas(),
            'periodo'  => Reporte::ventasPeriodo($desde, $hasta),
            'rapidos'  => ['Hoy' => [date('Y-m-d'), date('Y-m-d')], 'Semana' => [$lunes, date('Y-m-d')], 'Mes' => [date('Y-m-01'), date('Y-m-d')]],
        ]);
    }

    /** Exportación CSV de ventas del periodo (anti-inyección de fórmulas). */
    public function csv(): void
    {
        $this->requirePermiso('dashboard.ver');
        [$desde, $hasta] = rango_fechas($_GET['desde'] ?? '', $_GET['hasta'] ?? '');
        header('Content-Type: text/csv; charset=utf-8');
        header("Content-Disposition: attachment; filename=\"ventas_{$desde}_{$hasta}.csv\"");
        $out = fopen('php://output', 'w');
        fwrite($out, "\xEF\xBB\xBF");   // BOM para que Excel respete los acentos
        fputcsv($out, ['Ticket', 'Fecha', 'Cajero', 'Total', 'Efectivo', 'Vuelto', 'Estado']);
        foreach (Reporte::ventasParaCsv($desde, $hasta) as $f)
            fputcsv($out, array_map('csv_celda', [str_pad($f['numero_ticket'], 6, '0', STR_PAD_LEFT), $f['fecha'], $f['cajero'], $f['total'], $f['efectivo'], $f['cambio'], $f['estado']]));
        fclose($out);
        exit;
    }
}
