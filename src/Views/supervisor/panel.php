<?php
/**
 * @var list<array{id: int, nombre: string}> $instalaciones
 * @var list<array<string, mixed>> $semaforo
 * @var list<array<string, mixed>> $porInstalacion
 * @var list<array<string, mixed>> $tareas
 * @var array<int, array{equipos: int, con_garantia: int, discrepancias: int, sin_trabajador: int}> $equipos
 * @var array<int, list<array<string, mixed>>> $tecnicos
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
    <?php foreach ($porInstalacion as $i): $iid = (int) $i['id']; $eq = $equipos[$iid] ?? ['equipos' => 0, 'con_garantia' => 0, 'discrepancias' => 0, 'sin_trabajador' => 0]; $inv = '/ti/equipos?inst=' . $iid; ?>
        <div class="col-md-6 col-xxl-4">
            <div class="card h-100 border-0 shadow-sm stat-card">
                <div class="card-body">
                    <div class="fw-semibold text-truncate"><i class="bi bi-buildings me-1"></i><?= e($i['nombre']) ?></div>
                    <div class="row g-2 mt-1 text-center">
                        <a class="col-4 text-decoration-none text-body" href="<?= e(url('/ti/tickets?inst=' . $iid)) ?>">
                            <div class="fs-4 fw-semibold stat-value"><?= e($i['abiertos']) ?></div><div class="small text-body-secondary">tickets abiertos</div>
                        </a>
                        <a class="col-4 text-decoration-none text-body" href="<?= e(url($inv)) ?>">
                            <div class="fs-4 fw-semibold stat-value"><?= e(number_format($eq['equipos'])) ?></div><div class="small text-body-secondary">equipos</div>
                        </a>
                        <a class="col-4 text-decoration-none text-body" href="<?= e(url('/supervisor/agentes')) ?>">
                            <div class="fs-4 fw-semibold stat-value"><?= e(count($tecnicos[$iid] ?? [])) ?></div><div class="small text-body-secondary">técnicos</div>
                        </a>
                    </div>
                    <div class="small mt-2 d-flex flex-wrap gap-x-3 column-gap-3 row-gap-1">
                        <?php if ((int) $i['sin_asignar'] > 0): ?><span class="text-danger-emphasis"><i class="bi bi-person-dash me-1"></i><?= e($i['sin_asignar']) ?> tickets sin asignar</span><?php endif; ?>
                        <a class="text-success-emphasis text-decoration-none" href="<?= e(url($inv)) ?>"><i class="bi bi-shield-check me-1"></i><?= e(number_format($eq['con_garantia'])) ?> con garantía</a>
                        <?php if ($eq['discrepancias'] > 0): ?><a class="text-warning-emphasis text-decoration-none" href="<?= e(url($inv . '&filtro=discrepancia')) ?>"><i class="bi bi-geo-alt me-1"></i><?= e(number_format($eq['discrepancias'])) ?> fuera de su ubicación GECA</a><?php endif; ?>
                        <?php if ($eq['sin_trabajador'] > 0): ?><span class="text-body-secondary"><i class="bi bi-box-seam me-1"></i><?= e(number_format($eq['sin_trabajador'])) ?> sin trabajador (baja)</span><?php endif; ?>
                    </div>
                    <?php if (!empty($tecnicos[$iid])): ?>
                        <ul class="list-unstyled small mt-2 mb-0 border-top pt-2">
                            <?php foreach ($tecnicos[$iid] as $tc): ?>
                                <li class="d-flex justify-content-between gap-2">
                                    <span class="text-truncate"><?= e($tc['nombre']) ?><?= $tc['tipo'] === 'prestamo' ? ' <span class="badge text-bg-info">Préstamo hasta ' . e(fecha_mx((string) $tc['hasta'], false)) . '</span>' : '' ?></span>
                                    <span class="text-body-secondary text-nowrap"><?= e($tc['abiertos']) ?> abiertos</span>
                                </li>
                            <?php endforeach; ?>
                        </ul>
                    <?php endif; ?>
                </div>
            </div>
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
