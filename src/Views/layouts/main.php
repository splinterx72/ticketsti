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
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link rel="stylesheet" href="https://fonts.googleapis.com/css2?family=Roboto+Condensed:wght@500;700&family=Roboto:wght@400;500;700&display=swap">
    <link rel="stylesheet" href="<?= e(asset('css/app.css')) ?>">
    <link rel="stylesheet" href="<?= e(asset('css/theme.css')) ?>">
</head>
<body class="d-flex flex-column min-vh-100">

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
                        <?php if (modulo('tareas')): ?>
                        <li class="nav-item">
                            <a class="nav-link<?= $isActive('/tareas') ?>" href="<?= e(url('/tareas')) ?>"><i class="bi bi-list-task me-1"></i>Tareas</a>
                        </li>
                        <?php endif; ?>
                        <?php
                        $gestion = array_values(array_filter([
                            modulo('inventario') ? ['/ti/equipos', 'bi-pc-display', 'Inventario'] : null,
                            modulo('garantias') ? ['/ti/garantias', 'bi-shield-check', 'Garantías'] : null,
                            modulo('reportes') ? ['/ti/reportes', 'bi-bar-chart', 'Reportes'] : null,
                            ['/geca', 'bi-cloud-arrow-up', 'Carga GECA'],
                            modulo('plantillas') ? ['/ti/plantillas', 'bi-envelope-paper', 'Plantillas de correo'] : null,
                        ]));
                        if ($rol === 'admin_ti') {
                            $gestion[] = ['/ti/modulos', 'bi-toggles', 'Módulos'];
                            $gestion[] = ['/ti/correo', 'bi-envelope-at', 'Correo y Remedy'];
                            $gestion[] = ['/ti/zonas', 'bi-map', 'Zonas'];
                            $gestion[] = ['/ti/sla', 'bi-stopwatch', 'Tiempos de atención'];
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
                        ];
                        if (modulo('avisos')) {
                            $supervision[] = ['/supervisor/avisos', 'bi-megaphone', 'Avisos'];
                        }
                        if ($rol === 'coordinador') {
                            array_splice($supervision, 1, 0, [['/supervisor/supervisores', 'bi-diagram-3', 'Supervisores de mi zona']]);
                        } ?>
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
                    <?php
                    $avisosPendientes = 0;
                    try {
                        $avisosPendientes = (new \App\Services\AvisoService(\App\Config\Database::getInstance()))->pendientesDe($authUser);
                    } catch (\Throwable) {
                    }
                    if ($avisosPendientes > 0): ?>
                        <li class="nav-item nav-avisos">
                            <a class="nav-link" href="<?= e(url('/#avisos')) ?>" title="Avisos sin leer">
                                <i class="bi bi-bell-fill"></i>
                                <span class="badge rounded-pill text-bg-danger"><?= e($avisosPendientes) ?></span>
                                <span class="visually-hidden">avisos sin leer</span>
                            </a>
                        </li>
                    <?php endif; ?>
                    <li class="nav-item dropdown">
                        <a class="nav-link dropdown-toggle text-truncate<?= $isActive('/cuenta') ?>" href="#" role="button" data-bs-toggle="dropdown" aria-expanded="false" style="max-width: 18rem;">
                            <i class="bi bi-person-circle me-1"></i><?= e($authUser['nombre']) ?>
                            <span class="badge text-bg-light ms-1"><?= e($rolLabel[$rol] ?? $rol) ?></span>
                        </a>
                        <ul class="dropdown-menu dropdown-menu-end">
                            <li><span class="dropdown-item-text small text-body-secondary">Ficha <?= e($authUser['ficha']) ?></span></li>
                            <li><a class="dropdown-item" href="<?= e(url('/cuenta')) ?>"><i class="bi bi-person-gear me-2"></i>Mi cuenta</a></li>
                            <?php if (modulo('garantias')): ?>
                                <li><a class="dropdown-item" href="<?= e(url('/garantias')) ?>"><i class="bi bi-shield-check me-2"></i>Mis garantías</a></li>
                            <?php endif; ?>
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

