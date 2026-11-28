<?php
/**
 * @var string $q
 * @var array{usuario: ?array<string, mixed>, equipos: list<array<string, mixed>>} $candidatos
 * @var list<array{id: int, nombre: string}> $instalaciones
 */
use App\Services\ResguardoService;

$u = $candidatos['usuario'];
$equipos = $candidatos['equipos'];
?>
<div class="d-flex flex-wrap justify-content-between align-items-center gap-2 mb-3">
    <h1 class="h4 mb-0"><i class="bi bi-box-arrow-in-down me-1"></i>Recibir equipo</h1>
    <a href="<?= e(url('/resguardo')) ?>" class="btn btn-link"><i class="bi bi-arrow-left me-1"></i>Resguardo</a>
</div>

<form method="get" action="<?= e(url('/resguardo/recibir')) ?>" class="card border-0 shadow-sm mb-4" style="max-width: 40rem;">
    <div class="card-body d-flex flex-wrap gap-2 align-items-end">
        <div class="flex-grow-1">
            <label class="form-label fw-semibold" for="q">Ficha del trabajador o serie del equipo</label>
            <input class="form-control font-monospace" id="q" name="q" maxlength="40" value="<?= e($q) ?>" required autofocus placeholder="Ej. 123456 o 5CG1234XYZ">
        </div>
        <button class="btn btn-primary" type="submit"><i class="bi bi-search me-1"></i>Buscar</button>
    </div>
    <div class="card-footer bg-white small text-body-secondary">Con la ficha aparecen todos sus equipos del GECA; marca los que deja y lo que trae cada uno.</div>
</form>

<?php if ($q !== '' && $equipos === []): ?>
    <div class="alert alert-warning">No hay equipos con esa ficha o serie en el inventario.</div>
