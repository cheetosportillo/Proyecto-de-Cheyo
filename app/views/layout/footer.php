    </main>
</div>
<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
<script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>
<script>const BASE_URL = <?= json_encode(base_url() . '/index.php') ?>, MONEDA = <?= json_encode(MONEDA) ?>;</script>
<script src="<?= asset('js/app.js') ?>"></script>
<?php if (!empty($scripts)) foreach ($scripts as $s): ?>
<script src="<?= asset('js/' . $s) ?>"></script>
<?php endforeach; ?>
</body>
</html>