<?php if ($authUser !== null): $ubicacionTxt = ubicacion_usuario($authUser); ?>
    <div class="user-context">
        <div class="container py-2 d-flex flex-wrap align-items-center column-gap-3 row-gap-1">
            <a href="<?= e(url('/cuenta')) ?>" class="d-inline-flex align-items-center gap-1 text-truncate" style="max-width: 100%;" title="Cambiar mi ubicación">
                <i class="bi bi-geo-alt-fill" aria-hidden="true"></i>
                <span class="user-context-text text-truncate"><?= $ubicacionTxt !== null ? e($ubicacionTxt) : '<span class="text-warning-emphasis fw-semibold">Captura tu ubicación</span>' ?></span>
            </a>
            <?php if ($esTi):
                $miEstado = (new \App\Services\AsignadorService(\App\Config\Database::getInstance()))->estadoDe((int) $authUser['id']);
                $icoEstado = ['disponible' => 'bi-circle-fill text-success', 'ocupado' => 'bi-circle-half text-warning', 'fuera' => 'bi-dash-circle text-secondary', 'vacaciones' => 'bi-airplane text-secondary'][$miEstado['estado']]; ?>
                <div class="dropdown ms-sm-auto">
                    <button class="btn btn-sm btn-link p-0 text-decoration-none d-inline-flex align-items-center gap-1" type="button" data-bs-toggle="dropdown" data-bs-auto-close="outside" aria-expanded="false" title="Mi disponibilidad para recibir tickets">
                        <i class="bi <?= e($icoEstado) ?>" aria-hidden="true"></i>
                        <span class="user-context-text"><?= e(\App\Services\AsignadorService::ESTADOS[$miEstado['estado']]) ?><?= $miEstado['hasta'] !== null ? ' hasta ' . e(date('d/m', (int) strtotime((string) $miEstado['hasta']))) : '' ?></span>
                        <i class="bi bi-chevron-down small" aria-hidden="true"></i>
                    </button>
                    <form method="post" action="<?= e(url('/ti/mi-estado')) ?>" class="dropdown-menu p-3" style="min-width: 16rem;">
                        <?= csrf_field() ?>
                        <label class="form-label small fw-semibold" for="mi_estado">Mi disponibilidad</label>
                        <select class="form-select form-select-sm mb-2" id="mi_estado" name="estado">
                            <?php foreach (\App\Services\AsignadorService::ESTADOS as $k => $lbl): ?>
                                <option value="<?= e($k) ?>" <?= $k === $miEstado['estado'] ? 'selected' : '' ?>><?= e($lbl) ?></option>
                            <?php endforeach; ?>
                        </select>
                        <label class="form-label small fw-semibold" for="mi_hasta">Hasta (opcional)</label>
                        <input type="date" class="form-control form-control-sm mb-2" id="mi_hasta" name="hasta" min="<?= e(date('Y-m-d')) ?>" value="<?= e($miEstado['hasta'] ?? '') ?>">
                        <div class="form-text mb-2">Fuera o de vacaciones no recibes sugerencias de tickets.</div>
                        <button class="btn btn-sm btn-primary w-100" type="submit">Guardar</button>
                    </form>
                </div>
            <?php endif; ?>
            <a href="<?= e(url('/cuenta')) ?>" class="d-inline-flex align-items-center gap-1 text-body-secondary<?= $esTi ? '' : ' ms-sm-auto' ?>">
                <i class="bi bi-telephone" aria-hidden="true"></i>
                <span class="user-context-text">Ext. <?= e($authUser['telefono_ext'] ?? '—') ?></span>
            </a>
        </div>
    </div>
<?php endif; ?>

<main class="container py-4 flex-grow-1">
    <?php if ($authUser !== null && \App\Services\AdminLocalService::enAviso($authUser)):
        $diasAL = \App\Services\AdminLocalService::diasRestantes($authUser['admin_local_cambiado_en']); ?>
        <div class="msg msg-advertencia msg-banner" role="alert">
            <span class="msg-ico" aria-hidden="true"><i class="bi bi-shield-exclamation"></i></span>
            <div class="msg-cuerpo">
                <div class="msg-titulo">Tu contraseña de admin local vence en <?= e($diasAL) ?> día<?= $diasAL === 1 ? '' : 's' ?>.</div>
                <div class="msg-texto">Cámbiala ya: a 5 días tu acceso se bloquea hasta registrar el cambio.</div>
            </div>
            <a class="btn btn-sm btn-outline-warning msg-accion" href="<?= e(url('/admin-local')) ?>">Registrar cambio</a>
        </div>
    <?php endif; ?>
    <?= partial('flash', ['flash' => $flash ?? []]) ?>

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
