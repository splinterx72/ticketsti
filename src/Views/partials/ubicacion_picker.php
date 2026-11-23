<?php
/**
 * Selector de ubicación física en cascada (Instalación → Ubicación → Nivel → Ala + Referencia).
 *
 * Variables:
 * @var list<array<string, mixed>> $catalogo   UbicacionFisicaService::catalogo()
 * @var string $prefijo                         'reportada' | 'habitual'
 * @var array<string, mixed> $sel               instalacion_id, ubicacion_fisica_id, nivel_id, ala, referencia
 * @var bool $requerido
 * @var string $refPlaceholder
 *
 * Sin JavaScript funciona igual: la ubicación se agrupa por instalación y el
 * servidor valida nivel y ala. Con JavaScript los selects se filtran en cascada
 * y se muestran las indicaciones del lugar.
 */
$p = $prefijo;
$alasTxt = \App\Services\UbicacionFisicaService::ALAS;
$selInst = (int) ($sel['instalacion_id'] ?? 0);
$selUb = (int) ($sel['ubicacion_fisica_id'] ?? 0);
$selNiv = (int) ($sel['nivel_id'] ?? 0);
$selAla = (string) ($sel['ala'] ?? '');
if ($selInst === 0 && $selUb > 0) {
    foreach ($catalogo as $i) {
        foreach ($i['ubicaciones'] as $u) {
            if ($u['id'] === $selUb) {
                $selInst = $i['id'];
            }
        }
    }
}
$todosNiveles = [];
foreach ($catalogo as $i) {
    foreach ($i['ubicaciones'] as $u) {
        foreach ($u['niveles'] as $n) {
            $todosNiveles[$n['id']] = $n['nombre'];
        }
    }
}
$req = $requerido ? 'required' : '';
?>
<div class="ubicacion-picker" data-catalogo="<?= e(json_encode($catalogo, JSON_UNESCAPED_UNICODE)) ?>" data-prefijo="<?= e($p) ?>">
    <?php if ($catalogo === [] || array_sum(array_map(static fn (array $i): int => count($i['ubicaciones']), $catalogo)) === 0): ?>
        <div class="alert alert-secondary small mb-0">
            Aún no hay ubicaciones capturadas por los supervisores. Puedes continuar sin indicar ubicación.
        </div>
    <?php else: ?>
        <div class="row g-2">
            <div class="col-sm-6">
                <label class="form-label small fw-semibold" for="<?= e($p) ?>_instalacion_id">Instalación</label>
                <select class="form-select" id="<?= e($p) ?>_instalacion_id" name="<?= e($p) ?>_instalacion_id" data-role="instalacion" <?= $req ?>>
                    <option value="">Selecciona…</option>
                    <?php foreach ($catalogo as $i): if ($i['ubicaciones'] === []) { continue; } ?>
                        <option value="<?= e($i['id']) ?>" <?= $i['id'] === $selInst ? 'selected' : '' ?>><?= e($i['nombre']) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="col-sm-6">
                <label class="form-label small fw-semibold" for="<?= e($p) ?>_ubicacion_fisica_id">Ubicación</label>
                <select class="form-select" id="<?= e($p) ?>_ubicacion_fisica_id" name="<?= e($p) ?>_ubicacion_fisica_id" data-role="ubicacion" <?= $req ?>>
                    <option value="">Selecciona…</option>
                    <?php foreach ($catalogo as $i): if ($i['ubicaciones'] === []) { continue; } ?>
                        <optgroup label="<?= e($i['nombre']) ?>">
                            <?php foreach ($i['ubicaciones'] as $u): ?>
                                <option value="<?= e($u['id']) ?>" <?= $u['id'] === $selUb ? 'selected' : '' ?>><?= e($u['nombre']) ?></option>
                            <?php endforeach; ?>
                        </optgroup>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="col-6">
                <label class="form-label small fw-semibold" for="<?= e($p) ?>_nivel_id">Nivel</label>
                <select class="form-select" id="<?= e($p) ?>_nivel_id" name="<?= e($p) ?>_nivel_id" data-role="nivel">
                    <?php foreach ($todosNiveles as $id => $nombre): ?>
                        <option value="<?= e($id) ?>" <?= $id === $selNiv ? 'selected' : '' ?>><?= e($nombre) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="col-6">
                <label class="form-label small fw-semibold" for="<?= e($p) ?>_ala">Ala</label>
                <select class="form-select" id="<?= e($p) ?>_ala" name="<?= e($p) ?>_ala" data-role="ala">
                    <?php foreach ($alasTxt as $k => $label): ?>
                        <option value="<?= e($k) ?>" <?= $k === $selAla ? 'selected' : '' ?>><?= e($label) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="col-12">
                <div class="alert alert-warning small py-2 mb-0 d-none" data-role="indicaciones" role="note">
                    <i class="bi bi-cone-striped me-1"></i><strong>Indicaciones del lugar:</strong> <span></span>
                </div>
            </div>
            <div class="col-12">
                <label class="form-label small fw-semibold" for="<?= e($p) ?>_referencia">Referencia <span class="fw-normal text-body-secondary">(opcional)</span></label>
                <input type="text" class="form-control" id="<?= e($p) ?>_referencia" name="<?= e($p) ?>_referencia" maxlength="255"
                       value="<?= e($sel['referencia'] ?? '') ?>" placeholder="<?= e($refPlaceholder) ?>">
            </div>
        </div>
    <?php endif; ?>
</div>
