<?php
/**
 * @var array<string, mixed> $stats
 * @var array<string, mixed>|null $ultimaCarga
 * @var list<array<string, mixed>> $misEquipos
 * @var bool $esTi
 * @var array<string, mixed> $authUser
 */
$n = static fn (string $k): string => number_format((int) ($stats[$k] ?? 0));
?>
<div class="d-flex flex-wrap justify-content-between align-items-center gap-2 mb-4">
    <div>
        <h1 class="h4 mb-0">Hola, <?= e($authUser['nombre']) ?></h1>
        <span class="text-body-secondary small">Ficha <?= e($authUser['ficha']) ?></span>
    </div>
    <?php if ($esTi): ?>
        <a href="<?= e(url('/geca')) ?>" class="btn btn-primary"><i class="bi bi-cloud-arrow-up me-1"></i>Cargar GECA</a>
    <?php endif; ?>
</div>

<?php if ($esTi): ?>
    <div class="row g-3 mb-4">
        <?php
        $tiles = [
            ['equipos_vigentes', 'Equipos vigentes en GECA', 'bi-pc-display', 'primary'],
            ['equipos_no_vigentes', 'Equipos no vigentes', 'bi-archive', 'secondary'],
            ['equipos_discrepancia', 'Ubicación real ≠ GECA', 'bi-geo-alt', 'warning'],
            ['usuarios', 'Trabajadores en GECA', 'bi-people', 'info'],
            ['usuarios_registrados', 'Cuentas activadas', 'bi-person-check', 'success'],
            ['ubicaciones', 'Ubicaciones', 'bi-building', 'dark'],
        ];
        foreach ($tiles as [$key, $label, $icon, $color]): ?>
            <div class="col-6 col-md-4 col-xl-2">
                <div class="card h-100 border-0 shadow-sm stat-card">
                    <div class="card-body">
                        <i class="bi <?= e($icon) ?> text-<?= e($color) ?> fs-4"></i>
                        <div class="fs-3 fw-semibold mt-1"><?= e($n($key)) ?></div>
                        <div class="small text-body-secondary"><?= e($label) ?></div>
                    </div>
                </div>
            </div>
        <?php endforeach; ?>
    </div>

    <div class="card border-0 shadow-sm mb-4">
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

<div class="card border-0 shadow-sm">
    <div class="card-header bg-white fw-semibold"><i class="bi bi-laptop me-1"></i>Mis equipos en resguardo</div>
    <?php if ($misEquipos === []): ?>
        <div class="card-body text-body-secondary">No tienes equipos asignados en el GECA.</div>
    <?php else: ?>
        <div class="table-responsive">
            <table class="table table-hover align-middle mb-0">
                <thead class="table-light">
                <tr>
                    <th>Serie</th><th>Marca / Modelo</th><th>Monitores</th><th>Inmueble (GECA)</th><th>Estado</th>
                </tr>
                </thead>
                <tbody>
                <?php foreach ($misEquipos as $eq): ?>
                    <tr>
                        <td class="fw-semibold">
                            <?= e($eq['serie']) ?>
                            <?php if ((int) $eq['es_principal'] === 1): ?><span class="badge text-bg-primary ms-1">Principal</span><?php endif; ?>
                        </td>
                        <td><?= e(trim(($eq['marca'] ?? '') . ' ' . ($eq['modelo'] ?? ''))) ?: '—' ?></td>
                        <td class="small"><?= e(implode(', ', array_filter([$eq['serie_monitor1'], $eq['serie_monitor2']]))) ?: '—' ?></td>
                        <td><?= e($eq['inmueble_geca'] ?? '—') ?></td>
                        <td>
                            <?php if ((int) $eq['vigente_en_geca'] === 1): ?>
                                <span class="badge text-bg-success">Vigente</span>
                            <?php else: ?>
                                <span class="badge text-bg-secondary">No vigente</span>
                            <?php endif; ?>
                        </td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    <?php endif; ?>
</div>
