<?php
/**
 * @var array{estado: string, instalacion: int, tipo: string, q: string} $filtros
 * @var list<array<string, mixed>> $items
 * @var list<string> $tipos
 * @var list<array{id: int, nombre: string}> $instalaciones
 */
use App\Services\ResguardoService;

$conteo = array_count_values(array_column($items, 'estado'));
?>
<div class="d-flex flex-wrap justify-content-between align-items-center gap-2 mb-3">
    <h1 class="h4 mb-0"><i class="bi bi-box-seam me-1"></i>Resguardo de equipos</h1>
    <div class="d-flex gap-2">
        <a href="<?= e(url('/resguardo/disponibles')) ?>" class="btn btn-outline-primary"><i class="bi bi-check2-square me-1"></i>Disponibles</a>
        <a href="<?= e(url('/resguardo/recibir')) ?>" class="btn btn-primary"><i class="bi bi-box-arrow-in-down me-1"></i>Recibir equipo</a>
    </div>
</div>
<p class="small text-body-secondary">Equipos que dejan los trabajadores (baja, cambio, renuncia…) mientras esperan asignación. Las actas de baja y responsivas las genera el GECA; aquí queda lo que se dejó.</p>

<?= partial('resguardo_filtros', ['filtros' => $filtros, 'tipos' => $tipos, 'instalaciones' => $instalaciones, 'accion' => '/resguardo', 'conEstado' => true]) ?>

<div class="d-flex flex-wrap gap-2 mb-3 small">
    <?php foreach (ResguardoService::ESTADOS as $k => [$label, $color, $ico]): if (empty($conteo[$k])) { continue; } ?>
        <span class="badge text-bg-<?= e($color) ?>"><i class="bi <?= e($ico) ?> me-1"></i><?= e($label) ?>: <?= e($conteo[$k]) ?></span>
    <?php endforeach; ?>
</div>

<?php if ($items === []): ?>
    <div class="card border-0 shadow-sm"><div class="card-body small text-body-secondary">No hay equipos con esos filtros.</div></div>
<?php else: ?>
    <div class="list-group shadow-sm">
        <?php foreach ($items as $it): [$label, $color, $ico] = ResguardoService::ESTADOS[$it['estado']]; $dejo = ResguardoService::dejo($it); ?>
            <div class="list-group-item py-3">
                <div class="d-flex flex-wrap justify-content-between gap-2">
                    <div class="me-auto">
                        <div class="fw-semibold"><?= e(tipo_equipo((string) ($it['tipo_componente'] ?? '')) ?: 'Equipo') ?> · <span class="font-monospace"><?= e($it['serie']) ?></span>
                            <span class="fw-normal text-body-secondary"><?= e(trim(($it['marca'] ?? '') . ' ' . ($it['modelo'] ?? ''))) ?></span></div>
                        <div class="small text-body-secondary">
                            <a class="font-monospace" href="<?= e(url('/resguardo/' . (int) $it['recepcion_id'])) ?>"><?= e($it['folio']) ?></a>
                            · <?= e(ResguardoService::MOTIVOS[$it['motivo']] ?? $it['motivo']) ?>
                            · <?= e($it['ficha_entrega'] ? $it['ficha_entrega'] . ' ' . $it['nombre_entrega'] : 'Sin trabajador') ?>
                            · <?= e(fecha_mx($it['recibido_en'], false)) ?> · <?= e($it['instalacion'] ?? 'Sin instalación') ?>
                        </div>
                        <div class="small mt-1">
                            <span class="text-body-secondary">Dejó:</span> <?= $dejo === [] ? '<span class="text-body-secondary">solo el equipo</span>' : e(implode(', ', $dejo)) ?>
                            · <?= e(ResguardoService::CONDICIONES[$it['condicion']]) ?><?= $it['observaciones'] ? ' · ' . e($it['observaciones']) : '' ?>
                        </div>
                    </div>
                    <span class="badge text-bg-<?= e($color) ?> align-self-start"><i class="bi <?= e($ico) ?> me-1"></i><?= e($label) ?></span>
                </div>
                <div class="mt-2"><?= partial('resguardo_acciones', ['item' => $it, 'volver' => 'lista']) ?></div>
            </div>
        <?php endforeach; ?>
    </div>
<?php endif; ?>
