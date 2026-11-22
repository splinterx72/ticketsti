<?php
/**
 * @var array<string, mixed> $garantia
 * @var array<string, string> $old
 * @var array<string, mixed> $authUser
 */
use App\Services\GarantiaService;

$g = $garantia;
$v = static fn (string $k): string => (string) ($old[$k] ?? $g[$k] ?? '');
$isEdit = $g['id'] !== null;
$action = $isEdit ? '/ti/garantias/' . (int) $g['id'] : '/ti/garantias';
?>
<div class="d-flex justify-content-between align-items-center mb-3">
    <h1 class="h4 mb-0"><i class="bi bi-shield-plus me-1"></i><?= $isEdit ? 'Editar garantía' : 'Registrar garantía' ?></h1>
    <a href="<?= e(url($isEdit ? '/ti/equipos/' . (int) $g['equipo_id'] : '/ti/garantias')) ?>" class="btn btn-link"><i class="bi bi-arrow-left me-1"></i>Volver</a>
</div>

<div class="row justify-content-center">
    <div class="col-lg-8">
        <form method="post" action="<?= e(url($action)) ?>" class="card border-0 shadow-sm" novalidate>
            <div class="card-body p-4">
                <?= csrf_field() ?>
                <div class="row g-3">
                    <div class="col-md-6">
                        <label for="serie" class="form-label fw-semibold">Serie del equipo o monitor</label>
                        <input type="text" class="form-control font-monospace text-uppercase" id="serie" name="serie" maxlength="60"
                               value="<?= e($v('serie')) ?>" required spellcheck="false" autocomplete="off">
                        <div class="form-text">Puedes escribir la serie del CPU o la de uno de sus monitores.</div>
                    </div>
                    <div class="col-md-6">
                        <label for="elemento_cubierto" class="form-label fw-semibold">¿Qué cubre?</label>
                        <select class="form-select" id="elemento_cubierto" name="elemento_cubierto" required>
                            <?php foreach (GarantiaService::ELEMENTOS as $k => $label): ?>
                                <option value="<?= e($k) ?>" <?= $v('elemento_cubierto') === $k ? 'selected' : '' ?>><?= e($label) ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="col-md-7">
                        <label for="proveedor" class="form-label fw-semibold">Proveedor</label>
                        <input type="text" class="form-control" id="proveedor" name="proveedor" maxlength="120" value="<?= e($v('proveedor')) ?>" required>
                    </div>
                    <div class="col-md-5">
                        <label for="numero_poliza_contrato" class="form-label fw-semibold">Póliza / contrato <span class="fw-normal text-body-secondary">(opcional)</span></label>
                        <input type="text" class="form-control" id="numero_poliza_contrato" name="numero_poliza_contrato" maxlength="80" value="<?= e($v('numero_poliza_contrato')) ?>">
                    </div>
                    <div class="col-sm-4">
                        <label for="fecha_inicio" class="form-label fw-semibold">Inicio</label>
                        <input type="date" class="form-control" id="fecha_inicio" name="fecha_inicio" value="<?= e($v('fecha_inicio')) ?>" required>
                    </div>
                    <div class="col-sm-4">
                        <label for="fecha_vencimiento" class="form-label fw-semibold">Vencimiento</label>
                        <input type="date" class="form-control" id="fecha_vencimiento" name="fecha_vencimiento" value="<?= e($v('fecha_vencimiento')) ?>" required>
                    </div>
                    <div class="col-sm-4">
                        <label for="estado" class="form-label fw-semibold">Estado</label>
                        <select class="form-select" id="estado" name="estado">
                            <?php foreach (GarantiaService::ESTADOS as $k => $label): ?>
                                <option value="<?= e($k) ?>" <?= $v('estado') === $k ? 'selected' : '' ?>><?= e($label) ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="col-12">
                        <label for="notas" class="form-label fw-semibold">Notas <span class="fw-normal text-body-secondary">(opcional)</span></label>
                        <textarea class="form-control" id="notas" name="notas" rows="3" maxlength="2000"
                                  placeholder="Contacto del proveedor, número de caso de reclamación, condiciones…"><?= e($v('notas')) ?></textarea>
                    </div>
                </div>
            </div>
            <div class="card-footer bg-white d-flex justify-content-end gap-2 py-3">
                <button type="submit" class="btn btn-primary"><i class="bi bi-check2 me-1"></i>Guardar</button>
            </div>
        </form>

        <?php if ($isEdit && ($authUser['rol'] ?? '') === 'admin_ti'): ?>
            <form method="post" action="<?= e(url('/ti/garantias/' . (int) $g['id'] . '/eliminar')) ?>" class="text-end mt-3"
                  data-confirm="¿Eliminar esta garantía? Esta acción no se puede deshacer.">
                <?= csrf_field() ?>
                <button type="submit" class="btn btn-link text-danger btn-sm"><i class="bi bi-trash me-1"></i>Eliminar garantía</button>
            </form>
        <?php endif; ?>
    </div>
</div>
