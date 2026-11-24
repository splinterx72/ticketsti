<?php
/**
 * @var list<array{id: int, nombre: string}> $instalaciones
 * @var list<array<string, mixed>> $asignaciones
 * @var list<array<string, mixed>> $candidatos
 */
$porInst = [];
foreach ($asignaciones as $a) {
    $porInst[(int) $a['instalacion_id']][] = $a;
}
?>
<div class="d-flex justify-content-between align-items-center mb-3">
    <h1 class="h4 mb-0"><i class="bi bi-diagram-3 me-1"></i>Supervisores de mi zona</h1>
    <a href="<?= e(url('/supervisor')) ?>" class="btn btn-link"><i class="bi bi-arrow-left me-1"></i>Panel</a>
</div>

<?php if ($instalaciones === []): ?>
    <div class="alert alert-info">Aún no tienes zonas asignadas o tus zonas no tienen instalaciones. El Admin TI las configura en Gestión › Zonas.</div>
<?php else: ?>
<div class="row g-4">
    <div class="col-lg-7">
        <?php foreach ($instalaciones as $i): ?>
            <div class="card border-0 shadow-sm mb-3">
                <div class="card-header bg-white fw-semibold"><i class="bi bi-buildings me-1"></i><?= e($i['nombre']) ?></div>
                <?php if (empty($porInst[$i['id']])): ?>
                    <div class="card-body small text-warning-emphasis"><i class="bi bi-exclamation-triangle me-1"></i>Sin supervisor: sus tickets y avisos te llegan a ti.</div>
                <?php else: ?>
                    <ul class="list-group list-group-flush">
                        <?php foreach ($porInst[$i['id']] as $a): ?>
                            <li class="list-group-item d-flex justify-content-between align-items-center gap-2 small">
                                <span><?= e($a['nombre']) ?>
                                    <span class="badge text-bg-<?= $a['tipo'] === 'titular' ? 'primary' : 'info' ?>"><?= $a['tipo'] === 'titular' ? 'Titular' : 'Suplencia' ?></span>
                                    <?php if ($a['hasta']): ?><span class="text-body-secondary">hasta <?= e(fecha_mx((string) $a['hasta'], false)) ?></span><?php endif; ?>
                                </span>
                                <form method="post" action="<?= e(url('/supervisor/supervisores/' . (int) $a['id'] . '/quitar')) ?>" class="m-0" data-confirm="¿Terminar la asignación de <?= e($a['nombre']) ?>?">
                                    <?= csrf_field() ?>
                                    <button class="btn btn-sm btn-link text-danger p-0" type="submit">Quitar</button>
                                </form>
                            </li>
                        <?php endforeach; ?>
                    </ul>
                <?php endif; ?>
            </div>
        <?php endforeach; ?>
    </div>
    <div class="col-lg-5">
        <form method="post" action="<?= e(url('/supervisor/supervisores')) ?>" class="card border-0 shadow-sm">
            <?= csrf_field() ?>
            <div class="card-header bg-white fw-semibold"><i class="bi bi-person-plus me-1"></i>Asignar supervisor</div>
            <div class="card-body">
                <div class="mb-2">
                    <label class="form-label small" for="supervisor_id">Supervisor</label>
                    <select class="form-select" id="supervisor_id" name="supervisor_id" required>
                        <option value="">Elige…</option>
                        <?php foreach ($candidatos as $c): ?><option value="<?= e($c['id']) ?>"><?= e($c['nombre']) ?> (<?= e($c['ficha']) ?>)</option><?php endforeach; ?>
                    </select>
                    <div class="form-text">Solo aparecen cuentas con rol Supervisor (el Admin TI asigna los roles).</div>
                </div>
                <div class="mb-2">
                    <label class="form-label small" for="instalacion_id">Instalación</label>
                    <select class="form-select" id="instalacion_id" name="instalacion_id" required>
                        <?php foreach ($instalaciones as $i): ?><option value="<?= e($i['id']) ?>"><?= e($i['nombre']) ?></option><?php endforeach; ?>
                    </select>
                </div>
                <div class="row g-2">
                    <div class="col-6">
                        <label class="form-label small" for="tipo">Tipo</label>
                        <select class="form-select" id="tipo" name="tipo"><option value="titular">Titular</option><option value="suplencia">Suplencia</option></select>
                    </div>
                    <div class="col-6">
                        <label class="form-label small" for="hasta">Hasta <span class="text-body-secondary">(suplencia)</span></label>
                        <input type="date" class="form-control" id="hasta" name="hasta">
                    </div>
                </div>
            </div>
            <div class="card-footer bg-white text-end"><button class="btn btn-primary" type="submit">Asignar</button></div>
        </form>
    </div>
</div>
<?php endif; ?>
