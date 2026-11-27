<?php
/** @var list<array{clave: string, nombre: string, descripcion: ?string, activo: bool, roles: list<string>}> $modulos */
$labels = \App\Core\Roles::LABELS;
?>
<div class="d-flex justify-content-between align-items-center mb-3">
    <h1 class="h4 mb-0"><i class="bi bi-toggles me-1"></i>Módulos</h1>
</div>
<div class="alert alert-info small">
    <i class="bi bi-info-circle me-1"></i>
    Enciende o apaga cada módulo y elige qué perfiles lo usan. El Admin TI siempre tiene acceso.
    Dentro de cada módulo se respeta el alcance por instalación (p. ej. el trabajador solo ve las garantías de sus equipos).
</div>
<form method="post" action="<?= e(url('/ti/modulos')) ?>" class="card border-0 shadow-sm">
    <?= csrf_field() ?>
    <div class="table-responsive">
        <table class="table align-middle mb-0">
            <thead class="table-light"><tr><th>Módulo</th><th class="text-center">Encendido</th><th>Perfiles que lo usan</th></tr></thead>
            <tbody>
            <?php foreach ($modulos as $m): $k = $m['clave']; ?>
                <tr>
                    <td><strong><?= e($m['nombre']) ?></strong><div class="small text-body-secondary"><?= e($m['descripcion'] ?? '') ?></div></td>
                    <td class="text-center">
                        <div class="form-check form-switch d-inline-block">
                            <input class="form-check-input" type="checkbox" role="switch" name="activo[<?= e($k) ?>]" value="1" id="act_<?= e($k) ?>" <?= $m['activo'] ? 'checked' : '' ?>>
                            <label class="visually-hidden" for="act_<?= e($k) ?>">Encender <?= e($m['nombre']) ?></label>
                        </div>
                    </td>
                    <td>
                        <?php foreach (\App\Services\ModuloService::POSIBLES[$k] ?? [] as $rol): ?>
                            <div class="form-check form-check-inline">
                                <input class="form-check-input" type="checkbox" name="roles[<?= e($k) ?>][]" value="<?= e($rol) ?>" id="r_<?= e($k . '_' . $rol) ?>" <?= in_array($rol, $m['roles'], true) ? 'checked' : '' ?>>
                                <label class="form-check-label small" for="r_<?= e($k . '_' . $rol) ?>"><?= e($labels[$rol] ?? $rol) ?></label>
                            </div>
                        <?php endforeach; ?>
                    </td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    </div>
    <div class="card-footer bg-white text-end"><button class="btn btn-primary" type="submit">Guardar</button></div>
</form>