<?php elseif ($equipos !== []): ?>
<form method="post" action="<?= e(url('/resguardo/recibir')) ?>" enctype="multipart/form-data" class="needs-loading" novalidate>
    <?= csrf_field() ?>
    <input type="hidden" name="q" value="<?= e($q) ?>">
    <input type="hidden" name="usuario_id" value="<?= e($u['id'] ?? 0) ?>">

    <?php if ($u): ?>
        <div class="card border-0 shadow-sm mb-3">
            <div class="card-body d-flex flex-wrap gap-4">
                <div><div class="small text-body-secondary">Entrega</div><div class="fw-semibold"><?= e($u['nombre']) ?></div><div class="small font-monospace"><?= e($u['ficha']) ?></div></div>
                <?php if (!empty($u['jefe_nombre'])): ?><div><div class="small text-body-secondary">Jefe</div><div><?= e($u['jefe_nombre']) ?></div></div><?php endif; ?>
            </div>
        </div>
    <?php endif; ?>

    <h2 class="h6 text-uppercase text-body-secondary fw-semibold mb-2">¿Qué deja?</h2>
    <div class="row g-3 mb-4">
        <?php foreach ($equipos as $eq): $eid = (int) $eq['id']; $ocupado = $eq['en_resguardo'] !== null; $opciones = ResguardoService::opcionesDe($eq); ?>
            <div class="col-lg-6">
                <div class="card border-0 shadow-sm h-100 <?= $ocupado ? 'opacity-50' : '' ?>">
                    <div class="card-header bg-white">
                        <div class="form-check mb-0">
                            <input class="form-check-input" type="checkbox" name="equipos[]" value="<?= e($eid) ?>" id="eq<?= e($eid) ?>" <?= $ocupado ? 'disabled' : '' ?>>
                            <label class="form-check-label fw-semibold" for="eq<?= e($eid) ?>">
                                <?= e(tipo_equipo((string) ($eq['tipo_componente'] ?? '')) ?: 'Equipo') ?> · <span class="font-monospace"><?= e($eq['serie']) ?></span>
                                <span class="fw-normal text-body-secondary"><?= e(trim(($eq['marca'] ?? '') . ' ' . ($eq['modelo'] ?? ''))) ?></span>
                            </label>
                        </div>
                        <?php if ($ocupado): ?><div class="small text-warning-emphasis mt-1"><i class="bi bi-info-circle me-1"></i>Ya está en resguardo de TI.</div><?php endif; ?>
                        <?php if (!empty($eq['estado_resguardo'])): ?><div class="small text-body-secondary">GECA: <?= e($eq['estado_resguardo']) ?><?= (int) $eq['vigente_en_geca'] === 0 ? ' · no vigente' : '' ?></div><?php endif; ?>
                    </div>
                    <?php if (!$ocupado): ?>
                        <div class="card-body small">
                            <span class="fw-semibold d-block mb-1">Trae <span class="fw-normal text-body-secondary">(marca lo que entrega)</span></span>
                            <div class="d-flex flex-wrap gap-1 mb-2">
                                <?php foreach ($opciones as $k => $label): ?>
                                    <input type="checkbox" class="btn-check" name="accesorios[<?= e($eid) ?>][]" value="<?= e($k) ?>" id="acc<?= e($eid) ?>_<?= e($k) ?>" <?= str_starts_with($k, 'monitor_') ? 'checked' : '' ?>>
                                    <label class="btn btn-sm btn-outline-primary" for="acc<?= e($eid) ?>_<?= e($k) ?>"><?= e($label) ?></label>
                                <?php endforeach; ?>
                            </div>
                            <div class="row g-2">
                                <div class="col-sm-5">
                                    <label class="form-label mb-1" for="cond<?= e($eid) ?>">Condición</label>
                                    <select class="form-select form-select-sm" id="cond<?= e($eid) ?>" name="condicion[<?= e($eid) ?>]">
                                        <?php foreach (ResguardoService::CONDICIONES as $k => $label): ?><option value="<?= e($k) ?>"><?= e($label) ?></option><?php endforeach; ?>
                                    </select>
                                </div>
                                <div class="col-sm-7">
                                    <label class="form-label mb-1" for="obs<?= e($eid) ?>">Observaciones</label>
                                    <input class="form-control form-control-sm" id="obs<?= e($eid) ?>" name="obs[<?= e($eid) ?>]" maxlength="500" placeholder="Ej. pantalla rayada, sin tecla Enter">
                                </div>
                            </div>
                        </div>
                    <?php endif; ?>
                </div>
            </div>
        <?php endforeach; ?>
    </div>

    <div class="card border-0 shadow-sm">
        <div class="card-header bg-white fw-semibold">Datos de la recepción</div>
        <div class="card-body row g-3">
            <div class="col-md-4">
                <label class="form-label fw-semibold" for="motivo">Motivo</label>
                <select class="form-select" id="motivo" name="motivo" required>
                    <option value="">Elige…</option>
                    <?php foreach (ResguardoService::MOTIVOS as $k => $label): ?><option value="<?= e($k) ?>"><?= e($label) ?></option><?php endforeach; ?>
                </select>
            </div>
            <div class="col-md-4">
                <label class="form-label fw-semibold" for="instalacion_id">Se guarda en</label>
                <select class="form-select" id="instalacion_id" name="instalacion_id">
                    <option value="0">Sin instalación</option>
                    <?php foreach ($instalaciones as $i): ?><option value="<?= e($i['id']) ?>"><?= e($i['nombre']) ?></option><?php endforeach; ?>
                </select>
            </div>
            <div class="col-md-4">
                <label class="form-label fw-semibold" for="acta">Acta del GECA <span class="fw-normal text-body-secondary">(opcional)</span></label>
                <input class="form-control" type="file" id="acta" name="acta" accept=".pdf,.jpg,.jpeg,.png,.webp">
            </div>
            <div class="col-12">
                <label class="form-label fw-semibold" for="observaciones">Observaciones generales</label>
                <textarea class="form-control" id="observaciones" name="observaciones" rows="2" maxlength="3000" placeholder="Ej. Entrega por jubilación; deja también una diadema."></textarea>
            </div>
        </div>
        <div class="card-footer bg-white text-end">
            <button class="btn btn-primary" type="submit"><span class="spinner-border spinner-border-sm me-1 d-none" aria-hidden="true"></span><i class="bi bi-box-arrow-in-down me-1"></i>Registrar recepción</button>
        </div>
    </div>
</form>
<?php endif; ?>
