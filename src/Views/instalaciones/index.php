<?php
/**
 * @var list<array{id: int, nombre: string, activo: int, ubicaciones: int}> $instalaciones
 * @var array<int, string> $supervisores
 * @var list<array{id: int, nombre: string}> $tipos
 * @var list<array{id: int, nombre: string}> $niveles
 * @var list<array{inmueble: string, instalacion_id: ?int, equipos: int}> $inmuebles
 */
$pendientes = count(array_filter($inmuebles, static fn (array $i): bool => $i['instalacion_id'] === null));
$activas = array_values(array_filter($instalaciones, static fn (array $i): bool => $i['activo'] === 1));
?>
<div class="d-flex flex-wrap justify-content-between align-items-center gap-2 mb-3">
    <h1 class="h4 mb-0"><i class="bi bi-buildings me-1"></i>Instalaciones</h1>
</div>

<div class="row g-4">
    <div class="col-lg-7">
        <div class="card border-0 shadow-sm mb-4">
            <div class="card-header bg-white fw-semibold">Instalaciones y responsables</div>
            <?php if ($instalaciones === []): ?>
                <div class="card-body text-body-secondary small">Aún no hay instalaciones. Crea la primera, por ejemplo “Zona Industrial Km 4.5”.</div>
            <?php else: ?>
                <div class="list-group list-group-flush">
                    <?php foreach ($instalaciones as $i): ?>
                        <a href="<?= e(url('/ti/instalaciones/' . $i['id'])) ?>" class="list-group-item list-group-item-action <?= $i['activo'] ? '' : 'opacity-50' ?>">
                            <div class="d-flex justify-content-between gap-2">
                                <div>
                                    <div class="fw-semibold"><?= e($i['nombre']) ?> <?= $i['activo'] ? '' : '<span class="badge text-bg-secondary">Inactiva</span>' ?></div>
                                    <div class="small text-body-secondary">
                                        <i class="bi bi-person-badge me-1"></i><?= e($supervisores[$i['id']] ?? 'Sin supervisor asignado') ?>
                                    </div>
                                </div>
                                <span class="small text-body-secondary text-nowrap"><?= e($i['ubicaciones']) ?> ubicaciones</span>
                            </div>
                        </a>
                    <?php endforeach; ?>
                </div>
            <?php endif; ?>
            <form method="post" action="<?= e(url('/ti/instalaciones')) ?>" class="card-footer bg-white d-flex gap-2">
                <?= csrf_field() ?>
                <input type="text" name="nombre" class="form-control" maxlength="150" required placeholder="Nueva instalación (ej. Zona Industrial Laguna Azul)" aria-label="Nombre de la nueva instalación">
                <button class="btn btn-primary text-nowrap" type="submit"><i class="bi bi-plus-lg me-1"></i>Crear</button>
            </form>
        </div>

        <div class="card border-0 shadow-sm" id="geca">
            <div class="card-header bg-white d-flex justify-content-between align-items-center">
                <span class="fw-semibold">Equivalencias GECA → instalación</span>
                <?php if ($pendientes > 0): ?><span class="badge text-bg-warning"><?= e($pendientes) ?> sin asignar</span><?php endif; ?>
            </div>
            <div class="card-body small text-body-secondary pb-0">
                Indica a qué instalación física corresponde cada inmueble del GECA. Sirve para detectar cuando un equipo está en otra instalación.
            </div>
            <?php if ($inmuebles === []): ?>
                <div class="card-body small text-body-secondary">Aún no hay inmuebles: se llenan con la carga semanal del GECA.</div>
            <?php else: ?>
                <form method="post" action="<?= e(url('/ti/instalaciones/geca')) ?>">
                    <?= csrf_field() ?>
                    <div class="table-responsive">
                        <table class="table table-sm align-middle mb-0">
                            <thead class="table-light"><tr><th>Inmueble (GECA)</th><th class="text-end">Equipos</th><th>Instalación física</th></tr></thead>
                            <tbody>
                            <?php foreach ($inmuebles as $k => $m): ?>
                                <tr>
                                    <td class="small"><?= e($m['inmueble']) ?><input type="hidden" name="inmueble[<?= e($k) ?>]" value="<?= e($m['inmueble']) ?>"></td>
                                    <td class="text-end small"><?= e($m['equipos']) ?></td>
                                    <td>
                                        <select name="instalacion[<?= e($k) ?>]" class="form-select form-select-sm" aria-label="Instalación de <?= e($m['inmueble']) ?>">
                                            <option value="0">— Sin asignar —</option>
                                            <?php foreach ($activas as $i): ?>
                                                <option value="<?= e($i['id']) ?>" <?= $m['instalacion_id'] === $i['id'] ? 'selected' : '' ?>><?= e($i['nombre']) ?></option>
                                            <?php endforeach; ?>
                                        </select>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                    <div class="card-footer bg-white text-end"><button class="btn btn-primary btn-sm" type="submit">Guardar equivalencias</button></div>
                </form>
            <?php endif; ?>
        </div>
    </div>

    <div class="col-lg-5">
        <div class="card border-0 shadow-sm" id="plantilla">
            <div class="card-header bg-white fw-semibold">Plantilla general</div>
            <div class="card-body small">
                <p class="text-body-secondary">Los supervisores eligen de estas listas al dar de alta sus ubicaciones.</p>
                <div class="mb-3">
                    <div class="fw-semibold mb-1">Tipos de ubicación</div>
                    <div class="d-flex flex-wrap gap-1"><?php foreach ($tipos as $t): ?><span class="badge text-bg-light border"><?= e($t['nombre']) ?></span><?php endforeach; ?></div>
                </div>
                <div class="mb-3">
                    <div class="fw-semibold mb-1">Niveles</div>
                    <div class="d-flex flex-wrap gap-1"><?php foreach ($niveles as $n): ?><span class="badge text-bg-light border"><?= e($n['nombre']) ?></span><?php endforeach; ?></div>
                </div>
                <div class="mb-1"><span class="fw-semibold">Alas:</span> Norte, Sur, Oriente, Poniente (o N/A).</div>
            </div>
            <form method="post" action="<?= e(url('/ti/instalaciones/plantilla')) ?>" class="card-footer bg-white row g-2 mx-0">
                <?= csrf_field() ?>
                <div class="col-6"><input type="text" name="tipo" class="form-control form-control-sm" maxlength="60" placeholder="Nuevo tipo" aria-label="Nuevo tipo"></div>
                <div class="col-6"><input type="text" name="nivel" class="form-control form-control-sm" maxlength="40" placeholder="Nuevo nivel" aria-label="Nuevo nivel"></div>
                <div class="col-12 text-end"><button class="btn btn-sm btn-outline-primary" type="submit">Agregar a la plantilla</button></div>
            </form>
        </div>
    </div>
</div>
