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
$esTi = in_array($rol, ['agente_ti', 'admin_ti'], true);
$current = \App\Core\Router::currentPath((string) ($_SERVER['REQUEST_URI'] ?? '/'));
$isActive = static fn (string $prefix): string =>
    ($prefix === '/' ? $current === '/' : str_starts_with($current, $prefix)) ? ' active' : '';
$rolLabel = ['usuario' => 'Usuario', 'agente_ti' => 'Agente TI', 'admin_ti' => 'Admin TI'];
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
                            <a class="nav-link<?= $isActive('/ti') ?>" href="<?= e(url('/ti/tickets')) ?>"><i class="bi bi-kanban me-1"></i>Bandeja TI</a>
                        </li>
                        <li class="nav-item">
                            <a class="nav-link<?= $isActive('/geca') ?>" href="<?= e(url('/geca')) ?>"><i class="bi bi-cloud-arrow-up me-1"></i>Carga GECA</a>
                        </li>
                    <?php endif; ?>
                </ul>
                <div class="d-flex align-items-center gap-2 text-white">
                    <span class="small text-truncate" style="max-width: 16rem;">
                        <i class="bi bi-person-circle me-1"></i><?= e($authUser['nombre']) ?>
                        <span class="badge text-bg-light ms-1"><?= e($rolLabel[$rol] ?? $rol) ?></span>
                    </span>
                    <form method="post" action="<?= e(url('/logout')) ?>" class="m-0">
                        <?= csrf_field() ?>
                        <button type="submit" class="btn btn-sm btn-outline-light"><i class="bi bi-box-arrow-right"></i> Salir</button>
                    </form>
                </div>
            </div>
        <?php endif; ?>
    </div>
</nav>

<main class="container py-4 flex-grow-1">
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
<script src="<?= e(asset('js/app.js')) ?>"></script>
</body>
</html>
