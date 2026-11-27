<?php
/**
 * "Mi día" del personal de TI: asignados, ruta por instalación › edificio › nivel y tareas.
 *
 * @var list<array<string, mixed>> $asignados
 * @var list<array<string, mixed>> $ruta
 * @var list<array<string, mixed>> $misTareas
 */
?>
<div class="row g-4 mb-4">
    <div class="col-xl-6">
        <div class="card border-0 shadow-sm h-100">
            <div class="card-header bg-white d-flex justify-content-between align-items-center">
                <span class="fw-semibold"><i class="bi bi-person-check me-1"></i>Mis tickets asignados</span>
                <a class="small" href="<?= e(url('/ti/tickets?vista=mios')) ?>">Ver todos</a>
            </div>
            <?php if ($asignados === []): ?>
                <div class="card-body small text-body-secondary">No tienes tickets asignados. Revisa los que están <a href="<?= e(url('/ti/tickets?vista=sin_asignar')) ?>">por tomar</a>.</div>
            <?php else: ?>
                <div class="list-group list-group-flush">
                    <?php foreach ($asignados as $t): ?>
                        <a class="list-group-item list-group-item-action" href="<?= e(url('/tickets/' . (int) $t['id'])) ?>">
                            <div class="d-flex justify-content-between gap-2">
                                <span class="text-truncate"><span class="font-monospace small text-body-secondary"><?= e($t['folio']) ?></span> <?= e($t['titulo']) ?></span>
                                <?= sla_badge($t['sla'], false) ?>
                            </div>
                            <div class="small text-body-secondary d-flex flex-wrap gap-2 mt-1">
                                <?= prioridad_badge($t['prioridad']) ?>
                                <span><i class="bi bi-geo-alt me-1"></i><?= e(implode(' › ', array_filter([$t['instalacion'], $t['ubicacion'], $t['nivel'] !== null ? 'Nivel ' . $t['nivel'] : null])) ?: 'Sin lugar indicado') ?></span>
                            </div>
                        </a>
                    <?php endforeach; ?>
                </div>
            <?php endif; ?>
        </div>
    </div>

    <div class="col-xl-6">
        <div class="card border-0 shadow-sm h-100">
            <div class="card-header bg-white fw-semibold"><i class="bi bi-signpost-split me-1"></i>Ruta del día: tickets abiertos por edificio y nivel</div>
            <?php if ($ruta === []): ?>
                <div class="card-body small text-body-secondary">No hay tickets abiertos en tus instalaciones.</div>
            <?php else: ?>
                <div class="card-body pt-2">
                    <?php foreach ($ruta as $inst): ?>
                        <div class="ruta-inst">
                            <a class="fw-semibold text-body text-decoration-none" href="<?= e(url('/ti/tickets?vista=abiertos&inst=' . (int) $inst['id'])) ?>">
                                <i class="bi bi-buildings me-1 text-primary"></i><?= e($inst['nombre']) ?>
                            </a>
                            <span class="badge rounded-pill text-bg-light border ms-1"><?= e($inst['total']) ?></span>
                            <ul class="list-unstyled ruta-lugares mb-2">
                                <?php foreach ($inst['lugares'] as $l):
                                    $q = ['vista' => 'abiertos', 'inst' => $inst['id']];
                                    if ($l['ubicacion_id'] !== null) { $q['ubic'] = $l['ubicacion_id']; }
                                    if ($l['nivel_id'] !== null) { $q['nivel'] = $l['nivel_id']; }
                                    ?>
                                    <li>
                                        <a class="d-flex justify-content-between gap-2 text-decoration-none text-body" href="<?= e(url('/ti/tickets?' . http_build_query($q))) ?>">
                                            <span class="text-truncate">
                                                <?= e($l['ubicacion']) ?><?= $l['nivel'] !== null ? ' <span class="text-body-secondary">· Nivel ' . e($l['nivel']) . '</span>' : '' ?>
                                            </span>
                                            <span class="text-nowrap small">
                                                <strong><?= e($l['total']) ?></strong> ticket<?= $l['total'] === 1 ? '' : 's' ?>
                                                <?php if ($l['mios'] > 0): ?><span class="badge text-bg-primary ms-1"><?= e($l['mios']) ?> míos</span><?php endif; ?>
                                                <?php if ($l['sin_asignar'] > 0): ?><span class="badge text-bg-light border ms-1"><?= e($l['sin_asignar']) ?> por tomar</span><?php endif; ?>
                                                <?php if ($l['vencidos'] > 0): ?><span class="sla-badge sla-vencido ms-1"><i class="bi bi-alarm"></i> <?= e($l['vencidos']) ?></span><?php endif; ?>
                                            </span>
                                        </a>
                                        <?php if (!empty($l['indicaciones'])): ?>
                                            <div class="small text-warning-emphasis"><i class="bi bi-exclamation-triangle me-1"></i><?= e($l['indicaciones']) ?></div>
                                        <?php endif; ?>
                                    </li>
                                <?php endforeach; ?>
                            </ul>
                        </div>
                    <?php endforeach; ?>
                </div>
            <?php endif; ?>
        </div>
    </div>

    <?php if ($misTareas !== []): ?>
        <div class="col-12">
            <div class="card border-0 shadow-sm">
                <div class="card-header bg-white d-flex justify-content-between align-items-center">
                    <span class="fw-semibold"><i class="bi bi-list-task me-1"></i>Mis tareas pendientes</span>
                    <a class="small" href="<?= e(url('/tareas')) ?>">Ver todas</a>
                </div>
                <div class="list-group list-group-flush">
                    <?php foreach (array_slice($misTareas, 0, 5) as $t): $vence = strtotime((string) $t['entrega_en']); ?>
                        <a class="list-group-item list-group-item-action d-flex justify-content-between gap-2" href="<?= e(url('/tareas/' . (int) $t['id'])) ?>">
                            <span class="text-truncate"><span class="font-monospace small text-body-secondary"><?= e($t['folio']) ?></span> <?= e($t['titulo']) ?></span>
                            <span class="small text-nowrap <?= $vence < time() ? 'text-danger-emphasis fw-semibold' : 'text-body-secondary' ?>"><i class="bi bi-calendar-event me-1"></i><?= e(fecha_mx((string) $t['entrega_en'])) ?></span>
                        </a>
                    <?php endforeach; ?>
                </div>
            </div>
        </div>
    <?php endif; ?>
</div>
