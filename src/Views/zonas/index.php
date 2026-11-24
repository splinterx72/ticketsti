<?php
/**
 * @var list<array<string, mixed>> $zonas
 * @var list<array<string, mixed>> $instalaciones
 * @var list<array<string, mixed>> $candidatos
 */
?>
<div class="d-flex flex-wrap justify-content-between align-items-center gap-2 mb-3">
    <h1 class="h4 mb-0"><i class="bi bi-map me-1"></i>Zonas</h1>
    <form method="post" action="<?= e(url('/ti/zonas')) ?>" class="d-flex gap-2 m-0">
        <?= csrf_field() ?>
        <input type="text" name="nombre" class="form-control form-control-sm" maxlength="100" required placeholder="Ej. Zona Norte" aria-label="Nombre de la zona nueva">
        <button class="btn btn-sm btn-primary text-nowrap" type="submit"><i class="bi bi-plus-lg me-1"></i>Crear zona</button>
    </form>
</div>
<p class="small text-body-secondary">
    Cada zona agrupa instalaciones bajo uno o varios <strong>coordinadores</strong>. El coordinador ve tickets, inventario,
    supervisores y técnicos de las instalaciones de su zona, asigna supervisores y recibe los avisos de contraseña de admin local de ellos.
</p>

<?php if ($zonas === []): ?>
    <div class="alert alert-info">Aún no hay zonas. Crea la primera (por ejemplo, Zona Norte, Zona Sur, Zona Marina).</div>
<?php endif; ?>

<div class="row g-4 mb-4">
    <?php foreach ($zonas as $z): ?>
        <div class="col-lg-6">
            <div class="card border-0 shadow-sm h-100 <?= (int) $z['activo'] === 0 ? 'opacity-75' : '' ?>">
                <form method="post" action="<?= e(url('/ti/zonas/' . (int) $z['id'])) ?>" class="card-header bg-white d-flex gap-2 align-items-center">
                    <?= csrf_field() ?>
                    <i class="bi bi-map text-primary"></i>
                    <input type="text" name="nombre" value="<?= e($z['nombre']) ?>" class="form-control form-control-sm fw-semibold" maxlength="100" required aria-label="Nombre">
                    <select name="activo" class="form-select form-select-sm w-auto" aria-label="Estado">
                        <option value="1" <?= (int) $z['activo'] === 1 ? 'selected' : '' ?>>Activa</option>
                        <option value="0" <?= (int) $z['activo'] === 0 ? 'selected' : '' ?>>Inactiva</option>
                    </select>
                    <button class="btn btn-sm btn-outline-primary" type="submit">Guardar</button>
                </form>
                <div class="card-body">
                    <div class="small fw-semibold mb-1">Instalaciones (<?= e(count($z['instalaciones'])) ?>)</div>
                    <div class="small text-body-secondary mb-3"><?= e(implode(' · ', array_column($z['instalaciones'], 'nombre')) ?: 'Ninguna todavía: asígnalas abajo.') ?></div>
                    <div class="small fw-semibold mb-1">Coordinadores</div>
                    <?php if ($z['coordinadores'] === []): ?>
                        <div class="small text-body-secondary mb-2">Sin coordinador: sus avisos van al Admin TI.</div>
                    <?php else: ?>
                        <ul class="list-unstyled small mb-2">
                            <?php foreach ($z['coordinadores'] as $c): ?>
                                <li class="d-flex justify-content-between align-items-center gap-2">
                                    <span><?= e($c['nombre']) ?> <span class="badge text-bg-<?= $c['tipo'] === 'titular' ? 'primary' : 'info' ?>"><?= $c['tipo'] === 'titular' ? 'Titular' : 'Suplencia' ?></span>
                                        <?php if ($c['hasta']): ?><span class="text-body-secondary">hasta <?= e(fecha_mx((string) $c['hasta'], false)) ?></span><?php endif; ?></span>
                                    <form method="post" action="<?= e(url('/ti/zonas/coordinador/' . (int) $c['asignacion_id'] . '/quitar')) ?>" class="m-0" data-confirm="¿Terminar la asignación de <?= e($c['nombre']) ?>?">
                                        <?= csrf_field() ?>
                                        <button class="btn btn-sm btn-link text-danger p-0" type="submit">Quitar</button>
                                    </form>
                                </li>
                            <?php endforeach; ?>
                        </ul>
                    <?php endif; ?>
                </div>
                <form method="post" action="<?= e(url('/ti/zonas/' . (int) $z['id'] . '/coordinadores')) ?>" class="card-footer bg-white row g-2 align-items-end mx-0">
                    <?= csrf_field() ?>
                    <div class="col-12 col-md-5 ps-0">
                        <label class="form-label small mb-1">Coordinador</label>
                        <select name="coordinador_id" class="form-select form-select-sm" required>
                            <option value="">Elige…</option>
                            <?php foreach ($candidatos as $c): ?><option value="<?= e($c['id']) ?>"><?= e($c['nombre']) ?> (<?= e($c['ficha']) ?>)</option><?php endforeach; ?>
                        </select>
                    </div>
                    <div class="col-6 col-md-3">
                        <label class="form-label small mb-1">Tipo</label>
                        <select name="tipo" class="form-select form-select-sm"><option value="titular">Titular</option><option value="suplencia">Suplencia</option></select>
                    </div>
                    <div class="col-6 col-md-4 pe-0">
                        <label class="form-label small mb-1">Hasta <span class="text-body-secondary">(suplencia)</span></label>
                        <input type="date" name="hasta" class="form-control form-control-sm">
                    </div>
                    <div class="col-12 px-0 text-end"><button class="btn btn-sm btn-primary" type="submit"><i class="bi bi-person-plus me-1"></i>Asignar</button></div>
                </form>
            </div>
        </div>
    <?php endforeach; ?>
</div>

<?php if ($zonas !== [] && $instalaciones !== []): ?>
    <form method="post" action="<?= e(url('/ti/zonas/instalaciones')) ?>" class="card border-0 shadow-sm">
        <?= csrf_field() ?>
        <div class="card-header bg-white fw-semibold">¿A qué zona pertenece cada instalación?</div>
        <div class="card-body row g-2">
            <?php foreach ($instalaciones as $i): ?>
                <div class="col-md-6 col-xl-4">
                    <label class="form-label small mb-1" for="zona<?= e($i['id']) ?>"><?= e($i['nombre']) ?></label>
                    <select class="form-select form-select-sm" id="zona<?= e($i['id']) ?>" name="zona[<?= e($i['id']) ?>]">
                        <option value="0">Sin zona</option>
                        <?php foreach ($zonas as $z): ?><option value="<?= e($z['id']) ?>" <?= (int) ($i['zona_id'] ?? 0) === (int) $z['id'] ? 'selected' : '' ?>><?= e($z['nombre']) ?></option><?php endforeach; ?>
                    </select>
                </div>
            <?php endforeach; ?>
        </div>
        <div class="card-footer bg-white text-end"><button class="btn btn-primary btn-sm" type="submit">Guardar zonas</button></div>
    </form>
<?php endif; ?>
