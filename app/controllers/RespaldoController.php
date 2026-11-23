<?php
require_once __DIR__ . '/../models/Bitacora.php';
require_once __DIR__ . '/../core/Respaldo.php';

/** Respaldos .sql (permiso respaldos.gestionar, solo Dueña). Generar = JSON vía api() + CSRF; descargar = GET autenticado. */
class RespaldoController extends Controller
{
    public function index(): void
    {
        $this->requirePermiso('respaldos.gestionar');
        $this->render('respaldos/index', [
            'titulo'   => 'Respaldos',
            'archivos' => Respaldo::listar(),
            'cuenta'   => Respaldo::cuentaConfigurada(),
            'scripts'  => ['respaldos.js'],
        ]);
    }

    public function generar(): void
    {
        $this->requirePermiso('respaldos.gestionar', true);
        $this->verificarCsrf();
        $yo = (int)$this->usuario()['id'];
        try {
            [$pdo, $cuenta] = Respaldo::conexion();
            $r = Respaldo::generar($pdo);
        } catch (RuntimeException $e) {
            $this->json(['ok' => false, 'msg' => $e->getMessage()]);
        } catch (Throwable $e) {
            error_log('[respaldo] ' . $e);
            $this->json(['ok' => false, 'msg' => 'No se pudo generar el respaldo. Revisa el registro de errores del servidor.']);
        }
        Bitacora::registrar($yo, 'RESPALDO_GENERADO', $r['archivo'] . ' · ' . round($r['bytes'] / 1024) . ' KB · ' . $r['tablas'] . ' tablas · ' . $r['filas'] . ' filas · ' . $cuenta);
        $this->json(['ok' => true, 'msg' => 'Respaldo generado (' . $r['tablas'] . ' tablas, ' . $r['filas'] . ' filas).',
                     'archivo' => $r['archivo'], 'descarga' => url('respaldo', 'descargar', ['f' => $r['archivo']])]);
    }

    /** Descarga un respaldo de backups/ (el nombre se valida contra un patrón estricto: no hay rutas libres). */
    public function descargar(): void
    {
        $this->requirePermiso('respaldos.gestionar');
        $ruta = Respaldo::ruta((string)($_GET['f'] ?? ''));
        if ($ruta === null) {
            http_response_code(404);
            exit('Respaldo no encontrado.');
        }
        Bitacora::registrar((int)$this->usuario()['id'], 'RESPALDO_DESCARGADO', basename($ruta));
        while (ob_get_level() > 0) ob_end_clean();
        @set_time_limit(0);
        header('Content-Type: application/sql; charset=utf-8');
        header('Content-Disposition: attachment; filename="' . basename($ruta) . '"');
        header('Content-Length: ' . (int)filesize($ruta));
        header('Cache-Control: no-store');
        header('X-Content-Type-Options: nosniff');
        readfile($ruta);
        exit;
    }
}
