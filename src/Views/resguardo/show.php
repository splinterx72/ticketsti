<?php
/** @var array<string, mixed> $rc */
use App\Services\ResguardoService;
?>
<div class="d-flex flex-wrap justify-content-between align-items-start gap-2 mb-3">
    <div>
        <div class="small text-body-secondary font-monospace"><?= e($rc['folio']) ?> · <?= e(fecha_mx($rc['creado_en'])) ?></div>
        <h1 class="h4 mb-0">Recepción de equipo</h1>
    </div>
    <a href="<?= e(url('/resguardo')) ?>" class="btn btn-link"><i class="bi bi-arrow-left me-1"></i>Resguardo</a>
</div>
<div class="row g-4">
    <div class="col-lg-4">
        <div class="card border-0 shadow-sm">
            <dl class="card-body small mb-0 detail-list">
                <dt>Entregó</dt><dd><?= e($rc['ficha_entrega'] ? $rc['ficha_entrega'] . ' · ' . $rc['nombre_entrega'] : 'Sin trabajador') ?></dd>
                <dt>Motivo</dt><dd><?= e(ResguardoService::MOTIVOS[$rc['motivo']] ?? $rc['motivo']) ?></dd>
                <dt>Se guarda en</dt><dd><?= e($rc['instalacion'] ?? 'Sin instalación') ?></dd>
                <dt>Recibió</dt><dd><?= e($rc['recibio_nombre'] ?? '—') ?></dd>
                <?php if ($rc['observaciones']): ?><dt>Observaciones</dt><dd><?= texto_multilinea((string) $rc['observaciones']) ?></dd><?php endif; ?>
                <dt>Acta GECA</dt>
                <dd><?= $rc['acta_ruta'] ? '<a href="' . e(url('/resguardo/' . (int) $rc['id'] . '/acta')) . '"><i class="bi bi-paperclip me-1"></i>' . e($rc['acta_nombre']) . '</a>' : '<span class="text-body-secondary">No se adjuntó</span>' ?></dd>
            </dl>
        </div>
    </div>
    <div class="col-lg-8">
        <?php foreach ($rc['equipos'] as $it): [$label, $color, $ico] = ResguardoService::ESTADOS[$it['estado']]; $dejo = ResguardoService::dejo($it); ?>
            <div class="card border-0 shadow-sm mb-3">
                <div class="card-header bg-white d-flex justify-content-between align-items-center gap-2">
                    <span class="fw-semibold"><?= e(tipo_equipo((string) ($it['tipo_componente'] ?? '')) ?: 'Equipo') ?> · <span class="font-monospace"><?= e($it['serie']) ?></span>
                        <span class="fw-normal text-body-secondary"><?= e(trim(($it['marca'] ?? '') . ' ' . ($it['modelo'] ?? ''))) ?></span></span>
                    <span class="badge text-bg-<?= e($color) ?>"><i class="bi <?= e($ico) ?> me-1"></i><?= e($label) ?></span>
                </div>
                <div class="card-body small">
                    <div class="mb-1"><span class="text-body-secondary">Dejó:</span> <?= $dejo === [] ? 'solo el equipo' : e(implode(', ', $dejo)) ?></div>
                    <div class="mb-2"><span class="text-body-secondary">Condición:</span> <?= e(ResguardoService::CONDICIONES[$it['condicion']]) ?><?= $it['observaciones'] ? ' · ' . e($it['observaciones']) : '' ?></div>
                    <?php if ($it['asignacion_nota']): ?><div class="mb-2"><span class="text-body-secondary">Nota de asignación:</span> <?= e($it['asignacion_nota']) ?></div><?php endif; ?>
                    <?= partial('resguardo_acciones', ['item' => $it, 'volver' => 'recepcion']) ?>
                </div>
            </div>
        <?php endforeach; ?>
    </div>
</div>
