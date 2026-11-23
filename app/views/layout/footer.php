    </main>
</div>
<script src="<?= asset('vendor/bootstrap/js/bootstrap.bundle.min.js') ?>"></script>
<script src="<?= asset('vendor/sweetalert2/sweetalert2.all.min.js') ?>"></script>
<script>const BASE_URL = <?= json_encode(base_url() . '/index.php') ?>, MONEDA = <?= json_encode(MONEDA) ?>;</script>
<script>const SESION_SEG = <?= (int)SESION_INACTIVIDAD ?>;</script>
<script>const ONIX_USR = <?= json_encode(['id' => (int)$_SESSION['usuario']['id'], 'nombre' => (string)$_SESSION['usuario']['nombre'],
    'puedeVender' => Permisos::tiene('ventas.cobrar'), 'puedeDescartar' => Permisos::tiene('ventas.anular')],
    JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP) ?>;</script>
<script src="<?= asset('js/app.js') ?>"></script>
<script src="<?= asset('js/sesion.js') ?>"></script>
<script src="<?= asset('js/offline.js') ?>"></script>
<script src="<?= asset('js/pwa.js') ?>"></script>
<?php if (!empty($scripts)) foreach ($scripts as $s): ?>
<script src="<?= asset('js/' . $s) ?>"></script>
<?php endforeach; ?>
</body>
</html>
