<?php
/**
 * @var array{estado: string, instalacion: int, tipo: string, q: string} $filtros
 * @var list<array<string, mixed>> $items
 * @var list<string> $tipos
 * @var list<array{id: int, nombre: string}> $instalaciones
 */
use App\Services\ResguardoService;
?>
<div class="d-flex flex-wrap justify-content-between align-items-center gap-2 mb-3">
    <h1 class="h4 mb-0"><i class="bi bi-check2-square me-1"></i>Equipos disponibles para asignar</h1>
    <a href="<?= e(url('/resguardo')) ?>" class="btn btn-link"><i class="bi bi-arrow-left me-1"></i>Resguardo</a>
</div>
<p class="small text-body-secondary">Al asignar, escribe la ficha del trabajador. El equipo queda "pendiente en GECA" y se confirma solo cuando la carga semanal lo trae a su nombre.</p>

<?= partial('resguardo_filtros', ['filtros' => $filtros, 'tipos' => $tipos, 'instalaciones' => $instalaciones, 'accion' => '/resguardo/disponibles', 'conEstado' => false]) ?>

<?php if ($items === []): ?>
    <div class="card border-0 shadow-sm"><div class="card-body small text-body-secondary">No hay equipos disponibles con esos filtros.</div></div>
<?php else: ?>
    <div class="small text-body-secondary mb-2"><?= e(count($items)) ?> equipo<?= count($items) === 1 ? '' : 's' ?> disponible<?= count($items) === 1 ? '' : 's' ?>.</div>
    <div class="row g-3">
        <?php foreach ($items as $it): $dejo = ResguardoService::dejo($it); ?>
            <div class="col-md-6 col-xl-4">
                <div class="card border-0 shadow-sm h-100">
                    <div class="card-body">
                        <div class="small text-body-secondary"><?= e(tipo_equipo((string) ($it['tipo_componente'] ?? '')) ?: 'Equipo') ?> · <?= e($it['instalacion'] ?? 'Sin instalación') ?></div>
                        <div class="fw-semibold font-monospace"><?= e($it['serie']) ?></div>
                        <div><?= e(trim(($it['marca'] ?? '') . ' ' . ($it['modelo'] ?? ''))) ?></div>
                        <div class="small mt-2"><span class="text-body-secondary">Incluye:</span> <?= $dejo === [] ? 'solo el equipo' : e(implode(', ', $dejo)) ?></div>
                        <div class="small"><span class="text-body-secondary">Condición:</span> <?= e(ResguardoService::CONDICIONES[$it['condicion']]) ?><?= $it['observaciones'] ? ' · ' . e($it['observaciones']) : '' ?></div>
                        <div class="small text-body-secondary">Lo dejó <?= e($it['ficha_entrega'] ? $it['ficha_entrega'] . ' ' . $it['nombre_entrega'] : 'sin trabajador') ?> · <a class="font-monospace" href="<?= e(url('/resguardo/' . (int) $it['recepcion_id'])) ?>"><?= e($it['folio']) ?></a></div>
                    </div>
                    <div class="card-footer bg-white"><?= partial('resguardo_acciones', ['item' => $it, 'volver' => 'disponibles']) ?></div>
                </div>
            </div>
        <?php endforeach; ?>
    </div>
<?php endif; ?>
