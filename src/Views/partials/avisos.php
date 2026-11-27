<?php
/**
 * Avisos vigentes en el inicio. Los que piden confirmación "laten" hasta que
 * la persona pulsa "Enterado" (se guarda en aviso_lecturas, por usuario).
 * Ícono + texto de importancia, nunca solo color.
 *
 * @var list<array<string, mixed>> $avisos
 */
$imp = \App\Services\AvisoService::IMPORTANCIAS;
if ($avisos === []) {
    return;
}
$clase = ['urgente' => 'error', 'importante' => 'advertencia', 'informativo' => 'info'];
?>
<section class="avisos mb-4" id="avisos" aria-label="Avisos">
    <?php foreach ($avisos as $a):
        [$label, $color, $icon] = $imp[$a['importancia']];
        $pendiente = (int) ($a['requiere_confirmacion'] ?? 0) === 1 && ($a['leido_en'] ?? null) === null && array_key_exists('leido_en', $a);
        ?>
        <article class="aviso-card msg-<?= e($clase[$a['importancia']] ?? 'info') ?> <?= $pendiente ? 'is-pendiente' : '' ?>">
            <?php if (!empty($a['imagen'])): ?>
                <img class="aviso-miniatura" src="<?= e(url('/avisos/' . (int) $a['id'] . '/imagen') . '?v=' . substr(md5((string) $a['imagen']), 0, 8)) ?>" alt="" loading="lazy">
            <?php else: ?>
                <span class="msg-ico" aria-hidden="true"><i class="bi <?= e($icon) ?>"></i></span>
            <?php endif; ?>
            <div class="flex-grow-1 min-w-0">
                <div class="d-flex flex-wrap align-items-center gap-2 mb-1">
                    <span class="aviso-chip"><?= e($label) ?></span>
                    <?php if ($pendiente): ?><span class="aviso-nuevo">Nuevo</span><?php endif; ?>
                </div>
                <h2 class="h6 mb-1 fw-semibold"><?= e($a['titulo']) ?></h2>
                <div class="small"><?= texto_multilinea($a['mensaje']) ?></div>
                <div class="d-flex flex-wrap justify-content-between align-items-center gap-2 mt-2">
                    <span class="small text-body-secondary">Vigente hasta <?= e(fecha_mx($a['termina_en'])) ?><?= !empty($a['autor']) ? ' · ' . e($a['autor']) : '' ?></span>
                    <?php if ($pendiente): ?>
                        <form method="post" action="<?= e(url('/avisos/' . (int) $a['id'] . '/leido')) ?>" class="m-0">
                            <?= csrf_field() ?>
                            <button type="submit" class="btn btn-sm aviso-enterado"><i class="bi bi-check2 me-1"></i>Enterado</button>
                        </form>
                    <?php elseif (!empty($a['leido_en'])): ?>
                        <span class="small aviso-leido"><i class="bi bi-check2-circle me-1"></i>Leído el <?= e(fecha_mx((string) $a['leido_en'])) ?></span>
                    <?php endif; ?>
                </div>
            </div>
        </article>
    <?php endforeach; ?>
</section>
