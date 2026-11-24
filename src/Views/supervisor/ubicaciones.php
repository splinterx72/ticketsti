<?php
/**
 * @var list<array{id: int, nombre: string}> $instalaciones
 * @var int $seleccion
 * @var list<array<string, mixed>> $ubicaciones
 * @var list<array{id: int, nombre: string}> $tipos
 * @var list<array{id: int, nombre: string}> $niveles
 * @var array<string, string> $alas
 * @var array<string, mixed>|null $edicion
 * @var array<string, string> $old
 */
$ed = $edicion;
$v = static fn (string $k, string $def = ''): string => (string) ($old[$k] ?? ($ed[$k] ?? $def));
$nivelesSel = $ed['niveles'] ?? [];
$alasSel = $ed['alas'] ?? [];
$todasAlas = \App\Services\UbicacionFisicaService::ALAS;
?>
<div class="d-flex flex-wrap justify-content-between align-items-center gap-2 mb-3">
    <h1 class="h4 mb-0"><i class="bi bi-geo-alt me-1"></i>Ubicaciones de mis instalaciones</h1>
    <?php if (count($instalaciones) > 1): ?>
        <form method="get" action="<?= e(url('/supervisor/ubicaciones')) ?>" class="d-flex gap-2">
            <select name="instalacion" class="form-select form-select-sm" aria-label="Instalación" onchange="this.form.submit()">
                <?php foreach ($instalaciones as $i): ?><option value="<?= e($i['id']) ?>" <?= $i['id'] === $seleccion ? 'selected' : '' ?>><?= e($i['nombre']) ?></option><?php endforeach; ?>
            </select>
            <noscript><button class="btn btn-sm btn-primary">Ver</button></noscript>
        </form>
    <?php endif; ?>
</div>

<?php if ($instalaciones === []): ?>
    <div class="alert alert-info">Aún no tienes instalaciones a tu cargo. El Admin TI te las asigna en Gestión › Instalaciones.</div>
<?php else: ?>
<div class="row g-4">
    <div class="col-lg-7">
        <div class="card border-0 shadow-sm">
            <div class="card-header bg-white fw-semibold">
                <?= e(array_column($instalaciones, 'nombre', 'id')[$seleccion] ?? '') ?> · <?= e(count($ubicaciones)) ?> ubicaciones
            </div>
            <?php if ($ubicaciones === []): ?>
                <div class="card-body small text-body-secondary">Da de alta los edificios, naves, talleres o áreas de esta instalación.</div>
            <?php else: ?>
                <ul class="list-group list-group-flush">
                    <?php foreach ($ubicaciones as $u): ?>
                        <li class="list-group-item <?= (int) $u['activo'] === 1 ? '' : 'opacity-50' ?>">
                            <div class="d-flex justify-content-between gap-2">
                                <div class="small">
                                    <div class="fw-semibold fs-6"><?= e($u['nombre']) ?> <span class="badge text-bg-light border"><?= e($u['tipo'] ?? 'Sin tipo') ?></span></div>
                                    <div class="text-body-secondary">Niveles: <?= e($u['niveles'] !== [] ? implode(', ', $u['niveles']) : 'Único') ?> · Alas: <?= e($u['alas'] !== [] ? implode(', ', array_map(static fn (string $a): string => $todasAlas[$a], $u['alas'])) : 'N/A') ?></div>
                                    <?php if ($u['indicaciones']): ?><div class="text-warning-emphasis mt-1"><i class="bi bi-cone-striped me-1"></i><?= e($u['indicaciones']) ?></div><?php endif; ?>
                                </div>
                                <a class="btn btn-sm btn-outline-secondary align-self-start" href="<?= e(url('/supervisor/ubicaciones/' . (int) $u['id'] . '/editar')) ?>"><i class="bi bi-pencil"></i><span class="visually-hidden">Editar</span></a>
                            </div>
                        </li>
                    <?php endforeach; ?>
                </ul>
            <?php endif; ?>
        </div>
    </div>

    <div class="col-lg-5">
        <form method="post" action="<?= e(url($ed ? '/supervisor/ubicaciones/' . (int) $ed['id'] : '/supervisor/ubicaciones')) ?>" class="card border-0 shadow-sm" novalidate>
            <div class="card-header bg-white fw-semibold"><?= $ed ? 'Editar ubicación' : 'Nueva ubicación' ?></div>
            <div class="card-body">
                <?= csrf_field() ?>
                <input type="hidden" name="instalacion_id" value="<?= e($seleccion) ?>">
                <div class="mb-3">
                    <label class="form-label fw-semibold" for="nombre">Nombre</label>
                    <input type="text" class="form-control" id="nombre" name="nombre" maxlength="150" required value="<?= e($v('nombre')) ?>" placeholder="Ej. Edificio Holkanaab, Área de Carga">
                </div>
                <div class="mb-3">
                    <label class="form-label fw-semibold" for="tipo_id">Tipo</label>
                    <select class="form-select" id="tipo_id" name="tipo_id">
                        <option value="">— Sin tipo —</option>
                        <?php foreach ($tipos as $t): ?><option value="<?= e($t['id']) ?>" <?= $v('tipo_id') === (string) $t['id'] ? 'selected' : '' ?>><?= e($t['nombre']) ?></option><?php endforeach; ?>
                    </select>
                </div>
                <fieldset class="mb-3">
                    <legend class="form-label fw-semibold fs-6 mb-1">Niveles que aplican</legend>
                    <div class="form-text mt-0 mb-2">Si no marcas ninguno, el nivel será “Único”.</div>
                    <div class="d-flex flex-wrap gap-2">
                        <?php foreach ($niveles as $n): if ($n['nombre'] === 'Único') { continue; } ?>
                            <input type="checkbox" class="btn-check" id="niv<?= e($n['id']) ?>" name="niveles[]" value="<?= e($n['id']) ?>" <?= in_array($n['id'], $nivelesSel, true) ? 'checked' : '' ?>>
                            <label class="btn btn-sm btn-outline-primary" for="niv<?= e($n['id']) ?>"><?= e($n['nombre']) ?></label>
                        <?php endforeach; ?>
                    </div>
                </fieldset>
                <fieldset class="mb-3">
                    <legend class="form-label fw-semibold fs-6 mb-1">Alas que aplican</legend>
                    <div class="form-text mt-0 mb-2">Si no marcas ninguna, el ala será “N/A”.</div>
                    <div class="d-flex flex-wrap gap-2">
                        <?php foreach ($alas as $k => $label): ?>
                            <input type="checkbox" class="btn-check" id="ala<?= e($k) ?>" name="alas[]" value="<?= e($k) ?>" <?= in_array($k, $alasSel, true) ? 'checked' : '' ?>>
                            <label class="btn btn-sm btn-outline-primary" for="ala<?= e($k) ?>"><?= e($label) ?></label>
                        <?php endforeach; ?>
                    </div>
                </fieldset>
                <div class="mb-3">
                    <label class="form-label fw-semibold" for="indicaciones">Indicaciones del lugar <span class="fw-normal text-body-secondary">(opcional)</span></label>
                    <textarea class="form-control" id="indicaciones" name="indicaciones" rows="3" maxlength="2000" placeholder="Ej. Es necesario contar con botas de trabajo y todo el equipo de seguridad"><?= e($v('indicaciones')) ?></textarea>
                    <div class="form-text">El agente las verá en cada ticket de este lugar.</div>
                </div>
                <?php if ($ed): ?>
                    <div class="form-check form-switch">
                        <input class="form-check-input" type="checkbox" role="switch" id="activo" name="activo" value="1" <?= (int) $ed['activo'] === 1 ? 'checked' : '' ?>>
                        <label class="form-check-label" for="activo">Activa (aparece en los formularios)</label>
                        <input type="hidden" name="activo_presente" value="1">
                    </div>
                <?php endif; ?>
            </div>
            <div class="card-footer bg-white d-flex justify-content-between">
                <?php if ($ed): ?><a class="btn btn-link" href="<?= e(url('/supervisor/ubicaciones?instalacion=' . $seleccion)) ?>">Cancelar</a><?php else: ?><span></span><?php endif; ?>
                <button type="submit" class="btn btn-primary"><i class="bi bi-check2 me-1"></i>Guardar</button>
            </div>
        </form>
    </div>
