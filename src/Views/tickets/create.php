<?php
/**
 * @var list<array<string, mixed>> $equipos
 * @var list<array<string, mixed>> $catalogo
 * @var bool $hayCatalogo
 * @var array<string, mixed> $habitual   ubicación habitual física del usuario
 * @var array<int, ?int> $instalacionGeca  equipo_id => instalación equivalente de su inmueble GECA
 * @var array<string, string> $tipos
 * @var array<string, string> $prioridades
 * @var array<string, string> $old
 */
$hasOld = $old !== [];
// Regla 3.C: se preselecciona el equipo principal (el primero, ordenado por es_principal DESC).
$equipoSel = $hasOld ? (int) ($old['equipo_id'] ?? 0) : (int) ($equipos[0]['id'] ?? 0);
$componenteSel = $old['componente_afectado'] ?? ($equipoSel > 0 ? 'equipo' : 'general');
// Ubicación física sugerida: la capturada, la real del equipo elegido o la habitual del usuario.
if ($hasOld) {
    $selFisica = [
        'instalacion_id' => $old['reportada_instalacion_id'] ?? 0, 'ubicacion_fisica_id' => $old['reportada_ubicacion_fisica_id'] ?? 0,
        'nivel_id' => $old['reportada_nivel_id'] ?? 0, 'ala' => $old['reportada_ala'] ?? '', 'referencia' => $old['reportada_referencia'] ?? '',
    ];
} else {
    $selFisica = $habitual;
    foreach ($equipos as $eq) {
        if ((int) $eq['id'] === $equipoSel && $eq['real_ubicacion_fisica_id'] !== null) {
            $selFisica = ['ubicacion_fisica_id' => $eq['real_ubicacion_fisica_id'], 'nivel_id' => $eq['real_nivel_id'], 'ala' => $eq['real_ala'], 'referencia' => ''];
        }
    }
}
?>
<div class="d-flex justify-content-between align-items-center mb-3">
    <h1 class="h4 mb-0"><i class="bi bi-plus-circle me-1"></i>Nuevo ticket</h1>
    <a href="<?= e(url('/tickets')) ?>" class="btn btn-link"><i class="bi bi-arrow-left me-1"></i>Mis tickets</a>
</div>

