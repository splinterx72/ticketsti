<?php
/**
 * @var array<string, mixed> $db
 * @var array<string, mixed> $report
 * @var array<string, mixed> $site
 * @var array<string, mixed> $admin
 * @var array<string, string> $timezones
 */
$tzLabel = \App\Services\Installer\Installer::TIMEZONES[$site['timezone']] ?? $site['timezone'];
$sections = [
    ['Base de datos', 'bi-database', '/instalacion/base-datos', [
        'Servidor' => $report['server'] . ' ' . $report['version'],
        'Conexión' => $db['username'] . '@' . $db['host'] . ':' . $db['port'],
        'Base'     => $db['database'],
        'Acción'   => $report['mode'] === 'fresh'
            ? 'Crear ' . count(\App\Services\Installer\Installer::TABLES) . ' tablas y catálogos iniciales'
            : 'Conservar tablas y datos existentes',
    ]],
    ['Sitio', 'bi-globe2', '/instalacion/sitio', [
        'Nombre'      => $site['name'],
        'URL'         => $site['base_url'],
        'Zona horaria' => $tzLabel,
        'HTTPS'       => $site['force_https'] ? 'Forzado' : 'No forzado',
    ]],
    ['Administrador', 'bi-person-badge', '/instalacion/administrador', [
        'Ficha'  => $admin['ficha'],
        'Nombre' => $admin['nombre'],
        'Correo' => $admin['email'],
        'Rol'    => 'Admin TI',
    ]],
];
?>
<h1 class="h4 mb-1">Todo listo para instalar</h1>
<p class="text-body-secondary">Revisa el resumen. Puedes regresar a cualquier paso para corregirlo.</p>

<div class="row g-3 mb-4">
    <?php foreach ($sections as [$title, $icon, $path, $rows]): ?>
        <div class="col-md-4">
            <div class="card h-100 border">
                <div class="card-header bg-white d-flex justify-content-between align-items-center">
                    <span class="fw-semibold"><i class="bi <?= e($icon) ?> me-1"></i><?= e($title) ?></span>
                    <a href="<?= e(url($path)) ?>" class="small">Editar</a>
                </div>
                <dl class="card-body small mb-0 summary-list">
                    <?php foreach ($rows as $label => $value): ?>
                        <dt class="text-body-secondary fw-normal"><?= e($label) ?></dt>
                        <dd class="text-break"><?= e($value) ?></dd>
                    <?php endforeach; ?>
                </dl>
            </div>
        </div>
    <?php endforeach; ?>
</div>

<div class="alert alert-info small d-flex gap-2">
    <i class="bi bi-shield-lock flex-shrink-0"></i>
    <div>
        Al terminar se creará <code>config/config.php</code>, se borrará la clave de instalación y este asistente quedará
        bloqueado. Si algo falla al crear las tablas, se revierten automáticamente y no se toca ningún dato existente.
    </div>
</div>

<form method="post" action="<?= e(url('/instalacion/ejecutar')) ?>" class="needs-loading">
    <?= csrf_field() ?>
    <div class="d-flex justify-content-between align-items-center">
        <a href="<?= e(url('/instalacion/administrador')) ?>" class="btn btn-link px-0"><i class="bi bi-arrow-left me-1"></i>Atrás</a>
        <button type="submit" class="btn btn-success btn-lg px-4">
            <span class="spinner-border spinner-border-sm me-1 d-none" aria-hidden="true"></span>
            <i class="bi bi-rocket-takeoff me-1"></i>Instalar ahora
        </button>
    </div>
</form>
