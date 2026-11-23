<?php
/**
 * @var list<array{id: int, nombre: string, email: ?string, prestado: bool}> $agentes
 * @var array<string, string> $old
 */
$tipos = \App\Services\TareaService::TIPOS_EVIDENCIA;
$accept = '.' . implode(',.', array_keys(\App\Services\ArchivoService::PERMITIDOS));
?>
<div class="d-flex justify-content-between align-items-center mb-3">
    <h1 class="h4 mb-0"><i class="bi bi-list-task me-1"></i>Nueva tarea</h1>
    <a href="<?= e(url('/tareas')) ?>" class="btn btn-link"><i class="bi bi-arrow-left me-1"></i>Tareas</a>
</div>

<?php if ($agentes === []): ?>
    <div class="alert alert-info">Aún no tienes agentes en tus instalaciones. Asígnalos en Supervisión › Agentes.</div>
<?php else: ?>
<form method="post" action="<?= e(url('/tareas/nueva')) ?>" enctype="multipart/form-data" class="needs-loading" novalidate>
    <?= csrf_field() ?>
    <div class="row g-4">
        <div class="col-lg-7">
            <div class="card border-0 shadow-sm">
                <div class="card-body">
                    <div class="mb-3">
                        <label for="titulo" class="form-label fw-semibold">Título</label>
                        <input type="text" class="form-control" id="titulo" name="titulo" maxlength="180" required value="<?= e($old['titulo'] ?? '') ?>">
                    </div>
                    <div class="mb-3">
                        <label for="instrucciones" class="form-label fw-semibold">Instrucciones</label>
                        <textarea class="form-control" id="instrucciones" name="instrucciones" rows="6" maxlength="5000" required><?= e($old['instrucciones'] ?? '') ?></textarea>
                    </div>
                    <div class="row g-3">
                        <div class="col-sm-6">
                            <label for="entrega_en" class="form-label fw-semibold">Fecha y hora de entrega</label>
                            <input type="datetime-local" class="form-control" id="entrega_en" name="entrega_en" required
                                   value="<?= e($old['entrega_en'] ?? date('Y-m-d\T18:00', strtotime('+3 days'))) ?>">
                        </div>
                        <div class="col-sm-6">
                            <label for="prioridad" class="form-label fw-semibold">Prioridad</label>
                            <select class="form-select" id="prioridad" name="prioridad">
                                <?php foreach (\App\Services\Catalog::PRIORIDADES as $k => $label): ?>
                                    <option value="<?= e($k) ?>" <?= ($old['prioridad'] ?? 'media') === $k ? 'selected' : '' ?>><?= e($label) ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                    </div>
                    <div class="mt-3">
                        <label for="referencias" class="form-label fw-semibold">Archivos de referencia <span class="fw-normal text-body-secondary">(opcional)</span></label>
                        <input type="file" class="form-control" id="referencias" name="referencias[]" multiple accept="<?= e($accept) ?>">
                        <div class="form-text">Formatos, manuales o listas. PDF, imágenes, Word, Excel o ZIP; máximo 10 MB cada uno.</div>
                    </div>
                </div>
            </div>
        </div>
        <div class="col-lg-5">
            <div class="card border-0 shadow-sm mb-4">
                <div class="card-header bg-white fw-semibold">Agentes</div>
                <div class="card-body">
                    <?php foreach ($agentes as $a): ?>
                        <div class="form-check">
                            <input class="form-check-input" type="checkbox" name="agentes[]" value="<?= e($a['id']) ?>" id="ag<?= e($a['id']) ?>">
                            <label class="form-check-label" for="ag<?= e($a['id']) ?>"><?= e($a['nombre']) ?>
                                <?php if ($a['prestado']): ?><span class="badge text-bg-info">Prestado</span><?php endif; ?></label>
                        </div>
                    <?php endforeach; ?>
                    <div class="form-text">Cada agente entrega sus propias evidencias.</div>
                </div>
            </div>
            <div class="card border-0 shadow-sm">
                <div class="card-header bg-white fw-semibold">Evidencias que pides</div>
                <div class="card-body">
                    <?php for ($i = 0; $i < 3; $i++): ?>
                        <div class="input-group input-group-sm mb-2">
                            <select class="form-select" name="evid_tipo[]" style="max-width: 8rem;" aria-label="Tipo de evidencia <?= $i + 1 ?>">
                                <?php foreach ($tipos as $k => $label): ?><option value="<?= e($k) ?>"><?= e($label) ?></option><?php endforeach; ?>
                            </select>
                            <input type="text" class="form-control" name="evid_desc[]" maxlength="200" placeholder="<?= $i === 0 ? 'Ej. Foto de la etiqueta de cada monitor' : 'Otra evidencia (opcional)' ?>" aria-label="Descripción de la evidencia <?= $i + 1 ?>">
                        </div>
                    <?php endfor; ?>
                    <div class="form-text">Si describes al menos una, el agente no podrá entregar sin adjuntar archivos.</div>
                </div>
            </div>
        </div>
    </div>
    <div class="d-flex justify-content-end mt-4">
        <button type="submit" class="btn btn-primary btn-lg">
            <span class="spinner-border spinner-border-sm me-1 d-none" aria-hidden="true"></span><i class="bi bi-send me-1"></i>Asignar tarea
        </button>
    </div>
</form>
<?php endif; ?>
