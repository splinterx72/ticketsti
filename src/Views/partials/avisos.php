<?php
/**
 * Avisos vigentes (inicio y pantalla de entrada). Ícono + texto de importancia,
 * nunca solo color.
 *
 * @var list<array<string, mixed>> $avisos
 */
$imp = \App\Services\AvisoService::IMPORTANCIAS;
if ($avisos === []) {
    return;
}
?>
<section class="avisos mb-4" aria-label="Avisos">
    <?php foreach ($avisos as $a): [$label, $color, $icon] = $imp[$a['importancia']]; ?>
        <article class="aviso aviso-<?= e($a['importancia']) ?> card border-0 shadow-sm mb-2">
            <div class="card-body py-3 d-flex gap-3">
                <div class="aviso-icono text-<?= e($color) ?>"><i class="bi <?= e($icon) ?>"></i></div>
                <div class="flex-grow-1">
                    <div class="d-flex flex-wrap align-items-center gap-2 mb-1">
                        <span class="badge text-bg-<?= e($color) ?>"><?= e($label) ?></span>
                        <h2 class="h6 mb-0 fw-semibold"><?= e($a['titulo']) ?></h2>
                    </div>
                    <div class="small"><?= texto_multilinea($a['mensaje']) ?></div>
                    <div class="small text-body-secondary mt-1">Vigente hasta <?= e(fecha_mx($a['termina_en'])) ?><?= !empty($a['autor']) ? ' · ' . e($a['autor']) : '' ?></div>
                </div>
            </div>
        </article>
    <?php endforeach; ?>
</section>
