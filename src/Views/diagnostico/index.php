<?php
/**
 * @var list<array{archivo: string, aplicada: bool, faltan: list<string>}> $migraciones
 * @var list<array{nombre: string, ok: bool, para: string}> $extensiones
 * @var list<array{ruta: string, ok: bool, detalle: string}> $carpetas
 * @var list<string> $errores
 * @var array<string, string> $config
 */
$pendientes = array_values(array_filter($migraciones, static fn (array $m): bool => !$m['aplicada']));
$ok = static fn (bool $b): string => $b
    ? '<span class="text-success"><i class="bi bi-check-circle-fill me-1"></i>Bien</span>'
    : '<span class="text-danger"><i class="bi bi-x-octagon-fill me-1"></i>Revisar</span>';
?>
<div class="d-flex justify-content-between align-items-center mb-3">
    <h1 class="h4 mb-0"><i class="bi bi-heart-pulse me-1"></i>Diagnóstico</h1>
</div>
<?php if ($pendientes !== []): ?>
    <div class="msg msg-error mb-4"><span class="msg-ico"><i class="bi bi-database-exclamation"></i></span><div class="msg-cuerpo">
        <div class="msg-titulo">Faltan <?= count($pendientes) ?> migración(es) de la base de datos</div>
        <div class="msg-texto">Impórtalas en phpMyAdmin › tu base › Importar, en este orden: <strong><?= e(implode(', ', array_column($pendientes, 'archivo'))) ?></strong>. Mientras falten, algunas páginas mostrarán "Error del servidor".</div>
    </div></div>
<?php endif; ?>
<div class="row g-4">
    <div class="col-lg-6">
        <div class="card border-0 shadow-sm mb-4">
            <div class="card-header bg-white fw-semibold">Migraciones de la base de datos</div>
            <ul class="list-group list-group-flush small">
                <?php foreach ($migraciones as $m): ?>
                    <li class="list-group-item d-flex justify-content-between gap-2">
                        <span class="font-monospace"><?= e($m['archivo']) ?><?php if (!$m['aplicada']): ?><div class="text-danger font-sans-serif">Falta: <?= e(implode(', ', array_slice($m['faltan'], 0, 4))) ?><?= count($m['faltan']) > 4 ? '…' : '' ?></div><?php endif; ?></span>
                        <?= $m['aplicada'] ? '<span class="text-success text-nowrap"><i class="bi bi-check-circle-fill me-1"></i>Aplicada</span>' : '<span class="text-danger text-nowrap"><i class="bi bi-x-octagon-fill me-1"></i>Pendiente</span>' ?>
                    </li>
                <?php endforeach; ?>
            </ul>
        </div>
        <div class="card border-0 shadow-sm mb-4">
            <div class="card-header bg-white fw-semibold">Configuración</div>
            <dl class="card-body small mb-0 detail-list">
                <?php foreach ($config as $k => $v): ?><dt><?= e($k) ?></dt><dd><?= e($v) ?></dd><?php endforeach; ?>
            </dl>
        </div>
    </div>
    <div class="col-lg-6">
        <div class="card border-0 shadow-sm mb-4">
            <div class="card-header bg-white fw-semibold">Extensiones de PHP</div>
            <table class="table table-sm small align-middle mb-0">
                <?php foreach ($extensiones as $x): ?>
                    <tr><td class="font-monospace"><?= e($x['nombre']) ?></td><td class="text-body-secondary"><?= e($x['para']) ?></td><td class="text-end text-nowrap"><?= $ok($x['ok']) ?></td></tr>
                <?php endforeach; ?>
            </table>
            <div class="card-body small text-body-secondary pt-2">Se activan en cPanel › Select PHP Version › Extensions.</div>
        </div>
        <div class="card border-0 shadow-sm mb-4">
            <div class="card-header bg-white fw-semibold">Carpetas</div>
            <table class="table table-sm small align-middle mb-0">
                <?php foreach ($carpetas as $c): ?>
                    <tr><td class="font-monospace"><?= e($c['ruta']) ?></td><td class="text-body-secondary"><?= e($c['detalle']) ?></td><td class="text-end text-nowrap"><?= $ok($c['ok']) ?></td></tr>
                <?php endforeach; ?>
            </table>
            <div class="card-body small text-body-secondary pt-2">Si alguna no existe, créala con el Administrador de archivos (permisos 755).</div>
        </div>
    </div>
</div>
<div class="card border-0 shadow-sm">
    <div class="card-header bg-white fw-semibold">Últimos errores registrados <span class="fw-normal small text-body-secondary">(storage/logs/php-error.log)</span></div>
    <?php if ($errores === []): ?>
        <div class="card-body small text-body-secondary">Sin errores registrados.</div>
    <?php else: ?>
        <pre class="card-body small mb-0" style="max-height: 24rem; overflow: auto; white-space: pre-wrap;"><?= e(implode("\n", $errores)) ?></pre>
    <?php endif; ?>
</div>
