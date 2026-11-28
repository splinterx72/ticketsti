<?php
/**
 * @var list<array{id: int, nombre: string, email: ?string, prestado: bool}> $agentes
 * @var array<string, string> $old
 * @var bool $conReportes
 * @var bool $conLista
 */
$unidadSel = $old['meta_unidad'] ?? 'equipos';
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
            <?php if ($conReportes ?? false): ?>
                <div class="card border-0 shadow-sm mb-4">
                    <div class="card-header bg-white fw-semibold"><i class="bi bi-bullseye me-1"></i>¿Qué se va a intervenir?</div>
                    <div class="card-body">
                        <div class="d-flex flex-wrap gap-2 mb-2" role="group" aria-label="Unidad de la meta">
                            <?php foreach (['' => 'Sin meta'] + \App\Services\TareaService::UNIDADES as $k => $label): ?>
                                <input type="radio" class="btn-check" name="meta_unidad" id="mu_<?= e($k ?: 'no') ?>" value="<?= e($k) ?>" <?= $unidadSel === $k ? 'checked' : '' ?>>
                                <label class="btn btn-sm btn-outline-primary" for="mu_<?= e($k ?: 'no') ?>"><?= e($label) ?></label>
                            <?php endforeach; ?>
                        </div>
                        <div class="row g-2">
                            <div class="col-5">
                                <label class="form-label small fw-semibold mb-1" for="meta_cantidad">Cuántos por agente</label>
                                <input type="number" class="form-control" id="meta_cantidad" name="meta_cantidad" min="1" max="100000" value="<?= e($old['meta_cantidad'] ?? '') ?>" placeholder="Ej. 40">
                            </div>
                            <div class="col-7">
                                <label class="form-label small fw-semibold mb-1" for="meta_descripcion">Si es "Otro", ¿qué?</label>
                                <input type="text" class="form-control" id="meta_descripcion" name="meta_descripcion" maxlength="60" value="<?= e($old['meta_descripcion'] ?? '') ?>" placeholder="Ej. nodos, impresoras">
                            </div>
                        </div>
                        <div class="form-text">Cada agente reporta cuántos lleva y se calcula su % de avance. Al llegar al 100 % te avisamos.</div>
                        <?php if ($conLista ?? false): ?>
                            <label class="form-label small fw-semibold mt-3 mb-1" for="equipos_series">Series de los equipos <span class="fw-normal text-body-secondary">(solo con "Equipos"; una por línea)</span></label>
                            <textarea class="form-control font-monospace" id="equipos_series" name="equipos_series" rows="4" spellcheck="false" placeholder="5CG1234XYZ&#10;5CG5678ABC"><?= e($old['equipos_series'] ?? '') ?></textarea>
                            <div class="form-text">Si las capturas, la meta es el número de equipos de la lista y los agentes los marcan uno por uno al intervenirlos (con un ticket por equipo si lo eligen).</div>
                        <?php endif; ?>
                    </div>
                </div>
            <?php endif; ?>
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
