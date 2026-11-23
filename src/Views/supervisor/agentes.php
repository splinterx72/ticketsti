<?php
/**
 * @var list<array{id: int, nombre: string}> $instalaciones
 * @var list<array{id: int, nombre: string, activo: int, ubicaciones: int}> $todas
 * @var list<array<string, mixed>> $asignaciones
 * @var list<array<string, mixed>> $salientes
 * @var list<array<string, mixed>> $candidatos
 * @var list<array{id: int, nombre: string, email: ?string, prestado: bool}> $misAgentes
 */
$mias = array_column($instalaciones, 'id');
$base = array_values(array_filter($misAgentes, static fn (array $a): bool => !$a['prestado']));
$destinos = array_values(array_filter($todas, static fn (array $i): bool => !in_array($i['id'], $mias, true)));
$hasta = static fn (?string $h): string => $h ? 'hasta ' . fecha_mx($h, false) : 'permanente';
?>
<div class="d-flex flex-wrap justify-content-between align-items-center gap-2 mb-3">
    <h1 class="h4 mb-0"><i class="bi bi-people me-1"></i>Agentes de mis instalaciones</h1>
</div>

<?php if ($instalaciones === []): ?>
    <div class="alert alert-info">Aún no tienes instalaciones a tu cargo. El Admin TI te las asigna en Gestión › Instalaciones.</div>
<?php else: ?>
<div class="row g-4">
    <div class="col-lg-7">
        <div class="card border-0 shadow-sm mb-4">
            <div class="card-header bg-white fw-semibold">Asignados a mis instalaciones</div>
            <?php if ($asignaciones === []): ?>
                <div class="card-body small text-body-secondary">Todavía no hay agentes asignados.</div>
            <?php else: ?>
                <ul class="list-group list-group-flush">
                    <?php foreach ($asignaciones as $a): ?>
                        <li class="list-group-item d-flex justify-content-between align-items-center gap-2">
                            <div class="small">
                                <div class="fw-semibold fs-6"><?= e($a['nombre']) ?>
                                    <?php if ($a['tipo'] === 'prestamo'): ?><span class="badge text-bg-info">Prestado a mí</span><?php endif; ?>
                                </div>
                                <div class="text-body-secondary"><?= e($a['instalacion']) ?> · <?= e($hasta($a['hasta'])) ?></div>
                            </div>
                            <form method="post" action="<?= e(url('/supervisor/asignacion/' . (int) $a['id'] . '/quitar')) ?>" class="m-0"
                                  data-confirm="¿Terminar la asignación de <?= e($a['nombre']) ?> en <?= e($a['instalacion']) ?>?">
                                <?= csrf_field() ?>
                                <button class="btn btn-sm btn-link text-danger" type="submit">Quitar</button>
                            </form>
                        </li>
                    <?php endforeach; ?>
                </ul>
            <?php endif; ?>
        </div>

        <div class="card border-0 shadow-sm">
            <div class="card-header bg-white fw-semibold">Mis agentes prestados a otras instalaciones</div>
            <?php if ($salientes === []): ?>
                <div class="card-body small text-body-secondary">Ningún préstamo vigente.</div>
            <?php else: ?>
                <ul class="list-group list-group-flush">
                    <?php foreach ($salientes as $p): ?>
                        <li class="list-group-item d-flex justify-content-between align-items-center gap-2">
                            <div class="small">
                                <div class="fw-semibold fs-6"><?= e($p['nombre']) ?></div>
                                <div class="text-body-secondary">En <?= e($p['instalacion']) ?> · <?= $p['desde'] ? 'desde ' . e(fecha_mx($p['desde'], false)) . ' ' : '' ?><?= e($hasta($p['hasta'])) ?></div>
                            </div>
                            <form method="post" action="<?= e(url('/supervisor/asignacion/' . (int) $p['id'] . '/quitar')) ?>" class="m-0"
                                  data-confirm="¿Terminar hoy el préstamo de <?= e($p['nombre']) ?>?">
                                <?= csrf_field() ?>
                                <button class="btn btn-sm btn-link" type="submit">Terminar préstamo</button>
                            </form>
                        </li>
                    <?php endforeach; ?>
                </ul>
            <?php endif; ?>
        </div>
    </div>

    <div class="col-lg-5">
        <form method="post" action="<?= e(url('/supervisor/agentes')) ?>" class="card border-0 shadow-sm mb-4">
            <div class="card-header bg-white fw-semibold"><i class="bi bi-person-plus me-1"></i>Asignar agente</div>
            <div class="card-body">
                <?= csrf_field() ?>
                <label class="form-label small fw-semibold" for="agente_id">Agente</label>
                <select class="form-select mb-2" id="agente_id" name="agente_id" required>
                    <option value="">Selecciona…</option>
                    <?php foreach ($candidatos as $c): ?><option value="<?= e($c['id']) ?>"><?= e($c['nombre']) ?> (<?= e($c['ficha']) ?>)</option><?php endforeach; ?>
                </select>
                <label class="form-label small fw-semibold" for="instalacion_id">Instalación</label>
                <select class="form-select" id="instalacion_id" name="instalacion_id" required>
                    <?php foreach ($instalaciones as $i): ?><option value="<?= e($i['id']) ?>"><?= e($i['nombre']) ?></option><?php endforeach; ?>
                </select>
                <div class="form-text">Para que aparezca aquí, el Admin TI debe darle rol de Agente TI.</div>
            </div>
            <div class="card-footer bg-white text-end"><button class="btn btn-primary btn-sm" type="submit">Asignar</button></div>
        </form>

        <form method="post" action="<?= e(url('/supervisor/prestamos')) ?>" class="card border-0 shadow-sm">
            <div class="card-header bg-white fw-semibold"><i class="bi bi-arrow-left-right me-1"></i>Prestar agente</div>
            <div class="card-body">
                <?= csrf_field() ?>
                <?php if ($base === []): ?>
                    <p class="small text-body-secondary mb-0">Primero asigna agentes a tus instalaciones.</p>
                <?php else: ?>
                    <label class="form-label small fw-semibold" for="p_agente">Agente</label>
                    <select class="form-select mb-2" id="p_agente" name="agente_id" required>
                        <?php foreach ($base as $a): ?><option value="<?= e($a['id']) ?>"><?= e($a['nombre']) ?></option><?php endforeach; ?>
                    </select>
                    <label class="form-label small fw-semibold" for="p_inst">A la instalación</label>
                    <select class="form-select mb-2" id="p_inst" name="instalacion_id" required>
                        <?php foreach ($destinos as $i): ?><option value="<?= e($i['id']) ?>"><?= e($i['nombre']) ?></option><?php endforeach; ?>
                    </select>
                    <div class="row g-2">
                        <div class="col-6"><label class="form-label small fw-semibold" for="p_desde">Desde</label><input type="date" class="form-control" id="p_desde" name="desde" value="<?= e(date('Y-m-d')) ?>"></div>
                        <div class="col-6"><label class="form-label small fw-semibold" for="p_hasta">Hasta</label><input type="date" class="form-control" id="p_hasta" name="hasta" required min="<?= e(date('Y-m-d')) ?>"></div>
                    </div>
                    <div class="form-text">Durante el préstamo atiende ambas instalaciones; al terminar regresa solo.</div>
                <?php endif; ?>
            </div>
            <?php if ($base !== [] && $destinos !== []): ?>
                <div class="card-footer bg-white text-end"><button class="btn btn-primary btn-sm" type="submit">Registrar préstamo</button></div>
            <?php endif; ?>
        </form>
    </div>
</div>
<?php endif; ?>
