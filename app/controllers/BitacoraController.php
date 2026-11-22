<?php
require_once __DIR__ . '/../models/Bitacora.php';

/** Visor de bitácora (permiso bitacora.ver, solo Dueña). Solo lectura: la bitácora es inmutable. */
class BitacoraController extends Controller
{
    public function index(): void
    {
        $this->requirePermiso('bitacora.ver');
        $f = Bitacora::filtros($_GET);
        $total = Bitacora::contar($f);
        $paginas = max(1, (int)ceil($total / Bitacora::POR_PAGINA));
        $pagina = min($paginas, max(1, (int)($_GET['p'] ?? 1)));
        $this->render('bitacora/index', [
            'titulo'   => 'Bitácora',
            'f'        => $f,
            'filas'    => Bitacora::buscar($f, $pagina),
            'total'    => $total,
            'pagina'   => $pagina,
            'paginas'  => $paginas,
            'acciones' => Bitacora::acciones(),
            'usuarios' => Bitacora::usuarios(),
        ]);
    }

    /** CSV con los mismos filtros (hasta MAX_EXPORTAR filas, las más recientes). Celdas a prueba de fórmulas. */
    public function exportar(): void
    {
        $this->requirePermiso('bitacora.ver');
        $f = Bitacora::filtros($_GET);
        Bitacora::registrar((int)$this->usuario()['id'], 'BITACORA_EXPORTADA',
            'Filtros: ' . (json_encode(array_filter($f), JSON_UNESCAPED_UNICODE) ?: '{}'));
        while (ob_get_level() > 0) ob_end_clean();
        header('Content-Type: text/csv; charset=utf-8');
        header('Content-Disposition: attachment; filename="bitacora_' . date('Ymd_His') . '.csv"');
        header('Cache-Control: no-store');
        header('X-Content-Type-Options: nosniff');
        $out = fopen('php://output', 'w');
        fwrite($out, "\xEF\xBB\xBF");   // BOM: Excel abre los acentos bien
        fputcsv($out, ['ID', 'Fecha', 'Usuario', 'Login', 'Acción', 'Detalle'], ',', '"', '');
        Bitacora::recorrer($f, Bitacora::MAX_EXPORTAR, function (array $r) use ($out): void {
            fputcsv($out, array_map('csv_celda', [$r['id_bitacora'], $r['fecha'], $r['u_nombre'] ?? '(sistema)', $r['u_usuario'] ?? '', $r['accion'], $r['detalle'] ?? '']), ',', '"', '');
        });
        fclose($out);
        exit;
    }
}