</div>
<?php endif; ?>

<?php if ($seleccion > 0): ?>
<div class="card border-0 shadow-sm mt-4" id="inmuebles">
    <div class="card-header bg-white fw-semibold"><i class="bi bi-link-45deg me-1"></i>Inmuebles del GECA de esta instalación</div>
    <div class="card-body">
        <p class="small text-body-secondary">
            Los equipos cuyo inmueble GECA esté aquí cuentan en esta instalación mientras no tengan una ubicación física reportada,
            y un ticket desde otra instalación marca discrepancia. Solo puedes tomar inmuebles libres; para mover uno de otra
            instalación, pídelo al Admin TI.
        </p>
        <form method="post" action="<?= e(url('/supervisor/inmuebles')) ?>" class="row g-2 align-items-end mb-3">
            <?= csrf_field() ?>
            <input type="hidden" name="instalacion" value="<?= e($seleccion) ?>">
            <div class="col-md-9">
                <label class="form-label small mb-1" for="inmueble">Inmueble (tal como viene en el GECA)</label>
                <input class="form-control" list="inmueblesLibres" id="inmueble" name="inmueble" maxlength="150" required autocomplete="off" placeholder="Escribe para buscar…">
                <datalist id="inmueblesLibres">
                    <?php foreach ($inmueblesLibres as $i): ?><option value="<?= e($i['inmueble']) ?>"><?= e(number_format($i['equipos'])) ?> equipos</option><?php endforeach; ?>
                </datalist>
            </div>
            <div class="col-md-3 d-grid"><button class="btn btn-primary" type="submit"><i class="bi bi-plus-lg me-1"></i>Relacionar</button></div>
        </form>
        <?php if ($inmueblesMios === []): ?>
            <div class="small text-body-secondary">Aún no hay inmuebles relacionados con esta instalación.</div>
        <?php else: ?>
            <ul class="list-group">
                <?php foreach ($inmueblesMios as $i): ?>
                    <li class="list-group-item d-flex justify-content-between align-items-center gap-2">
                        <span><?= e($i['inmueble']) ?> <span class="small text-body-secondary">· <?= e(number_format($i['equipos'])) ?> equipos vigentes</span></span>
                        <form method="post" action="<?= e(url('/supervisor/inmuebles')) ?>" class="m-0" data-confirm="¿Quitar <?= e($i['inmueble']) ?> de esta instalación?">
                            <?= csrf_field() ?>
                            <input type="hidden" name="instalacion" value="<?= e($seleccion) ?>">
                            <input type="hidden" name="inmueble" value="<?= e($i['inmueble']) ?>">
                            <input type="hidden" name="quitar" value="1">
                            <button class="btn btn-sm btn-link text-danger" type="submit"><i class="bi bi-x-lg"></i> Quitar</button>
                        </form>
                    </li>
                <?php endforeach; ?>
            </ul>
        <?php endif; ?>
    </div>
</div>
<?php endif; ?>
