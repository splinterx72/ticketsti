<?php
/**
 * @var list<array{id: int, nombre: string}> $instalaciones
 * @var list<array<string, mixed>> $semaforo
 * @var list<array<string, mixed>> $porInstalacion
 * @var list<array<string, mixed>> $tareas
 */
$luz = [
    'verde'    => ['success', 'bi-check-circle-fill', 'Al día'],
    'amarillo' => ['warning', 'bi-exclamation-triangle-fill', 'Por vencer'],
    'rojo'     => ['danger', 'bi-x-octagon-fill', 'Bloqueado'],
];
$conteo = ['verde' => 0, 'amarillo' => 0, 'rojo' => 0];
foreach ($semaforo as $s) {
    $conteo[$s['color']]++;
}
?>
<div class="d-flex flex-wrap justify-content-between align-items-center gap-2 mb-3">
    <h1 class="h4 mb-0"><i class="bi bi-person-badge me-1"></i>Supervisión</h1>
    <div class="d-flex flex-wrap gap-2">
        <a class="btn btn-primary btn-sm" href="<?= e(url('/tareas/nueva')) ?>"><i class="bi bi-plus-lg me-1"></i>Nueva tarea</a>
        <a class="btn btn-outline-primary btn-sm" href="<?= e(url('/supervisor/avisos/nuevo')) ?>"><i class="bi bi-megaphone me-1"></i>Publicar aviso</a>
        <a class="btn btn-outline-primary btn-sm" href="<?= e(url('/supervisor/ubicaciones')) ?>"><i class="bi bi-geo-alt me-1"></i>Ubicaciones</a>
        <a class="btn btn-outline-primary btn-sm" href="<?= e(url('/supervisor/agentes')) ?>"><i class="bi bi-people me-1"></i>Agentes</a>
    </div>
</div>

<?php if ($instalaciones === []): ?>
    <div class="alert alert-info">Aún no tienes instalaciones a tu cargo. El Admin TI te las asigna en Gestión › Instalaciones.</div>
<?php else: ?>
<div class="row g-3 mb-4">
    <?php foreach ($porInstalacion as $i): ?>
        <div class="col-sm-6 col-xl-4">
            <a href="<?= e(url('/ti/tickets?inst=' . (int) $i['id'])) ?>" class="card h-100 border-0 shadow-sm stat-card text-decoration-none text-body">
                <div class="card-body">
                    <div class="small text-body-secondary text-truncate"><i class="bi bi-buildings me-1"></i><?= e($i['nombre']) ?></div>
                    <div class="d-flex align-items-baseline gap-2 mt-1">
                        <span class="fs-3 fw-semibold stat-value"><?= e($i['abiertos']) ?></span><span class="small text-body-secondary">tickets abiertos</span>
                    </div>
                    <div class="small">
                        <?php if ((int) $i['sin_asignar'] > 0): ?><span class="text-danger-emphasis me-2"><i class="bi bi-person-dash me-1"></i><?= e($i['sin_asignar']) ?> sin asignar</span><?php endif; ?>
                        <?php if ((int) $i['discrepancias'] > 0): ?><span class="text-warning-emphasis"><i class="bi bi-geo-alt me-1"></i><?= e($i['discrepancias']) ?> discrepancias</span><?php endif; ?>
                    </div>
                </div>
            </a>
        </div>
    <?php endforeach; ?>
</div>

<div class="row g-4">
    <div class="col-lg-6">
        <div class="card border-0 shadow-sm h-100">
            <div class="card-header bg-white d-flex flex-wrap justify-content-between align-items-center gap-2">
                <span class="fw-semibold"><i class="bi bi-shield-lock me-1"></i>Contraseña de admin local</span>
                <span class="small d-flex gap-2">
                    <?php foreach ($luz as $k => [$color, $icon, $label]): ?>
                        <span class="text-<?= e($color) ?>-emphasis"><i class="bi <?= e($icon) ?>"></i> <?= e($conteo[$k]) ?> <?= e(mb_strtolower($label)) ?></span>
                    <?php endforeach; ?>
                </span>
            </div>
            <?php if ($semaforo === []): ?>
                <div class="card-body small text-body-secondary">Asigna agentes a tus instalaciones para ver su estado.</div>
            <?php else: ?>
                <ul class="list-group list-group-flush">
                    <?php foreach ($semaforo as $s): [$color, $icon, $label] = $luz[$s['color']]; ?>
                        <li class="list-group-item d-flex justify-content-between align-items-center gap-2">
                            <span>
                                <i class="bi <?= e($icon) ?> text-<?= e($color) ?> me-1" aria-hidden="true"></i>
                                <?= e($s['nombre']) ?> <?= $s['prestado'] ? '<span class="badge text-bg-info">Prestado</span>' : '' ?>
                            </span>
                            <span class="small text-end">
                                <span class="badge text-bg-<?= e($color) ?>"><?= e($label) ?></span>
                                <span class="text-body-secondary d-block">
                                    <?php if ($s['dias'] === null): ?>Sin fecha registrada
                                    <?php elseif ($s['dias'] < 0): ?>Venció hace <?= e(abs($s['dias'])) ?> días
                                    <?php else: ?>Vence <?= e(fecha_mx($s['vence'], false)) ?> (<?= e($s['dias']) ?> días)<?php endif; ?>
                                </span>
                            </span>
                        </li>
                    <?php endforeach; ?>
                </ul>
            <?php endif; ?>
        </div>
    </div>
    <div class="col-lg-6">
        <div class="card border-0 shadow-sm h-100">
            <div class="card-header bg-white d-flex justify-content-between align-items-center">
                <span class="fw-semibold"><i class="bi bi-list-task me-1"></i>Tareas abiertas</span>
                <a class="small" href="<?= e(url('/tareas')) ?>">Ver todas</a>
            </div>
            <?php if ($tareas === []): ?>
                <div class="card-body small text-body-secondary">No hay tareas pendientes.</div>
            <?php else: ?>
                <div class="list-group list-group-flush">
                    <?php foreach ($tareas as $t): ?>
                        <a class="list-group-item list-group-item-action" href="<?= e(url('/tareas/' . (int) $t['id'])) ?>">
                            <div class="d-flex justify-content-between gap-2">
                                <span class="text-truncate"><span class="small font-monospace text-body-secondary"><?= e($t['folio']) ?></span> <?= e($t['titulo']) ?></span>
                                <span class="small text-nowrap">
                                    <?= e($t['entregadas']) ?>/<?= e($t['total']) ?>
                                    <?php if ((int) $t['por_revisar'] > 0): ?><span class="badge text-bg-info ms-1"><?= e($t['por_revisar']) ?> por revisar</span><?php endif; ?>
                                </span>
                            </div>
                            <div class="small text-body-secondary">Entrega <?= e(fecha_mx($t['entrega_en'])) ?></div>
                        </a>
                    <?php endforeach; ?>
                </div>
            <?php endif; ?>
        </div>
    </div>
</div>
<?php endif; ?>
