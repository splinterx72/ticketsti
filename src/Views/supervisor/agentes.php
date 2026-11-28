<?php
/**
 * @var list<array{id: int, nombre: string}> $instalaciones
 * @var list<array{id: int, nombre: string, activo: int, ubicaciones: int}> $todas
 * @var list<array<string, mixed>> $asignaciones
 * @var list<array<string, mixed>> $salientes
 * @var list<array<string, mixed>> $candidatos
 * @var list<array{id: int, nombre: string, email: ?string, prestado: bool}> $misAgentes
 * @var array<int, array{estado: string, hasta: ?string, especialidades: list<int>}> $disponibilidad
 * @var list<array{id: int, nombre: string}> $departamentos
 * @var array<int, array{modo: string, remedy: bool}> $modos
 * @var int $cobInstalacion
 * @var bool $cobDisponible
 * @var list<array<string, mixed>> $cobUbicaciones
 * @var list<array{id: int, nombre: string, prestamo: bool, ubicaciones: list<int>, pausas: list<array<string, mixed>>}> $cobAgentes
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

<h2 class="h5 mt-4 mb-3" id="disponibilidad"><i class="bi bi-stars me-1"></i>Asignación de tickets</h2>
<div class="row g-4">
    <div class="col-lg-4">
        <div class="card border-0 shadow-sm">
            <div class="card-header bg-white fw-semibold">Mis instalaciones</div>
            <ul class="list-group list-group-flush">
                <?php foreach ($instalaciones as $i): $modo = $modos[$i['id']]['modo'] ?? 'sugerida'; $remedy = $modos[$i['id']]['remedy'] ?? true; ?>
                    <li class="list-group-item">
                        <form method="post" action="<?= e(url('/supervisor/instalaciones/' . (int) $i['id'] . '/asignacion')) ?>" class="d-flex flex-wrap align-items-center gap-2 m-0">
                            <?= csrf_field() ?>
                            <label class="small fw-semibold flex-grow-1" for="modo_<?= (int) $i['id'] ?>"><?= e($i['nombre']) ?></label>
                            <select class="form-select form-select-sm w-auto" id="modo_<?= (int) $i['id'] ?>" name="modo">
                                <?php foreach (\App\Services\AsignadorService::MODOS as $k => $lbl): ?>
                                    <option value="<?= e($k) ?>" <?= $k === $modo ? 'selected' : '' ?>><?= e($lbl) ?></option>
                                <?php endforeach; ?>
                            </select>
                            <button class="btn btn-sm btn-outline-primary" type="submit">Guardar</button>
                        </form>
                        <form method="post" action="<?= e(url('/supervisor/instalaciones/' . (int) $i['id'] . '/remedy')) ?>" class="d-flex align-items-center gap-2 mt-2 m-0">
                            <?= csrf_field() ?>
                            <input type="hidden" name="remedy_activo" value="<?= $remedy ? '0' : '1' ?>">
                            <span class="small flex-grow-1"><i class="bi bi-envelope-arrow-up me-1"></i>Enviar tickets nuevos a Remedy:
                                <strong class="<?= $remedy ? 'text-success' : 'text-body-secondary' ?>"><?= $remedy ? 'Sí' : 'No' ?></strong></span>
                            <button class="btn btn-sm btn-outline-secondary" type="submit"><?= $remedy ? 'Apagar' : 'Encender' ?></button>
                        </form>
                    </li>
                <?php endforeach; ?>
            </ul>
            <div class="card-body small text-body-secondary">
                <strong>Asignación — Sugerida:</strong> el sistema propone al agente con menos carga (pondera la prioridad), disponible, con la especialidad y, si puede, que ya atienda ese edificio; TI lo acepta con un clic.
                <strong>Automática:</strong> lo asigna solo y avisa al agente. <strong>Manual:</strong> sin sugerencia.
            </div>
        </div>
    </div>
    <div class="col-lg-8">
        <div class="card border-0 shadow-sm">
            <div class="card-header bg-white fw-semibold">Disponibilidad y especialidades</div>
            <?php if ($misAgentes === []): ?>
                <div class="card-body small text-body-secondary">Todavía no hay agentes asignados.</div>
            <?php else: ?>
                <ul class="list-group list-group-flush">
                    <?php foreach ($misAgentes as $a): $d = $disponibilidad[$a['id']]; ?>
                        <li class="list-group-item">
                            <form method="post" action="<?= e(url('/supervisor/agentes/' . (int) $a['id'] . '/disponibilidad')) ?>" class="row g-2 align-items-end m-0">
                                <?= csrf_field() ?>
                                <div class="col-12 fw-semibold"><?= e($a['nombre']) ?></div>
                                <div class="col-sm-4">
                                    <label class="form-label small mb-1" for="est_<?= (int) $a['id'] ?>">Estado</label>
                                    <select class="form-select form-select-sm" id="est_<?= (int) $a['id'] ?>" name="estado">
                                        <?php foreach (\App\Services\AsignadorService::ESTADOS as $k => $lbl): ?>
                                            <option value="<?= e($k) ?>" <?= $k === $d['estado'] ? 'selected' : '' ?>><?= e($lbl) ?></option>
                                        <?php endforeach; ?>
                                    </select>
                                </div>
                                <div class="col-sm-3">
                                    <label class="form-label small mb-1" for="has_<?= (int) $a['id'] ?>">Hasta</label>
                                    <input type="date" class="form-control form-control-sm" id="has_<?= (int) $a['id'] ?>" name="hasta" min="<?= e(date('Y-m-d')) ?>" value="<?= e($d['hasta'] ?? '') ?>">
                                </div>
                                <div class="col-sm-5">
                                    <span class="form-label small mb-1 d-block">Especialidades <span class="text-body-secondary">(ninguna = todas)</span></span>
                                    <?php foreach ($departamentos as $dep): ?>
                                        <div class="form-check form-check-inline small">
                                            <input class="form-check-input" type="checkbox" name="especialidades[]" value="<?= (int) $dep['id'] ?>"
                                                   id="esp_<?= (int) $a['id'] ?>_<?= (int) $dep['id'] ?>" <?= in_array((int) $dep['id'], $d['especialidades'], true) ? 'checked' : '' ?>>
                                            <label class="form-check-label" for="esp_<?= (int) $a['id'] ?>_<?= (int) $dep['id'] ?>"><?= e($dep['nombre']) ?></label>
                                        </div>
                                    <?php endforeach; ?>
                                </div>
                                <div class="col-12 text-end"><button class="btn btn-sm btn-primary" type="submit">Guardar</button></div>
                            </form>
                        </li>
                    <?php endforeach; ?>
                </ul>
            <?php endif; ?>
        </div>
    </div>
</div>
<h2 class="h5 mt-4 mb-1" id="cobertura"><i class="bi bi-diagram-3 me-1"></i>Cobertura y pausas por ubicación</h2>
<p class="small text-body-secondary mb-3">
    Marca qué ubicaciones atiende cada agente (sin marcar = todas). Si tiene otros trabajos o tareas, ponlo en pausa en una
    ubicación o en toda la instalación: mientras dure no recibe tickets nuevos ahí (ni sugeridos ni automáticos).
</p>
<?php if (!$cobDisponible): ?>
    <div class="alert alert-warning small">Falta importar la migración <code>2026-12-02_cobertura_agentes.sql</code>. Mientras tanto, los agentes atienden todas las ubicaciones.</div>
<?php else: ?>
    <?php if (count($instalaciones) > 1): ?>
        <form method="get" action="<?= e(url('/supervisor/agentes')) ?>" class="d-flex gap-2 mb-3" style="max-width: 28rem;">
            <select name="cob" class="form-select form-select-sm" aria-label="Instalación" data-autoenviar>
                <?php foreach ($instalaciones as $i): ?><option value="<?= e($i['id']) ?>" <?= $i['id'] === $cobInstalacion ? 'selected' : '' ?>><?= e($i['nombre']) ?></option><?php endforeach; ?>
            </select>
            <noscript><button class="btn btn-sm btn-primary">Ver</button></noscript>
        </form>
    <?php endif; ?>
    <?php if ($cobAgentes === []): ?>
        <div class="card border-0 shadow-sm"><div class="card-body small text-body-secondary">No hay agentes asignados a esta instalación.</div></div>
    <?php elseif ($cobUbicaciones === []): ?>
        <div class="card border-0 shadow-sm"><div class="card-body small text-body-secondary">La instalación aún no tiene ubicaciones. Dalas de alta en Supervisión › Ubicaciones.</div></div>
    <?php else: $nombresUbi = array_column($cobUbicaciones, 'nombre', 'id'); ?>
        <div class="row g-3">
            <?php foreach ($cobAgentes as $ag): $aid = (int) $ag['id']; ?>
                <div class="col-xl-6">
                    <div class="card border-0 shadow-sm h-100">
                        <div class="card-header bg-white d-flex justify-content-between align-items-center gap-2">
                            <span class="fw-semibold"><?= e($ag['nombre']) ?> <?= $ag['prestamo'] ? '<span class="badge text-bg-info">Préstamo</span>' : '' ?></span>
                            <?php if ($ag['pausas'] !== []): ?>
                                <span class="badge text-bg-warning"><i class="bi bi-pause-circle me-1"></i>En pausa</span>
                            <?php else: ?>
                                <span class="badge text-bg-success"><i class="bi bi-play-circle me-1"></i>Activo</span>
                            <?php endif; ?>
                        </div>
                        <form method="post" action="<?= e(url('/supervisor/cobertura')) ?>" class="card-body pb-2">
                            <?= csrf_field() ?>
                            <input type="hidden" name="instalacion_id" value="<?= e($cobInstalacion) ?>">
                            <input type="hidden" name="agente_id" value="<?= e($aid) ?>">
                            <span class="form-label small fw-semibold d-block mb-1">Atiende <span class="fw-normal text-body-secondary">(<?= $ag['ubicaciones'] === [] ? 'todas' : count($ag['ubicaciones']) . ' de ' . count($cobUbicaciones) ?>)</span></span>
                            <div class="d-flex flex-wrap gap-1 mb-2">
                                <?php foreach ($cobUbicaciones as $u): $uid = (int) $u['id']; ?>
                                    <input type="checkbox" class="btn-check" id="cob_<?= e($aid) ?>_<?= e($uid) ?>" name="ubicaciones[]" value="<?= e($uid) ?>" <?= in_array($uid, $ag['ubicaciones'], true) ? 'checked' : '' ?>>
                                    <label class="btn btn-sm btn-outline-primary" for="cob_<?= e($aid) ?>_<?= e($uid) ?>"><?= e($u['nombre']) ?></label>
                                <?php endforeach; ?>
                            </div>
                            <div class="text-end"><button class="btn btn-sm btn-outline-primary" type="submit">Guardar ubicaciones</button></div>
                        </form>
                        <?php if ($ag['pausas'] !== []): ?>
                            <ul class="list-group list-group-flush small">
                                <?php foreach ($ag['pausas'] as $p): ?>
                                    <li class="list-group-item d-flex justify-content-between align-items-center gap-2">
                                        <span>
                                            <i class="bi bi-pause-circle text-warning me-1"></i><strong><?= e($p['ubicacion'] ?? 'Toda la instalación') ?></strong>
                                            <?= $p['hasta'] ? ' · hasta ' . e(fecha_mx($p['hasta'], false)) : ' · sin fecha' ?>
                                            <?php if ($p['motivo']): ?><span class="text-body-secondary"> · <?= e($p['motivo']) ?></span><?php endif; ?>
                                        </span>
                                        <form method="post" action="<?= e(url('/supervisor/pausas/' . (int) $p['id'] . '/reanudar')) ?>" class="m-0">
                                            <?= csrf_field() ?>
                                            <button class="btn btn-sm btn-outline-success" type="submit"><i class="bi bi-play-fill"></i> Reanudar</button>
                                        </form>
                                    </li>
                                <?php endforeach; ?>
                            </ul>
                        <?php endif; ?>
                        <form method="post" action="<?= e(url('/supervisor/pausas')) ?>" class="card-footer bg-white row g-2 align-items-end m-0">
                            <?= csrf_field() ?>
                            <input type="hidden" name="instalacion_id" value="<?= e($cobInstalacion) ?>">
                            <input type="hidden" name="agente_id" value="<?= e($aid) ?>">
                            <div class="col-sm-5">
                                <label class="form-label small mb-1" for="pu_<?= e($aid) ?>">Pausar en</label>
                                <select class="form-select form-select-sm" id="pu_<?= e($aid) ?>" name="ubicacion_id">
                                    <option value="0">Toda la instalación</option>
                                    <?php foreach (($ag['ubicaciones'] !== [] ? $ag['ubicaciones'] : array_keys($nombresUbi)) as $uid): ?>
                                        <option value="<?= e($uid) ?>"><?= e($nombresUbi[$uid] ?? '') ?></option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                            <div class="col-sm-4">
                                <label class="form-label small mb-1" for="pm_<?= e($aid) ?>">Motivo</label>
                                <input class="form-control form-control-sm" id="pm_<?= e($aid) ?>" name="motivo" maxlength="255" placeholder="Ej. Tarea en otra área">
                            </div>
                            <div class="col-sm-3">
                                <label class="form-label small mb-1" for="ph_<?= e($aid) ?>">Hasta</label>
                                <input type="date" class="form-control form-control-sm" id="ph_<?= e($aid) ?>" name="hasta" min="<?= e(date('Y-m-d')) ?>">
                            </div>
                            <div class="col-12 text-end"><button class="btn btn-sm btn-warning" type="submit"><i class="bi bi-pause-fill"></i> Pausar</button></div>
                        </form>
                    </div>
                </div>
            <?php endforeach; ?>
        </div>
    <?php endif; ?>
<?php endif; ?>
<?php endif; ?>
