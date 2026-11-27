<?php
/**
 * @var list<array<string, mixed>> $plantillas
 * @var bool $gestiona
 */
?>
<div class="d-flex flex-wrap justify-content-between align-items-center gap-2 mb-3">
    <h1 class="h4 mb-0"><i class="bi bi-envelope-paper me-1"></i>Plantillas de correo</h1>
    <?php if ($gestiona): ?><a class="btn btn-primary btn-sm" href="<?= e(url('/ti/plantillas/nueva')) ?>"><i class="bi bi-plus-lg me-1"></i>Nueva plantilla</a><?php endif; ?>
</div>
<div class="alert alert-info small">
    <i class="bi bi-info-circle me-1"></i>
    Se envían desde el ticket (al solicitante) o desde la ficha del trabajador; antes de enviar puedes ajustar el texto.
    Variables: <?php foreach (\App\Services\PlantillaService::VARIABLES as $v => $d): ?><code title="<?= e($d) ?>"><?= e($v) ?></code> <?php endforeach; ?>
</div>
<div class="row g-3">
    <?php foreach ($plantillas as $p): ?>
        <div class="col-md-6 col-xl-4">
            <div class="card border-0 shadow-sm h-100<?= (int) $p['activa'] === 1 ? '' : ' opacity-75' ?>">
                <div class="card-header bg-white d-flex justify-content-between align-items-center gap-2">
                    <strong class="text-truncate"><?= e($p['nombre']) ?></strong>
                    <?php if ((int) $p['activa'] !== 1): ?><span class="badge text-bg-secondary">Inactiva</span><?php endif; ?>
                </div>
                <div class="card-body small">
                    <div class="fw-semibold mb-1"><?= e($p['asunto']) ?></div>
                    <div class="text-body-secondary" style="white-space: pre-line; max-height: 9rem; overflow: hidden;"><?= e($p['cuerpo']) ?></div>
                </div>
                <?php if ($gestiona): ?>
                    <div class="card-footer bg-white d-flex justify-content-end gap-2">
                        <a class="btn btn-sm btn-outline-primary" href="<?= e(url('/ti/plantillas/' . (int) $p['id'] . '/editar')) ?>">Editar</a>
                        <form method="post" action="<?= e(url('/ti/plantillas/' . (int) $p['id'] . '/eliminar')) ?>" class="m-0" data-confirm="¿Eliminar la plantilla «<?= e($p['nombre']) ?>»?">
                            <?= csrf_field() ?>
                            <button class="btn btn-sm btn-link text-danger" type="submit">Eliminar</button>
                        </form>
                    </div>
                <?php endif; ?>
            </div>
        </div>
    <?php endforeach; ?>
    <?php if ($plantillas === []): ?><div class="col-12 text-body-secondary small">Aún no hay plantillas.</div><?php endif; ?>
</div>
