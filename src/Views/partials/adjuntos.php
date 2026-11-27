<?php
/**
 * Lista de adjuntos de un ticket o comentario (descarga con permiso).
 *
 * @var list<array<string, mixed>> $adjuntos
 */
if ($adjuntos === []) {
    return;
}
?>
<div class="d-flex flex-wrap gap-2 mt-2">
    <?php foreach ($adjuntos as $a):
        $icono = str_starts_with((string) $a['mime'], 'image/') ? 'bi-file-earmark-image' : ((string) $a['mime'] === 'application/pdf' ? 'bi-file-earmark-pdf' : 'bi-file-earmark');
        ?>
        <a class="btn btn-sm btn-outline-secondary text-truncate" style="max-width: 16rem;" href="<?= e(url('/tickets/archivo/' . (int) $a['id'])) ?>">
            <i class="bi <?= e($icono) ?> me-1"></i><?= e($a['nombre_original']) ?>
            <span class="text-body-secondary small">· <?= e(number_format((int) $a['tamano'] / 1024, 0)) ?> KB</span>
            <?php if ((int) $a['interno'] === 1): ?><i class="bi bi-lock ms-1" title="Solo TI"></i><?php endif; ?>
        </a>
    <?php endforeach; ?>
</div>
