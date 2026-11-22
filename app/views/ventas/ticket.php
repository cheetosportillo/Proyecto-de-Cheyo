<?php
// Todo sale de detalle_venta (foto histórica) y de Config; nunca del catálogo vigente.
$negocio = APP_NAME; $dir = 'El Salvador'; $tel = ''; $mensaje = '¡Gracias por su compra!';
if (class_exists('Config') && method_exists('Config', 'obtener')) {   // Parte 1
    $negocio = Config::obtener('negocio_nombre', $negocio) ?: $negocio;
    $dir     = Config::obtener('negocio_direccion', $dir) ?: $dir;
    $tel     = (string)Config::obtener('negocio_telefono', '');
    $mensaje = Config::obtener('ticket_mensaje', $mensaje) ?: $mensaje;
}
$nro = str_pad((string)$venta['numero_ticket'], 6, '0', STR_PAD_LEFT);
?>
<!DOCTYPE html>
<html lang="es">
<head>
<meta charset="UTF-8">
<title>Ticket #<?= e($nro) ?></title>
<style>
  body { font-family: 'Courier New', monospace; font-size: 12px; width: 76mm; margin: 0 auto; padding: 4mm; color: #000; }
  h2, p { text-align: center; margin: 2px 0; }
  table { width: 100%; border-collapse: collapse; }
  td { padding: 2px 0; vertical-align: top; }
  .r { text-align: right; } hr { border: 0; border-top: 1px dashed #000; margin: 6px 0; }
  .copia { text-align: center; font-weight: bold; font-size: 16px; border: 2px solid #000; margin: 4px 0; padding: 2px; }
  .no-print { text-align: center; margin-top: 10px; }
  @media print { .no-print { display: none; } @page { margin: 0; } }
</style>
</head>
<body onload="window.print()">
  <h2><?= e($negocio) ?></h2>
  <p><?= e($dir) ?></p>
  <?php if ($tel !== ''): ?><p>Tel. <?= e($tel) ?></p><?php endif; ?>
  <p><strong>Comprobante de Venta (No Fiscal)</strong></p>
  <?php if (!empty($copia)): ?><div class="copia">*** COPIA ***</div><?php endif; ?>
  <hr>
  <p>Ticket N.º <?= e($nro) ?></p>
  <p><?= e(date('d/m/Y H:i', strtotime($venta['fecha']))) ?></p>
  <p>Atendió: <?= e($venta['cajero']) ?></p>
  <?php if ($venta['estado'] === 'ANULADA'): ?><p><strong>** VENTA ANULADA **</strong></p><?php endif; ?>
  <hr>
  <table>
    <?php foreach ($detalle as $d): ?>
      <tr><td colspan="2"><?= e($d['codigo']) ?> · <?= e($d['nombre']) ?></td></tr>
      <tr><td><?= (int)$d['cantidad'] ?> x <?= money($d['precio_unitario']) ?> · <?= e(fmt_peso($d['peso'], $d['unidad_peso'])) ?></td>
          <td class="r"><?= money($d['subtotal']) ?></td></tr>
    <?php endforeach; ?>
  </table>
  <hr>
  <table>
    <tr><td><strong>TOTAL</strong></td><td class="r"><strong><?= money($venta['total']) ?></strong></td></tr>
    <tr><td>Efectivo</td><td class="r"><?= money($venta['efectivo']) ?></td></tr>
    <tr><td>Vuelto</td><td class="r"><?= money($venta['cambio']) ?></td></tr>
  </table>
  <hr>
  <p><?= e($mensaje) ?></p>
  <!-- PENDIENTE (contador): definir el manejo del IVA 13 % antes de emitir documentos fiscales -->
  <div class="no-print"><button onclick="window.print()">Imprimir</button></div>
</body>
</html>
