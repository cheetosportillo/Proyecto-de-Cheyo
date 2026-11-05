<?php
$usr = $_SESSION['usuario'];
$ctrl = $_GET['c'] ?? 'dashboard';
$esDuena = $usr['rol'] === 'duena';
function nav_item(string $c, string $icono, string $texto, string $ctrl): string {
    $act = $ctrl === $c ? ' active' : '';
    return '<li class="nav-item"><a class="nav-link' . $act . '" href="' . e(url($c)) . '"><i class="bi ' . $icono . ' me-2"></i>' . $texto . '</a></li>';
}
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="csrf-token" content="<?= e(Controller::csrf()) ?>">
    <title><?= e($titulo ?? 'Sistema') ?> | <?= APP_NAME ?></title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css" rel="stylesheet">
    <link href="<?= asset('css/estilos.css') ?>" rel="stylesheet">
</head>
<body>
<div class="d-flex">
    <!-- Menú lateral -->
    <nav class="sidebar d-flex flex-column p-3" id="sidebar">
        <a href="<?= e(url('dashboard')) ?>" class="brand text-decoration-none mb-4">
            <i class="bi bi-gem"></i> <?= APP_NAME ?>
        </a>
        <ul class="nav nav-pills flex-column gap-1 mb-auto">
            <?= nav_item('dashboard', 'bi-speedometer2', 'Dashboard', $ctrl) ?>
            <?= nav_item('venta', 'bi-cash-coin', 'Ventas (Caja)', $ctrl) ?>
            <?= nav_item('producto', 'bi-box-seam', 'Inventario', $ctrl) ?>
            <?php if ($esDuena): ?>
                <?= nav_item('compra', 'bi-truck', 'Compras / Proveedores', $ctrl) ?>
                <?= nav_item('usuario', 'bi-people', 'Usuarios', $ctrl) ?>
            <?php endif; ?>
        </ul>
        <hr class="border-secondary">
        <div class="small text-white-50 mb-2">
            <i class="bi bi-person-circle"></i> <?= e($usr['nombre']) ?><br>
            <span class="badge bg-warning text-dark"><?= $esDuena ? 'Dueña' : 'Cajero' ?></span>
        </div>
        <a class="btn btn-outline-light btn-sm" href="<?= e(url('auth', 'logout')) ?>"><i class="bi bi-box-arrow-right"></i> Cerrar sesión</a>
    </nav>

    <!-- Contenido -->
    <main class="flex-grow-1 p-4 content">
        <button class="btn btn-dark d-md-none mb-3" onclick="document.getElementById('sidebar').classList.toggle('show')"><i class="bi bi-list"></i> Menú</button>
        <h3 class="mb-4 titulo-pagina"><?= e($titulo ?? '') ?></h3>
