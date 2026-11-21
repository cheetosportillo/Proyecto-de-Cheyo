<?php
require_once __DIR__ . '/../models/Kardex.php';
require_once __DIR__ . '/../models/Bitacora.php';
require_once __DIR__ . '/../models/Producto.php';
require_once __DIR__ . '/../models/Categoria.php';

/** Consulta del Kardex, ajustes de inventario, toma física y carga inicial por CSV. */
class KardexController extends Controller
{
    private function filtros(): array
    {
        [$desde, $hasta] = rango_fechas($_GET['desde'] ?? date('Y-m-01'), $_GET['hasta'] ?? date('Y-m-d'));
        return [(int)($_GET['prod'] ?? 0), (int)($_GET['cat'] ?? 0), $desde, $hasta];
    }

    public function index(): void
    {
        $this->requirePermiso('kardex.ver');
        [$prod, $cat, $desde, $hasta] = $this->filtros();
        $lunes = (new DateTime('monday this week'))->format('Y-m-d');
        $this->render('kardex/index', [
            'titulo'     => 'Kardex de inventario',
            'filas'      => Kardex::consultar($prod, $cat, $desde, $hasta),
            'productos'  => Producto::listar('', 0, true, false),
            'categorias' => Categoria::listar(false),
            'prod' => $prod, 'cat' => $cat, 'desde' => $desde, 'hasta' => $hasta,
            'rapidos'    => ['Hoy' => [date('Y-m-d'), date('Y-m-d')], 'Semana' => [$lunes, date('Y-m-d')], 'Mes' => [date('Y-m-01'), date('Y-m-d')]],
            'puedeAjustar' => $this->puede('kardex.ajustar'),
            'puedeCargar'  => $this->puede('kardex.ajustar') && $this->puede('inventario.gestionar'),
        ]);
    }

    /** Exportar CSV seguro (kardex.ver). */
    public function csv(): void
    {
        $this->requirePermiso('kardex.ver');
        [$prod, $cat, $desde, $hasta] = $this->filtros();
        header('Content-Type: text/csv; charset=utf-8');
        header("Content-Disposition: attachment; filename=\"kardex_{$desde}_{$hasta}.csv\"");
        $out = fopen('php://output', 'w');
        fwrite($out, "\xEF\xBB\xBF");
        fputcsv($out, ['Fecha', 'Código', 'Producto', 'Categoría', 'Tipo', 'Cantidad', 'Peso mov.', 'Unidad', 'Costo unit.', 'Valor total',
                       'Saldo piezas', 'Saldo peso', 'Saldo monto', 'CPP', 'Motivo']);
        foreach (Kardex::consultar($prod, $cat, $desde, $hasta, 100000) as $k)
            fputcsv($out, array_map('csv_celda', [$k['fecha'], $k['codigo'], $k['nombre'], $k['categoria'], $k['tipo'], $k['cantidad'], $k['peso_mov'],
                $k['unidad_peso'], $k['costo_unitario'], $k['valor_total'], $k['saldo_cantidad'], $k['saldo_peso'], $k['saldo_monetario'], $k['cpp'], $k['motivo']]));
        fclose($out);
        exit;
    }

    /** Modal «Ajuste de Inventario»: valorado al CPP vigente, motivo >= 5. */
    public function ajustar(): void
    {
        $this->requirePermiso('kardex.ajustar', true);
        $this->verificarCsrf();
        $d = $this->entrada();
        $id = (int)($d['id_producto'] ?? 0); $cant = (int)($d['cantidad'] ?? 0);
        $motivo = trim($d['motivo'] ?? '');
        $sentido = $d['tipo'] ?? '';
        if ($id <= 0 || $cant <= 0 || $cant > 100000) $this->json(['ok' => false, 'msg' => 'Elige un producto y una cantidad mayor a 0.']);
        if (largo_texto($motivo) < 5) $this->json(['ok' => false, 'msg' => 'El motivo debe tener al menos 5 caracteres.']);
        if (!in_array($sentido, ['ENTRADA', 'SALIDA'], true)) $this->json(['ok' => false, 'msg' => 'Tipo de ajuste no válido.']);
        try {
            $sentido === 'ENTRADA'
                ? Kardex::registrarEntrada($id, 'AJUSTE_ENTRADA', $cant, null, $this->usuario()['id'], 'AJUSTE', null, $motivo)
                : Kardex::registrarSalida($id, 'AJUSTE_SALIDA', $cant, $this->usuario()['id'], 'AJUSTE', null, $motivo);
        } catch (RuntimeException $e) { $this->json(['ok' => false, 'msg' => $e->getMessage()]); }
        Bitacora::registrar($this->usuario()['id'], 'AJUSTE_INVENTARIO', "producto $id $sentido x$cant: $motivo");
        $this->json(['ok' => true, 'msg' => 'Ajuste registrado al costo promedio vigente.']);
    }

