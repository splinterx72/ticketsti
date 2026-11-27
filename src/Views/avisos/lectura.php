<?php
/**
 * @var array<string, mixed> $aviso
 * @var array{total: int, leidos: int, faltan: list<array<string, mixed>>} $lectura
 */
$pct = $lectura['total'] > 0 ? (int) round($lectura['leidos'] * 100 / $lectura['total']) : 0;
$faltanN = $lectura['total'] - $lectura['leidos'];
?>
<div class="d-flex justify-content-between align-items-center mb-3">
    <h1 class="h4 mb-0"><i class="bi bi-eye me-1"></i>Lectura del aviso</h1>
    <a href="<?= e(url('/supervisor/avisos')) ?>" class="btn btn-link"><i class="bi bi-arrow-left me-1"></i>Avisos</a>
</div>
<div class="card border-0 shadow-sm mb-4">
    <div class="card-body">
        <div class="fw-semibold fs-5"><?= e($aviso['titulo']) ?></div>
        <div class="small text-body-secondary mb-3">Vigente hasta <?= e(fecha_mx((string) $aviso['termina_en'])) ?></div>
        <div class="progress mb-2" style="height: .7rem;" role="progressbar" aria-valuenow="<?= e($pct) ?>" aria-valuemin="0" aria-valuemax="100" aria-label="Leído">
            <div class="progress-bar" style="width: <?= e($pct) ?>%"></div>
        </div>
        <div class="d-flex justify-content-between small">
            <span><strong><?= e($lectura['leidos']) ?></strong> de <?= e($lectura['total']) ?> lo confirmaron</span><span><?= e($pct) ?> %</span>
        </div>
    </div>
</div>
<div class="card border-0 shadow-sm">
    <div class="card-header bg-white fw-semibold">Faltan <?= e($faltanN) ?></div>
    <?php if ($lectura['faltan'] === []): ?>
        <div class="card-body small text-success-emphasis"><i class="bi bi-check2-all me-1"></i>Todos lo confirmaron.</div>
    <?php else: ?>
        <ul class="list-group list-group-flush small">
            <?php foreach ($lectura['faltan'] as $f): ?>
                <li class="list-group-item d-flex justify-content-between"><a href="<?= e(url('/ti/usuarios/' . (int) $f['id'])) ?>"><?= e($f['nombre']) ?></a><span class="font-monospace text-body-secondary"><?= e($f['ficha']) ?></span></li>
            <?php endforeach; ?>
        </ul>
        <?php if ($faltanN > count($lectura['faltan'])): ?><div class="card-footer bg-white small text-body-secondary">Se muestran los primeros <?= e(count($lectura['faltan'])) ?>.</div><?php endif; ?>
    <?php endif; ?>
</div>
