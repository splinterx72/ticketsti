<?php
/**
 * Layout principal (usuario autenticado).
 *
 * @var string $content
 * @var string $appName
 * @var string|null $title
 * @var array<string, mixed>|null $authUser
 * @var list<array{type: string, message: string}> $flash
 */
$authUser = $authUser ?? null;
$rol = $authUser['rol'] ?? null;
$esTi = \App\Core\Roles::esTi($rol);
$current = \App\Core\Router::currentPath((string) ($_SERVER['REQUEST_URI'] ?? '/'));
$isActive = static fn (string $prefix): string =>
    ($prefix === '/' ? $current === '/' : str_starts_with($current, $prefix)) ? ' active' : '';
$rolLabel = \App\Core\Roles::LABELS;
?>
<!doctype html>
<html lang="es-MX">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="csrf-token" content="<?= e($csrfToken ?? '') ?>">
    <title><?= e(($title ?? '') !== '' ? $title . ' · ' . $appName : $appName) ?></title>
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css"
          integrity="sha384-QWTKZyjpPEjISv5WaRU9OFeRpok6YctnYmDr5pNlyT2bRjXh0JMhjY6hW+ALEwIH" crossorigin="anonymous">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css">
    <link rel="stylesheet" href="<?= e(asset('css/app.css')) ?>">
</head>
<body class="bg-body-tertiary d-flex flex-column min-vh-100">

<nav class="navbar navbar-expand-lg navbar-dark bg-primary shadow-sm">
    <div class="container">
        <a class="navbar-brand fw-semibold" href="<?= e(url('/')) ?>">
            <i class="bi bi-headset me-1"></i><?= e($appName) ?>
        </a>
        <?php if ($authUser !== null): ?>
            <button class="navbar-toggler" type="button" data-bs-toggle="collapse" data-bs-target="#mainNav"
                    aria-controls="mainNav" aria-expanded="false" aria-label="Mostrar menú">
                <span class="navbar-toggler-icon"></span>
            </button>
            <div class="collapse navbar-collapse" id="mainNav">
                <ul class="navbar-nav me-auto">
                    <li class="nav-item">
                        <a class="nav-link<?= $isActive('/') ?>" href="<?= e(url('/')) ?>"><i class="bi bi-speedometer2 me-1"></i>Inicio</a>
                    </li>
                    <li class="nav-item">
                        <a class="nav-link<?= ($current === '/tickets' || (str_starts_with($current, '/tickets/') && $current !== '/tickets/nuevo')) ? ' active' : '' ?>" href="<?= e(url('/tickets')) ?>"><i class="bi bi-ticket-detailed me-1"></i>Mis tickets</a>
                    </li>
                    <li class="nav-item">
                        <a class="nav-link<?= $current === '/tickets/nuevo' ? ' active' : '' ?>" href="<?= e(url('/tickets/nuevo')) ?>"><i class="bi bi-plus-circle me-1"></i>Nuevo ticket</a>
                    </li>
                    <?php if ($esTi): ?>
                        <li class="nav-item">
                            <a class="nav-link<?= $isActive('/ti/tickets') ?>" href="<?= e(url('/ti/tickets')) ?>"><i class="bi bi-kanban me-1"></i>Bandeja TI</a>
                        </li>
                        <?php
                        $gestion = [
                            ['/ti/equipos', 'bi-pc-display', 'Inventario'],
                            ['/ti/garantias', 'bi-shield-check', 'Garantías'],
                            ['/ti/reportes', 'bi-bar-chart', 'Reportes'],
                            ['/geca', 'bi-cloud-arrow-up', 'Carga GECA'],
                        ];
                        if ($rol === 'admin_ti') {
                            $gestion[] = ['/ti/instalaciones', 'bi-buildings', 'Instalaciones'];
                            $gestion[] = ['/ti/usuarios', 'bi-people', 'Usuarios'];
                        }
                        $gestionActiva = false;
                        foreach ($gestion as [$path]) {
                            $gestionActiva = $gestionActiva || str_starts_with($current, $path);
                        }
                        ?>
                        <li class="nav-item dropdown">
                            <a class="nav-link dropdown-toggle<?= $gestionActiva ? ' active' : '' ?>" href="#" role="button" data-bs-toggle="dropdown" aria-expanded="false">
                                <i class="bi bi-grid me-1"></i>Gestión
                            </a>
                            <ul class="dropdown-menu">
                                <?php foreach ($gestion as [$path, $icon, $label]): ?>
                                    <li><a class="dropdown-item<?= str_starts_with($current, $path) ? ' active' : '' ?>" href="<?= e(url($path)) ?>"><i class="bi <?= e($icon) ?> me-2"></i><?= e($label) ?></a></li>
                                <?php endforeach; ?>
                            </ul>
                        </li>
                    <?php endif; ?>
                    <?php if (in_array($rol, \App\Core\Roles::SUPERVISION, true)): ?>
                        <?php $supervision = [
                            ['/supervisor', 'bi-speedometer', 'Panel'],
                            ['/supervisor/ubicaciones', 'bi-geo-alt', 'Ubicaciones'],
                            ['/supervisor/agentes', 'bi-people', 'Agentes'],
                            ['/supervisor/avisos', 'bi-megaphone', 'Avisos'],
                        ]; ?>
                        <li class="nav-item dropdown">
                            <a class="nav-link dropdown-toggle<?= str_starts_with($current, '/supervisor') ? ' active' : '' ?>" href="#" role="button" data-bs-toggle="dropdown" aria-expanded="false">
                                <i class="bi bi-person-badge me-1"></i>Supervisión
                            </a>
                            <ul class="dropdown-menu">
                                <?php foreach ($supervision as [$path, $icon, $label]): ?>
                                    <li><a class="dropdown-item<?= $current === $path ? ' active' : '' ?>" href="<?= e(url($path)) ?>"><i class="bi <?= e($icon) ?> me-2"></i><?= e($label) ?></a></li>
                                <?php endforeach; ?>
                            </ul>
                        </li>
                    <?php endif; ?>
                </ul>
                <ul class="navbar-nav">
                    <li class="nav-item dropdown">
                        <a class="nav-link dropdown-toggle text-truncate<?= $isActive('/cuenta') ?>" href="#" role="button" data-bs-toggle="dropdown" aria-expanded="false" style="max-width: 18rem;">
                            <i class="bi bi-person-circle me-1"></i><?= e($authUser['nombre']) ?>
                            <span class="badge text-bg-light ms-1"><?= e($rolLabel[$rol] ?? $rol) ?></span>
                        </a>
                        <ul class="dropdown-menu dropdown-menu-end">
                            <li><span class="dropdown-item-text small text-body-secondary">Ficha <?= e($authUser['ficha']) ?></span></li>
                            <li><a class="dropdown-item" href="<?= e(url('/cuenta')) ?>"><i class="bi bi-person-gear me-2"></i>Mi cuenta</a></li>
                            <li><hr class="dropdown-divider"></li>
                            <li>
                                <form method="post" action="<?= e(url('/logout')) ?>" class="m-0">
                                    <?= csrf_field() ?>
                                    <button type="submit" class="dropdown-item"><i class="bi bi-box-arrow-right me-2"></i>Cerrar sesión</button>
                                </form>
                            </li>
                        </ul>
                    </li>
                </ul>
            </div>
        <?php endif; ?>
    </div>
