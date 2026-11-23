<?php
/**
 * @var bool $esTi
 * @var list<array<string, mixed>> $misTickets
 * @var int $misAbiertos
 * @var list<array<string, mixed>> $misEquipos
 * @var array<string, mixed> $stats
 * @var array<string, int> $ticketStats
 * @var array<string, mixed>|null $ultimaCarga
 * @var array<string, mixed> $authUser
 * @var list<array<string, mixed>> $avisos
 */
$n = static fn (array $src, string $k): string => number_format((int) ($src[$k] ?? 0));
?>
<div class="d-flex flex-wrap justify-content-between align-items-center gap-2 mb-4">
    <div>
        <h1 class="h4 mb-0">Hola, <?= e($authUser['nombre']) ?></h1>
        <span class="text-body-secondary small">Ficha <?= e($authUser['ficha']) ?></span>
    </div>
    <div class="d-flex gap-2">
        <?php if ($esTi): ?>
            <a href="<?= e(url('/ti/tickets')) ?>" class="btn btn-outline-primary"><i class="bi bi-kanban me-1"></i>Bandeja TI</a>
        <?php endif; ?>
        <a href="<?= e(url('/tickets/nuevo')) ?>" class="btn btn-primary"><i class="bi bi-plus-lg me-1"></i>Nuevo ticket</a>
    </div>
</div>

<?= partial('avisos', ['avisos' => $avisos]) ?>

<?php if ($esTi): ?>
    <div class="row g-3 mb-4">
        <?php
        $tiles = [
            ['abiertos', 'Tickets abiertos', 'bi-inbox', 'primary', '/ti/tickets?vista=abiertos'],
            ['sin_asignar', 'Sin asignar', 'bi-person-dash', 'danger', '/ti/tickets?vista=sin_asignar'],
            ['mios', 'Asignados a mí', 'bi-person-check', 'info', '/ti/tickets?vista=mios'],
            ['urgentes', 'Alta / crítica', 'bi-exclamation-triangle', 'warning', '/ti/tickets?vista=abiertos&prioridad=alta'],
            ['cerrados_hoy', 'Cerrados hoy', 'bi-check2-circle', 'success', '/ti/tickets?vista=cerrados'],
        ];
        foreach ($tiles as [$key, $label, $icon, $color, $link]): ?>
            <div class="col-6 col-md-4 col-xl">
                <a href="<?= e(url($link)) ?>" class="card h-100 border-0 shadow-sm stat-card text-decoration-none text-body">
                    <div class="card-body">
                        <i class="bi <?= e($icon) ?> text-<?= e($color) ?> fs-4"></i>
                        <div class="fs-3 fw-semibold mt-1"><?= e($n($ticketStats, $key)) ?></div>
                        <div class="small text-body-secondary"><?= e($label) ?></div>
                    </div>
                </a>
            </div>
        <?php endforeach; ?>
    </div>
<?php endif; ?>

<div class="row g-4 mb-4">
    <div class="col-lg-6">
        <div class="card border-0 shadow-sm h-100">
            <div class="card-header bg-white d-flex justify-content-between align-items-center">
                <span class="fw-semibold"><i class="bi bi-ticket-detailed me-1"></i>Mis tickets</span>
                <span class="badge text-bg-primary rounded-pill"><?= e($misAbiertos) ?> abiertos</span>
            </div>
            <?php if ($misTickets === []): ?>
                <div class="card-body text-center text-body-secondary py-4">
                    <p class="mb-2">No has levantado tickets.</p>
                    <a href="<?= e(url('/tickets/nuevo')) ?>" class="btn btn-sm btn-outline-primary">Crear mi primer ticket</a>
                </div>
            <?php else: ?>
                <div class="list-group list-group-flush">
                    <?php foreach ($misTickets as $t): ?>
                        <a href="<?= e(url('/tickets/' . (int) $t['id'])) ?>" class="list-group-item list-group-item-action">
                            <div class="d-flex justify-content-between gap-2">
                                <div class="text-truncate">
                                    <div class="small font-monospace text-body-secondary"><?= e($t['folio']) ?></div>
                                    <div class="text-truncate"><?= e($t['titulo']) ?></div>
                                </div>
                                <div class="text-end flex-shrink-0">
                                    <?= estado_badge($t['estado_nombre'], $t['estado_color']) ?>
                                    <div class="small text-body-secondary"><?= e(hace($t['actualizado_en'])) ?></div>
                                </div>
                            </div>
                        </a>
                    <?php endforeach; ?>
                </div>
                <div class="card-footer bg-white text-end">
                    <a href="<?= e(url('/tickets')) ?>" class="small">Ver todos <i class="bi bi-arrow-right"></i></a>
                </div>
            <?php endif; ?>
        </div>
    </div>

    <div class="col-lg-6">
        <div class="card border-0 shadow-sm h-100">
            <div class="card-header bg-white fw-semibold"><i class="bi bi-laptop me-1"></i>Mis equipos en resguardo</div>
            <?php if ($misEquipos === []): ?>
                <div class="card-body text-body-secondary">No tienes equipos asignados en el GECA.</div>
            <?php else: ?>
                <ul class="list-group list-group-flush">
                    <?php foreach ($misEquipos as $eq): ?>
                        <li class="list-group-item">
                            <div class="d-flex justify-content-between align-items-start gap-2">
                                <div>
                                    <span class="fw-semibold font-monospace"><?= e($eq['serie']) ?></span>
                                    <?php if ((int) $eq['es_principal'] === 1): ?><span class="badge text-bg-primary ms-1"><i class="bi bi-star-fill me-1"></i>Principal</span><?php endif; ?>
                                    <?php if ((int) $eq['vigente_en_geca'] === 0): ?><span class="badge text-bg-secondary ms-1">No vigente</span><?php endif; ?>
                                    <div class="small"><?= e(trim(($eq['marca'] ?? '') . ' ' . ($eq['modelo'] ?? '')) ?: '—') ?></div>
                                    <?php $monitores = array_filter([$eq['serie_monitor1'], $eq['serie_monitor2']]); ?>
                                    <?php if ($monitores !== []): ?>
                                        <div class="small text-body-secondary"><i class="bi bi-display me-1"></i><?= e(implode(', ', $monitores)) ?></div>
                                    <?php endif; ?>
                                    <div class="small text-body-secondary"><i class="bi bi-geo-alt me-1"></i><?= e($eq['ubicacion_real'] ?? $eq['ubicacion_geca'] ?? 'Sin ubicación') ?></div>
                                    <?php if ($eq['ubicacion_real_id'] !== null && $eq['ubicacion_geca_id'] !== null && (int) $eq['ubicacion_real_id'] !== (int) $eq['ubicacion_geca_id']): ?>
                                        <div class="small text-warning-emphasis"><i class="bi bi-exclamation-triangle me-1"></i>En GECA figura en: <?= e($eq['ubicacion_geca']) ?></div>
                                    <?php endif; ?>
                                </div>
                                <?php if ((int) $eq['es_principal'] !== 1): ?>
                                    <form method="post" action="<?= e(url('/equipos/' . (int) $eq['id'] . '/principal')) ?>" class="m-0 flex-shrink-0">
                                        <?= csrf_field() ?>
                                        <button type="submit" class="btn btn-sm btn-outline-secondary" title="Marcar como equipo principal">
                                            <i class="bi bi-star"></i><span class="d-none d-sm-inline ms-1">Principal</span>
                                        </button>
                                    </form>
                                <?php endif; ?>
                            </div>
                        </li>
                    <?php endforeach; ?>
                </ul>
            <?php endif; ?>
        </div>
    </div>
