<?php
/**
 * @var array<string, mixed> $carga
 * @var array<string, mixed>|null $detalle
 */
$cards = [
    ['Registros leídos', (int) $carga['total_registros'], 'primary', 'bi-list-ol'],
    ['Equipos nuevos', (int) $carga['nuevos_insertados'], 'success', 'bi-plus-circle'],
    ['Equipos actualizados', (int) $carga['actualizados'], 'info', 'bi-arrow-repeat'],
    ['No vigentes en GECA', (int) $carga['marcados_no_vigentes'], 'secondary', 'bi-archive'],
];
?>
<div class="d-flex flex-wrap justify-content-between align-items-center gap-2 mb-4">
    <div>
        <h1 class="h4 mb-0">Carga GECA #<?= e($carga['id']) ?></h1>
        <span class="small text-body-secondary">
            <?= e(fecha_mx($carga['fecha_carga'])) ?> · <?= e($carga['nombre_archivo']) ?> · <?= e($carga['agente'] ?? 'Agente no registrado') ?>
        </span>
    </div>
    <a href="<?= e(url('/geca')) ?>" class="btn btn-outline-secondary btn-sm"><i class="bi bi-arrow-left me-1"></i>Volver</a>
</div>

<div class="row g-3 mb-4">
    <?php foreach ($cards as [$label, $value, $color, $icon]): ?>
        <div class="col-6 col-lg-3">
            <div class="card border-0 shadow-sm h-100 stat-card">
                <div class="card-body">
                    <i class="bi <?= e($icon) ?> text-<?= e($color) ?> fs-4"></i>
                    <div class="fs-3 fw-semibold mt-1"><?= e(number_format($value)) ?></div>
                    <div class="small text-body-secondary"><?= e($label) ?></div>
                </div>
            </div>
        </div>
    <?php endforeach; ?>
</div>

<?php if ($detalle !== null): ?>
    <div class="card border-0 shadow-sm mb-4">
        <div class="card-header bg-white fw-semibold"><i class="bi bi-clipboard-data me-1"></i>Detalle de la ejecución</div>
        <div class="card-body">
            <div class="row small g-2">
                <div class="col-sm-6 col-lg-4"><span class="text-body-secondary">Formato:</span> <?= e($detalle['formato']) ?></div>
                <div class="col-sm-6 col-lg-4"><span class="text-body-secondary">Usuarios nuevos:</span> <?= e(number_format((int) $detalle['usuarios_nuevos'])) ?></div>
                <div class="col-sm-6 col-lg-4"><span class="text-body-secondary">Usuarios actualizados:</span> <?= e(number_format((int) $detalle['usuarios_actualizados'])) ?></div>
                <div class="col-sm-6 col-lg-4"><span class="text-body-secondary">Ubicaciones nuevas:</span> <?= e(number_format((int) $detalle['ubicaciones_nuevas'])) ?></div>
                <div class="col-sm-6 col-lg-4"><span class="text-body-secondary">Filas omitidas:</span> <?= e(number_format((int) $detalle['omitidos'])) ?></div>
                <div class="col-sm-6 col-lg-4"><span class="text-body-secondary">Tiempo / memoria:</span> <?= e($detalle['segundos']) ?> s · <?= e($detalle['memoria_pico_mb']) ?> MB</div>
            </div>
        </div>
    </div>

    <?php if (!empty($detalle['advertencias'])): ?>
        <div class="card border-0 shadow-sm">
            <div class="card-header bg-warning-subtle fw-semibold">
                <i class="bi bi-exclamation-triangle me-1"></i>Advertencias (<?= e(count($detalle['advertencias'])) ?>)
            </div>
            <ul class="list-group list-group-flush small warnings-list">
                <?php foreach ($detalle['advertencias'] as $w): ?>
                    <li class="list-group-item"><?= e($w) ?></li>
                <?php endforeach; ?>
            </ul>
        </div>
    <?php else: ?>
        <div class="alert alert-success"><i class="bi bi-check-circle me-1"></i>La carga terminó sin advertencias.</div>
    <?php endif; ?>
<?php endif; ?>
