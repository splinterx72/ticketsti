<?php
/**
 * @var int|null $id
 * @var array<string, mixed> $aviso
 * @var list<array<string, mixed>> $instalaciones
 * @var array<string, string> $old
 */
$v = static fn (string $k): string => (string) ($old[$k] ?? $aviso[$k] ?? '');
$sel = array_map('intval', (array) ($aviso['instalaciones'] ?? []));
$imp = \App\Services\AvisoService::IMPORTANCIAS;
?>
<div class="d-flex justify-content-between align-items-center mb-3">
    <h1 class="h4 mb-0"><i class="bi bi-megaphone me-1"></i><?= $id === null ? 'Nuevo aviso' : 'Editar aviso' ?></h1>
    <a href="<?= e(url('/supervisor/avisos')) ?>" class="btn btn-link"><i class="bi bi-arrow-left me-1"></i>Avisos</a>
</div>

<form method="post" action="<?= e(url($id === null ? '/supervisor/avisos' : '/supervisor/avisos/' . $id)) ?>" class="card border-0 shadow-sm mx-auto" style="max-width: 48rem;" enctype="multipart/form-data" novalidate>
    <div class="card-body p-4">
        <?= csrf_field() ?>
        <div class="mb-3">
            <label for="titulo" class="form-label fw-semibold">Título</label>
            <input type="text" class="form-control" id="titulo" name="titulo" maxlength="150" required value="<?= e($v('titulo')) ?>">
        </div>
        <div class="mb-3">
            <label for="mensaje" class="form-label fw-semibold">Mensaje</label>
            <textarea class="form-control" id="mensaje" name="mensaje" rows="5" maxlength="3000" required><?= e($v('mensaje')) ?></textarea>
        </div>
        <div class="mb-3">
            <label for="imagen" class="form-label fw-semibold">Imagen <span class="fw-normal text-body-secondary">(opcional)</span></label>
            <div class="aviso-imagen-preview mb-2 <?= empty($aviso['imagen']) ? 'is-empty' : '' ?>" id="avisoPreview">
                <?php if (!empty($aviso['imagen']) && $id !== null): ?>
                    <img src="<?= e(url('/avisos/' . (int) $id . '/imagen')) ?>" alt="Imagen actual del aviso">
                <?php endif; ?>
                <div class="aviso-imagen-vacia"><i class="bi bi-image"></i><span>Marco 16:9 · ideal 1600×900 px</span></div>
            </div>
            <input type="file" class="form-control" id="imagen" name="imagen" accept="image/jpeg,image/png,image/webp"
                   data-preview="#avisoPreview" data-max-bytes="<?= e(\App\Services\ImagenAvisoService::MAX_BYTES) ?>">
            <div class="form-text">
                JPG, PNG o WebP de hasta 5 MB, horizontal 16:9 (ideal <strong>1600×900 px</strong>, mínimo 960×540).
                Si la proporción es otra, se recorta al centro. Con alcance <em>Todos</em> aparece en el carrusel de la pantalla de entrada.
            </div>
            <?php if (!empty($aviso['imagen']) && $id !== null): ?>
                <div class="form-check mt-2">
                    <input class="form-check-input" type="checkbox" id="quitar_imagen" name="quitar_imagen" value="1">
                    <label class="form-check-label" for="quitar_imagen">Quitar la imagen actual</label>
                </div>
            <?php endif; ?>
        </div>
        <fieldset class="mb-3">
            <legend class="form-label fw-semibold fs-6">Importancia</legend>
            <div class="btn-group flex-wrap" role="group">
                <?php foreach (array_reverse($imp, true) as $k => [$label, $color, $icon]): ?>
                    <input type="radio" class="btn-check" name="importancia" id="imp_<?= e($k) ?>" value="<?= e($k) ?>" <?= $v('importancia') === $k ? 'checked' : '' ?>>
                    <label class="btn btn-outline-<?= e($color) ?>" for="imp_<?= e($k) ?>"><i class="bi <?= e($icon) ?> me-1"></i><?= e($label) ?></label>
                <?php endforeach; ?>
            </div>
        </fieldset>
        <div class="row g-3 mb-3">
            <div class="col-sm-6">
                <label for="inicia_en" class="form-label fw-semibold">Se muestra desde</label>
                <input type="datetime-local" class="form-control" id="inicia_en" name="inicia_en" required value="<?= e($v('inicia_en')) ?>">
            </div>
            <div class="col-sm-6">
                <label for="termina_en" class="form-label fw-semibold">Hasta</label>
                <input type="datetime-local" class="form-control" id="termina_en" name="termina_en" required value="<?= e($v('termina_en')) ?>">
            </div>
        </div>
        <fieldset>
            <legend class="form-label fw-semibold fs-6">¿Quién lo ve?</legend>
            <div class="form-check">
                <input class="form-check-input" type="radio" name="alcance" id="alc_todos" value="todos" <?= $v('alcance') !== 'instalaciones' ? 'checked' : '' ?>>
                <label class="form-check-label" for="alc_todos">Todos (también aparece en la pantalla de entrada)</label>
            </div>
            <div class="form-check">
                <input class="form-check-input" type="radio" name="alcance" id="alc_inst" value="instalaciones" <?= $v('alcance') === 'instalaciones' ? 'checked' : '' ?> <?= $instalaciones === [] ? 'disabled' : '' ?>>
                <label class="form-check-label" for="alc_inst">Solo mis instalaciones:</label>
            </div>
            <div class="d-flex flex-wrap gap-2 ms-4 mt-2">
                <?php foreach ($instalaciones as $i): ?>
                    <input type="checkbox" class="btn-check" id="inst<?= e($i['id']) ?>" name="instalaciones[]" value="<?= e($i['id']) ?>" <?= in_array((int) $i['id'], $sel, true) ? 'checked' : '' ?>>
                    <label class="btn btn-sm btn-outline-primary" for="inst<?= e($i['id']) ?>"><?= e($i['nombre']) ?></label>
                <?php endforeach; ?>
                <?php if ($instalaciones === []): ?><span class="small text-body-secondary">No tienes instalaciones asignadas.</span><?php endif; ?>
            </div>
            <div class="form-text ms-4">Lo ven los trabajadores de esa instalación y los agentes asignados a ella.</div>
        </fieldset>
    </div>
    <div class="card-footer bg-white text-end">
        <button type="submit" class="btn btn-primary"><i class="bi bi-send me-1"></i><?= $id === null ? 'Publicar' : 'Guardar' ?></button>
    </div>
</form>