</div>

<?php if ($esTi): ?>
    <h2 class="h6 fw-semibold text-body-secondary text-uppercase small mb-3">Inventario GECA</h2>
    <div class="row g-3 mb-4">
        <?php
        $tiles = [
            ['equipos_vigentes', 'Equipos vigentes', 'bi-pc-display', 'primary'],
            ['equipos_no_vigentes', 'No vigentes', 'bi-archive', 'secondary'],
            ['equipos_discrepancia', 'Ubicación real ≠ GECA', 'bi-geo-alt', 'warning'],
            ['usuarios', 'Trabajadores', 'bi-people', 'info'],
            ['usuarios_registrados', 'Cuentas activadas', 'bi-person-check', 'success'],
            ['ubicaciones', 'Ubicaciones', 'bi-building', 'dark'],
        ];
        foreach ($tiles as [$key, $label, $icon, $color]): ?>
            <div class="col-6 col-md-4 col-xl-2">
                <div class="card h-100 border-0 shadow-sm stat-card">
                    <div class="card-body">
                        <i class="bi <?= e($icon) ?> text-<?= e($color) ?> fs-4"></i>
                        <div class="fs-3 fw-semibold mt-1"><?= e($n($stats, $key)) ?></div>
                        <div class="small text-body-secondary"><?= e($label) ?></div>
                    </div>
                </div>
            </div>
        <?php endforeach; ?>
    </div>

    <div class="card border-0 shadow-sm">
        <div class="card-header bg-white fw-semibold"><i class="bi bi-clock-history me-1"></i>Última carga GECA</div>
        <div class="card-body">
            <?php if ($ultimaCarga === null): ?>
                <p class="mb-0 text-body-secondary">Aún no se ha realizado ninguna carga. Sube el archivo del lunes desde <a href="<?= e(url('/geca')) ?>">Carga GECA</a>.</p>
            <?php else: ?>
                <div class="row small g-2">
                    <div class="col-sm-6 col-lg-3"><span class="text-body-secondary">Fecha:</span> <?= e(fecha_mx($ultimaCarga['fecha_carga'])) ?></div>
                    <div class="col-sm-6 col-lg-3"><span class="text-body-secondary">Archivo:</span> <?= e($ultimaCarga['nombre_archivo']) ?></div>
                    <div class="col-sm-6 col-lg-3"><span class="text-body-secondary">Registros:</span> <?= e(number_format((int) $ultimaCarga['total_registros'])) ?></div>
                    <div class="col-sm-6 col-lg-3"><span class="text-body-secondary">Agente:</span> <?= e($ultimaCarga['agente'] ?? '—') ?></div>
                </div>
                <a href="<?= e(url('/geca/cargas/' . (int) $ultimaCarga['id'])) ?>" class="btn btn-sm btn-outline-primary mt-3">Ver detalle</a>
            <?php endif; ?>
        </div>
    </div>
<?php endif; ?>