<form method="post" action="<?= e(url('/tickets')) ?>" id="ticketForm" class="needs-loading" novalidate>
    <?= csrf_field() ?>
    <div class="row g-4">
        <div class="col-lg-7">
            <div class="card border-0 shadow-sm">
                <div class="card-header bg-white fw-semibold">1. ¿Qué sucede?</div>
                <div class="card-body">
                    <div class="row g-3">
                        <div class="col-sm-7">
                            <label for="tipo_ticket" class="form-label fw-semibold">Tipo</label>
                            <select class="form-select" id="tipo_ticket" name="tipo_ticket" required>
                                <?php foreach ($tipos as $k => $label): ?>
                                    <option value="<?= e($k) ?>" <?= ($old['tipo_ticket'] ?? 'incidencia') === $k ? 'selected' : '' ?>><?= e($label) ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="col-sm-5">
                            <label for="prioridad" class="form-label fw-semibold">Prioridad</label>
                            <select class="form-select" id="prioridad" name="prioridad" required>
                                <?php foreach ($prioridades as $k => $label): ?>
                                    <option value="<?= e($k) ?>" <?= ($old['prioridad'] ?? 'media') === $k ? 'selected' : '' ?>><?= e($label) ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="col-12">
                            <label for="titulo" class="form-label fw-semibold">Título</label>
                            <input type="text" class="form-control" id="titulo" name="titulo" maxlength="180" required
                                   value="<?= e($old['titulo'] ?? '') ?>" placeholder="Ej. La computadora no enciende">
                        </div>
                        <div class="col-12">
                            <label for="descripcion" class="form-label fw-semibold">Descripción</label>
                            <textarea class="form-control" id="descripcion" name="descripcion" rows="6" maxlength="5000" required
                                      placeholder="¿Qué pasa? ¿Desde cuándo? ¿Aparece algún mensaje de error?"><?= e($old['descripcion'] ?? '') ?></textarea>
                        </div>
                    </div>
                </div>
            </div>
        </div>

        <div class="col-lg-5">
            <div class="card border-0 shadow-sm mb-4">
                <div class="card-header bg-white fw-semibold">2. ¿Qué equipo?</div>
                <div class="card-body">
                    <label for="equipo_id" class="form-label fw-semibold">Equipo</label>
                    <select class="form-select" id="equipo_id" name="equipo_id">
                        <?php foreach ($equipos as $eq): ?>
                            <option value="<?= e($eq['id']) ?>"
                                    data-serie="<?= e($eq['serie']) ?>"
                                    data-m1="<?= e($eq['serie_monitor1'] ?? '') ?>"
                                    data-m2="<?= e($eq['serie_monitor2'] ?? '') ?>"
                                    data-ub="<?= e($eq['real_ubicacion_fisica_id'] ?? '') ?>"
                                    data-niv="<?= e($eq['real_nivel_id'] ?? '') ?>"
                                    data-ala="<?= e($eq['real_ala'] ?? '') ?>"
                                    data-geca-inst="<?= e($instalacionGeca[(int) $eq['id']] ?? '') ?>"
                                    data-geca="<?= e($eq['ubicacion_geca'] ?? '') ?>"
                                    <?= (int) $eq['id'] === $equipoSel ? 'selected' : '' ?>>
                                <?= !empty($eq['tipo_componente']) ? e(tipo_equipo($eq['tipo_componente'])) . ' · ' : '' ?><?= e($eq['serie']) ?> — <?= e(trim(($eq['marca'] ?? '') . ' ' . ($eq['modelo'] ?? ''))) ?>
                                <?= (int) $eq['es_principal'] === 1 ? '★ Principal' : '' ?>
                                <?= (int) $eq['vigente_en_geca'] === 0 ? '(no vigente)' : '' ?>
                            </option>
                        <?php endforeach; ?>
                        <option value="0" <?= $equipoSel === 0 ? 'selected' : '' ?>>Sin equipo / Incidencia general</option>
                    </select>
                    <?php if ($equipos === []): ?>
                        <div class="form-text">No tienes equipos en resguardo; el ticket se registrará como incidencia general.</div>
                    <?php endif; ?>

                    <div class="mt-3 equipo-only">
                        <span class="form-label fw-semibold d-block">Componente con falla</span>
                        <div class="btn-group w-100 flex-wrap" role="group" aria-label="Componente afectado">
                            <?php foreach (['equipo' => 'bi-pc-display', 'monitor_1' => 'bi-display', 'monitor_2' => 'bi-display', 'general' => 'bi-three-dots'] as $comp => $icon): ?>
                                <input type="radio" class="btn-check" name="componente_afectado" id="comp_<?= e($comp) ?>"
                                       value="<?= e($comp) ?>" <?= $componenteSel === $comp ? 'checked' : '' ?>>
                                <label class="btn btn-outline-primary btn-sm" for="comp_<?= e($comp) ?>">
                                    <i class="bi <?= e($icon) ?> me-1"></i><?= e(componente_label($comp)) ?>
                                </label>
                            <?php endforeach; ?>
                        </div>
                        <div class="form-text" id="monitorInfo"></div>
                    </div>

                    <div class="mt-3">
                        <label for="hostname_reportado" class="form-label fw-semibold">Hostname <span class="fw-normal text-body-secondary">(opcional)</span></label>
                        <input type="text" class="form-control font-monospace text-uppercase" id="hostname_reportado" name="hostname_reportado"
                               maxlength="30" value="<?= e($old['hostname_reportado'] ?? '') ?>" placeholder="PMxxx + serie" spellcheck="false" autocomplete="off">
                        <div class="form-text">Lo ves en la etiqueta del equipo o en <em>Este equipo › Propiedades</em>. Inicia con <code>PM</code>.</div>
                    </div>
                </div>
            </div>

            <div class="card border-0 shadow-sm">
                <div class="card-header bg-white fw-semibold">3. ¿Dónde está físicamente?</div>
                <div class="card-body">
                    <?= partial('ubicacion_picker', [
                        'catalogo' => $catalogo, 'prefijo' => 'reportada', 'sel' => $selFisica, 'requerido' => $hayCatalogo,
                        'refPlaceholder' => 'Ej. junto a la sala de juntas 2',
                    ]) ?>
                    <div class="small text-body-secondary mt-2 equipo-only" id="gecaInfo"></div>
                    <div class="alert alert-warning small mt-2 mb-0 d-none" id="discrepanciaAviso">
                        <i class="bi bi-geo-alt me-1"></i>Esta instalación es distinta a la del resguardo GECA. Al enviar, se actualizará la
                        ubicación real del equipo y TI dará seguimiento al cambio de resguardo.
                    </div>
                </div>
            </div>
        </div>
    </div>

    <div class="d-flex justify-content-end mt-4">
        <button type="submit" class="btn btn-primary btn-lg px-4">
            <span class="spinner-border spinner-border-sm me-1 d-none" aria-hidden="true"></span>
            <i class="bi bi-send me-1"></i>Enviar ticket
        </button>
    </div>
</form>
