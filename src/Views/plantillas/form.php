<?php
/**
 * @var array<string, mixed> $p
 * @var array<string, string> $old
 */
$v = static fn (string $k): string => (string) ($old[$k] ?? $p[$k] ?? '');
$activa = $old !== [] ? !empty($old['activa']) : (int) $p['activa'] === 1;
$accion = $p['id'] === null ? '/ti/plantillas' : '/ti/plantillas/' . (int) $p['id'];
?>
<div class="mb-3"><a href="<?= e(url('/ti/plantillas')) ?>" class="small"><i class="bi bi-arrow-left"></i> Plantillas</a></div>
<h1 class="h4 mb-3"><?= e($p['id'] === null ? 'Nueva plantilla' : 'Editar plantilla') ?></h1>
<form method="post" action="<?= e(url($accion)) ?>" class="card border-0 shadow-sm" style="max-width: 48rem;">
    <?= csrf_field() ?>
    <div class="card-body">
        <div class="mb-3">
            <label class="form-label fw-semibold" for="nombre">Nombre</label>
            <input class="form-control" id="nombre" name="nombre" maxlength="100" required value="<?= e($v('nombre')) ?>" placeholder="Ej. Mantenimiento programado">
        </div>
        <div class="mb-3">
            <label class="form-label fw-semibold" for="asunto">Asunto</label>
            <input class="form-control" id="asunto" name="asunto" maxlength="180" required value="<?= e($v('asunto')) ?>">
        </div>
        <div class="mb-3">
            <label class="form-label fw-semibold" for="cuerpo">Mensaje</label>
            <textarea class="form-control" id="cuerpo" name="cuerpo" rows="9" maxlength="5000" required><?= e($v('cuerpo')) ?></textarea>
            <div class="form-text">Variables: <?php foreach (\App\Services\PlantillaService::VARIABLES as $var => $d): ?><code title="<?= e($d) ?>"><?= e($var) ?></code> <?php endforeach; ?></div>
        </div>
        <div class="form-check form-switch">
            <input class="form-check-input" type="checkbox" role="switch" id="activa" name="activa" value="1" <?= $activa ? 'checked' : '' ?>>
            <label class="form-check-label" for="activa">Activa (se ofrece al enviar)</label>
        </div>
    </div>
    <div class="card-footer bg-white text-end"><button class="btn btn-primary" type="submit">Guardar</button></div>
</form>
