<!DOCTYPE html>
<html lang="es">
<head>
<meta charset="UTF-8">
<title>Ticket #<?= str_pad($venta['id_venta'], 6, '0', STR_PAD_LEFT) ?></title>
<style>
  body { font-family: 'Courier New', monospace; font-size: 12px; width: 76mm; margin: 0 auto; padding: 4mm; color: #000; }
  h2, p { text-align: center; margin: 2px 0; }
  table { width: 100%; border-collapse: collapse; }
  td { padding: 2px 0; vertical-align: top; }
  .r { text-align: right; } hr { border: 0; border-top: 1px dashed #000; margin: 6px 0; }
  .no-print { text-align: center; margin-top: 10px; }
  @media print { .no-print { display: none; } @page { margin: 0; } }
</style>
</head>
<body onload="window.print()">
  <h2><?= e(APP_NAME) ?></h2>
  <p>El Salvador</p>
  <hr>
  <p>Ticket N.º <?= str_pad($venta['id_venta'], 6, '0', STR_PAD_LEFT) ?></p>
  <p><?= e(date('d/m/Y H:i', strtotime($venta['fecha']))) ?></p>
  <p>Atendió: <?= e($venta['cajero']) ?></p>
  <hr>
  <table>
    <?php foreach ($detalle as $d): ?>
      <tr><td colspan="2"><?= e($d['nombre']) ?></td></tr>
      <tr><td><?= (int)$d['cantidad'] ?> x <?= money($d['precio_unitario']) ?> · <?= number_format($d['peso'], 2) ?><?= $d['unidad_peso'] === 'kilates' ? 'kt' : 'g' ?></td>
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
  <p>¡Gracias por su compra!</p>
  <div class="no-print"><button onclick="window.print()">Imprimir</button></div>
</body>
</html>
