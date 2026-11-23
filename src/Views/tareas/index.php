<?php
/**
 * @var bool $esSupervisor
 * @var list<array<string, mixed>> $asignadas
 * @var list<array<string, mixed>> $mias
 */
$estados = \App\Services\TareaService::ESTADOS;
$ahora = date('Y-m-d H:i:s');
$vence = static function (string $entrega, bool $cerrada) use ($ahora): string {
    if ($cerrada) {
        return '';
    }
    return $entrega < $ahora ? '<span class="badge text-bg-danger ms-1"><i class="bi bi-alarm me-1"></i>Vencida</span>' : '';
};
?>
<div class="d-flex flex-wrap justify-content-between align-items-center gap-2 mb-3">
    <h1 class="h4 mb-0"><i class="bi bi-list-task me-1"></i>Tareas</h1>
    <?php if ($esSupervisor): ?>
        <a href="<?= e(url('/tareas/nueva')) ?>" class="btn btn-primary"><i class="bi bi-plus-lg me-1"></i>Nueva tarea</a>
    <?php endif; ?>
</div>

<?php if ($esSupervisor): ?>
    <h2 class="h6 text-uppercase text-body-secondary fw-semibold mb-2">Asignadas por mí</h2>
    <?php if ($asignadas === []): ?>
        <div class="card border-0 shadow-sm mb-4"><div class="card-body text-body-secondary small">Aún no asignas tareas.</div></div>
    <?php else: ?>
        <div class="list-group shadow-sm mb-4">
            <?php foreach ($asignadas as $t):
                $total = max(1, (int) $t['total']);
                $cerrada = (int) $t['aceptadas'] === (int) $t['total'];
                ?>
                <a href="<?= e(url('/tareas/' . (int) $t['id'])) ?>" class="list-group-item list-group-item-action py-3">
                    <div class="d-flex flex-wrap justify-content-between gap-2">
                        <div class="me-auto">
                            <div class="small text-body-secondary font-monospace"><?= e($t['folio']) ?></div>
                            <div class="fw-semibold"><?= e($t['titulo']) ?> <?= $vence((string) $t['entrega_en'], $cerrada) ?></div>
                            <div class="small text-body-secondary">Entrega <?= e(fecha_mx($t['entrega_en'])) ?> · <?= prioridad_badge((string) $t['prioridad']) ?></div>
                        </div>
                        <div class="text-end" style="min-width: 11rem;">
                            <div class="small mb-1">
                                <strong><?= e($t['entregadas']) ?></strong> de <?= e($t['total']) ?> entregaron
                                <?php if ((int) $t['por_revisar'] > 0): ?><span class="badge text-bg-info ms-1"><?= e($t['por_revisar']) ?> por revisar</span><?php endif; ?>
                            </div>
                            <div class="progress" style="height: 6px;" role="progressbar" aria-label="Avance" aria-valuenow="<?= e($t['aceptadas']) ?>" aria-valuemax="<?= e($t['total']) ?>">
                                <div class="progress-bar bg-success" style="width: <?= e(round((int) $t['aceptadas'] / $total * 100)) ?>%"></div>
                                <div class="progress-bar bg-info" style="width: <?= e(round(((int) $t['entregadas'] - (int) $t['aceptadas']) / $total * 100)) ?>%"></div>
                            </div>
                        </div>
                    </div>
                </a>
            <?php endforeach; ?>
        </div>
    <?php endif; ?>
<?php endif; ?>

<h2 class="h6 text-uppercase text-body-secondary fw-semibold mb-2">Mis tareas</h2>
<?php if ($mias === []): ?>
    <div class="card border-0 shadow-sm"><div class="card-body text-body-secondary small">No tienes tareas asignadas.</div></div>
<?php else: ?>
    <div class="list-group shadow-sm">
        <?php foreach ($mias as $t): [$label, $color] = $estados[$t['estado']]; ?>
            <a href="<?= e(url('/tareas/' . (int) $t['id'])) ?>" class="list-group-item list-group-item-action py-3">
                <div class="d-flex flex-wrap justify-content-between gap-2">
                    <div>
                        <div class="small text-body-secondary font-monospace"><?= e($t['folio']) ?> · <?= e($t['supervisor_nombre'] ?? '') ?></div>
                        <div class="fw-semibold"><?= e($t['titulo']) ?> <?= $vence((string) $t['entrega_en'], in_array($t['estado'], ['entregada', 'aceptada'], true)) ?></div>
                        <div class="small text-body-secondary">Entrega <?= e(fecha_mx($t['entrega_en'])) ?></div>
                    </div>
                    <span class="badge text-bg-<?= e($color) ?> align-self-start"><?= e($label) ?></span>
                </div>
            </a>
        <?php endforeach; ?>
    </div>
<?php endif; ?>
