    </main>
</div>
<script src="<?= asset('vendor/bootstrap/js/bootstrap.bundle.min.js') ?>"></script>
<script src="<?= asset('vendor/sweetalert2/sweetalert2.all.min.js') ?>"></script>
<script>const BASE_URL = <?= json_encode(base_url() . '/index.php') ?>, MONEDA = <?= json_encode(MONEDA) ?>;</script>
<script src="<?= asset('js/app.js') ?>"></script>
<?php if (!empty($scripts)) foreach ($scripts as $s): ?>
<script src="<?= asset('js/' . $s) ?>"></script>
<?php endforeach; ?>
</body>
</html>