    /** Toma física: compara lo contado contra el sistema y genera un ajuste por cada diferencia. */
    public function tomaFisica(): void
    {
        $this->requirePermiso('kardex.ajustar', true);
        $this->verificarCsrf();
        $conteos = [];
        foreach (($this->entrada()['conteos'] ?? []) as $c) {
            if (!isset($c['id']) || !isset($c['contado']) || $c['contado'] === '') continue;   // sin contar: se ignora
            $contado = (int)$c['contado'];
            if ($contado < 0 || $contado > 1000000) $this->json(['ok' => false, 'msg' => 'Hay cantidades contadas no válidas.']);
            $conteos[(int)$c['id']] = $contado;
        }
        if (!$conteos) $this->json(['ok' => false, 'msg' => 'No ingresaste ninguna cantidad contada.']);
        ksort($conteos);   // bloqueo ordenado por id_producto
        $uid = $this->usuario()['id']; $motivo = 'Toma física ' . date('Y-m-d');

        try {
            $ajustes = Database::transaccion(function (PDO $pdo) use ($conteos, $uid, $motivo) {
                $n = 0;
                $sel = $pdo->prepare('SELECT codigo, stock FROM productos WHERE id_producto = ? FOR UPDATE');
                foreach ($conteos as $id => $contado) {
                    $sel->execute([$id]);
                    $p = $sel->fetch();
                    if (!$p) throw new RuntimeException("El producto #$id no existe.");
                    $dif = $contado - (int)$p['stock'];
                    if ($dif === 0) continue;
                    try {
                        $dif > 0 ? Kardex::registrarEntrada($id, 'AJUSTE_ENTRADA', $dif, null, $uid, 'TOMA_FISICA', null, $motivo)
                                 : Kardex::registrarSalida($id, 'AJUSTE_SALIDA', -$dif, $uid, 'TOMA_FISICA', null, $motivo);
                    } catch (RuntimeException $e) { throw new RuntimeException("«{$p['codigo']}»: " . $e->getMessage()); }
                    $n++;
                }
                return $n;
            });
        } catch (RuntimeException $e) { $this->json(['ok' => false, 'msg' => $e->getMessage()]); }
        Bitacora::registrar($uid, 'TOMA_FISICA', "$ajustes ajuste(s)");
        $this->json(['ok' => true, 'msg' => $ajustes ? "Toma física aplicada: $ajustes ajuste(s) registrado(s)." : 'Todo coincide con el sistema: no hubo diferencias.']);
    }

    /**
     * Carga inicial CSV (SALDO_INICIAL): columnas codigo,cantidad,costo. Todo o nada (una sola transacción).
     * Requiere inventario.gestionar Y kardex.ajustar.
     */
    public function cargaCsv(): void
    {
        $this->requirePermiso('inventario.gestionar', true);
        $this->requirePermiso('kardex.ajustar', true);
        $this->verificarCsrf();
        $texto = (string)($this->entrada()['csv'] ?? '');
        $texto = preg_replace('/^\xEF\xBB\xBF/', '', $texto);
        $lineas = preg_split('/\r\n|\n|\r/', trim($texto));
        if (!$lineas || $lineas[0] === '') $this->json(['ok' => false, 'msg' => 'El archivo está vacío.']);
        if (count($lineas) > 2001) $this->json(['ok' => false, 'msg' => 'Máximo 2,000 filas por carga.']);

        $filas = [];
        foreach ($lineas as $i => $linea) {
            $n = $i + 1;
            $c = str_getcsv($linea, strpos($linea, ';') !== false && strpos($linea, ',') === false ? ';' : ',');
            if ($i === 0 && !is_numeric($c[1] ?? '')) continue;   // encabezado
            if (count($c) < 3) $this->json(['ok' => false, 'msg' => "Fila $n: se esperan 3 columnas (codigo, cantidad, costo)."]);
            $codigo = strtoupper(trim($c[0]));
            if (!ctype_digit(trim($c[1])) || (int)$c[1] <= 0 || !is_numeric(trim($c[2])) || (float)$c[2] < 0)
                $this->json(['ok' => false, 'msg' => "Fila $n («$codigo»): cantidad entera > 0 y costo numérico >= 0."]);
            if (isset($filas[$codigo])) $this->json(['ok' => false, 'msg' => "Fila $n: el código «$codigo» está repetido en el archivo."]);
            $filas[$codigo] = ['n' => $n, 'cantidad' => (int)$c[1], 'costo' => a_centavos(trim($c[2]))];
        }
        if (!$filas) $this->json(['ok' => false, 'msg' => 'No hay filas para cargar.']);

        $uid = $this->usuario()['id'];
        try {
            $n = Database::transaccion(function (PDO $pdo) use ($filas, $uid) {
                $busca = $pdo->prepare('SELECT id_producto FROM productos WHERE codigo = ? AND estado = 1');
                $res = [];
                foreach ($filas as $codigo => $f) {
                    $busca->execute([$codigo]);
                    $id = $busca->fetchColumn();
                    if (!$id) throw new RuntimeException("Fila {$f['n']}: no existe un producto activo con código «$codigo». Créalo primero en Inventario.");
                    $res[(int)$id] = $f + ['codigo' => $codigo];
                }
                ksort($res);   // orden por id_producto antes de bloquear
                foreach ($res as $id => $f) {
                    try { Kardex::registrarEntrada($id, 'SALDO_INICIAL', $f['cantidad'], $f['costo'], $uid, 'CARGA_CSV', null, 'Carga inicial CSV'); }
                    catch (RuntimeException $e) { throw new RuntimeException("Fila {$f['n']} («{$f['codigo']}»): " . $e->getMessage()); }
                }
                return count($res);
            });
        } catch (RuntimeException $e) { $this->json(['ok' => false, 'msg' => $e->getMessage() . ' (no se cargó nada)']); }
        Bitacora::registrar($uid, 'CARGA_INICIAL_CSV', "$n producto(s)");
        $this->json(['ok' => true, 'msg' => "Carga inicial completa: $n producto(s) con su saldo inicial."]);
    }
}