</nav>

<main class="container py-4 flex-grow-1">
    <?php if ($authUser !== null && \App\Services\AdminLocalService::enAviso($authUser)):
        $diasAL = \App\Services\AdminLocalService::diasRestantes($authUser['admin_local_cambiado_en']); ?>
        <div class="alert alert-warning d-flex flex-wrap align-items-center gap-2" role="alert">
            <i class="bi bi-shield-exclamation fs-5"></i>
            <div class="me-auto">
                <strong>Tu contraseña de admin local vence en <?= e($diasAL) ?> día<?= $diasAL === 1 ? '' : 's' ?>.</strong>
                Cámbiala ya: a 5 días tu acceso se bloquea hasta registrar el cambio.
            </div>
            <a class="btn btn-sm btn-warning" href="<?= e(url('/admin-local')) ?>">Registrar cambio</a>
        </div>
    <?php endif; ?>
    <?php foreach ($flash ?? [] as $msg): ?>
        <div class="alert alert-<?= e($msg['type']) ?> alert-dismissible fade show" role="alert">
            <?= e($msg['message']) ?>
            <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Cerrar"></button>
        </div>
    <?php endforeach; ?>

    <?= $content ?>
</main>

<footer class="py-3 border-top bg-white small text-body-secondary">
    <div class="container d-flex justify-content-between flex-wrap gap-2">
        <span>Mesa de Ayuda TI · Control de Resguardos GECA</span>
        <span><?= e(date('Y')) ?></span>
    </div>
</footer>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"
        integrity="sha384-YvpcrYf0tY3lHB60NNkmXc5s9fDVZLESaAA55NDzOxhy9GkcIdslK1eN7N6jIeHz" crossorigin="anonymous"></script>
<script src="<?= e(asset('js/ubicacion-picker.js')) ?>"></script>
<script src="<?= e(asset('js/app.js')) ?>"></script>
</body>
</html>
