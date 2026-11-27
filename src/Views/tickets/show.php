<?php
/**
 * @var array<string, mixed> $ticket
 * @var list<array<string, mixed>> $comentarios
 * @var bool $esTi
 * @var list<array<string, mixed>> $estados
 * @var list<array<string, mixed>> $departamentos
 * @var list<array<string, mixed>> $agentes
 * @var list<array<string, mixed>> $garantias
 * @var array<string, mixed> $authUser
 * @var array<string, string> $old
 */
$t = $ticket;
$cerrado = (int) $t['es_cierre'] === 1;
$monitor = match ($t['componente_afectado']) {
    'monitor_1' => trim(($t['serie_monitor1'] ?? '') . ' ' . ($t['marca_monitor1'] ?? '') . ' ' . ($t['modelo_monitor1'] ?? '')),
    'monitor_2' => trim(($t['serie_monitor2'] ?? '') . ' ' . ($t['marca_monitor2'] ?? '') . ' ' . ($t['modelo_monitor2'] ?? '')),
    default     => null,
};
?>
<div class="d-flex flex-wrap justify-content-between align-items-start gap-2 mb-3">
    <div>
        <div class="small text-body-secondary font-monospace"><?= e($t['folio']) ?> · <?= e(tipo_label($t['tipo_ticket'])) ?></div>
        <h1 class="h4 mb-1"><?= e($t['titulo']) ?></h1>
        <div class="d-flex flex-wrap gap-1">
            <?= estado_badge($t['estado_nombre'], $t['estado_color']) ?>
            <?= prioridad_badge($t['prioridad']) ?>
            <?php if ((int) $t['discrepancia_ubicacion'] === 1): ?>
                <span class="badge text-bg-warning"><i class="bi bi-geo-alt me-1"></i>Discrepancia de ubicación</span>
            <?php endif; ?>
        </div>
    </div>
    <a href="<?= e(url($esTi ? '/ti/tickets' : '/tickets')) ?>" class="btn btn-link"><i class="bi bi-arrow-left me-1"></i><?= $esTi ? 'Bandeja' : 'Mis tickets' ?></a>
</div>

<div class="row g-4">
    <div class="col-lg-8">
        <div class="card border-0 shadow-sm mb-4">
            <div class="card-body">
                <div class="small text-body-secondary mb-2">
                    <strong class="text-body"><?= e($t['solicitante_nombre']) ?></strong> abrió este ticket <?= e(hace($t['creado_en'])) ?>
                    <span title="<?= e(fecha_mx($t['creado_en'])) ?>">(<?= e(fecha_mx($t['creado_en'])) ?>)</span>
                </div>
                <div class="ticket-description"><?= texto_multilinea($t['descripcion']) ?></div>
                <?php if ($t['indicaciones'] !== null): ?>
                    <div class="alert alert-warning small mt-3 mb-0 d-flex gap-2" role="note">
                        <i class="bi bi-cone-striped flex-shrink-0"></i>
                        <div><strong>Indicaciones del lugar:</strong> <?= texto_multilinea($t['indicaciones']) ?></div>
                    </div>
                <?php endif; ?>
            </div>
        </div>

        <h2 class="h6 fw-semibold mb-3" id="comentarios"><i class="bi bi-chat-left-text me-1"></i>Seguimiento</h2>
        <?php if ($comentarios === []): ?>
            <p class="small text-body-secondary">Aún no hay comentarios.</p>
        <?php endif; ?>
        <div class="timeline mb-4">
            <?php foreach ($comentarios as $c):
                $sistema = str_starts_with((string) $c['mensaje'], '[Sistema]');
                $deTi = \App\Core\Roles::esTi($c['autor_rol']);
                ?>
                <?php if ($sistema): ?>
                    <div class="timeline-item timeline-system small text-body-secondary">
                        <i class="bi bi-gear me-1"></i><?= e(substr((string) $c['mensaje'], 10)) ?>
                        <span class="ms-1">· <?= e($c['autor']) ?> · <?= e(hace($c['creado_en'])) ?></span>
                    </div>
                <?php else: ?>
                    <div class="timeline-item card border-0 shadow-sm <?= (int) $c['es_nota_interna'] === 1 ? 'note-internal' : ($deTi ? 'note-ti' : '') ?>">
                        <div class="card-body py-2 px-3">
                            <div class="d-flex justify-content-between small mb-1">
                                <span>
                                    <strong><?= e($c['autor']) ?></strong>
                                    <?php if ($deTi): ?><span class="badge text-bg-primary ms-1">TI</span><?php endif; ?>
                                    <?php if ((int) $c['es_nota_interna'] === 1): ?><span class="badge text-bg-warning ms-1"><i class="bi bi-lock me-1"></i>Nota interna</span><?php endif; ?>
                                </span>
                                <span class="text-body-secondary" title="<?= e(fecha_mx($c['creado_en'])) ?>"><?= e(hace($c['creado_en'])) ?></span>
                            </div>
                            <div><?= texto_multilinea($c['mensaje']) ?></div>
                        </div>
                    </div>
                <?php endif; ?>
            <?php endforeach; ?>
        </div>

        <?php if ($cerrado && !$esTi): ?>
            <div class="alert alert-secondary small">
                Este ticket está cerrado. Si el problema continúa, <a href="<?= e(url('/tickets/nuevo')) ?>">crea un ticket nuevo</a>.
            </div>
        <?php else: ?>
            <form method="post" action="<?= e(url('/tickets/' . (int) $t['id'] . '/comentarios')) ?>" class="card border-0 shadow-sm needs-loading">
                <div class="card-body">
                    <?= csrf_field() ?>
                    <label for="mensaje" class="form-label fw-semibold">Agregar comentario</label>
                    <textarea class="form-control" id="mensaje" name="mensaje" rows="3" maxlength="5000" required
                              placeholder="<?= $esTi ? 'Respuesta al usuario o nota interna…' : 'Escribe información adicional o responde a TI…' ?>"><?= e($old['mensaje'] ?? '') ?></textarea>
                    <div class="d-flex flex-wrap justify-content-between align-items-center gap-2 mt-2">
                        <?php if ($esTi): ?>
                            <div class="form-check">
                                <input class="form-check-input" type="checkbox" id="es_nota_interna" name="es_nota_interna" value="1">
                                <label class="form-check-label small" for="es_nota_interna"><i class="bi bi-lock me-1"></i>Nota interna (el usuario no la ve)</label>
                            </div>
                        <?php else: ?>
                            <span></span>
                        <?php endif; ?>
                        <button type="submit" class="btn btn-primary">
                            <span class="spinner-border spinner-border-sm me-1 d-none" aria-hidden="true"></span>
                            <i class="bi bi-send me-1"></i>Enviar
                        </button>
                    </div>
                </div>
            </form>
        <?php endif; ?>
    </div>

    <div class="col-lg-4">
        <?php if ($esTi): ?>
            <div class="card border-0 shadow-sm mb-4 border-start border-primary border-3">
                <div class="card-header bg-white fw-semibold d-flex justify-content-between align-items-center">
                    <span><i class="bi bi-sliders me-1"></i>Gestión TI</span>
                    <?php if ((int) ($t['agente_asignado_id'] ?? 0) !== (int) $authUser['id'] && !$cerrado): ?>
                        <form method="post" action="<?= e(url('/tickets/' . (int) $t['id'] . '/tomar')) ?>" class="m-0">
                            <?= csrf_field() ?>
                            <button class="btn btn-sm btn-outline-primary" type="submit"><i class="bi bi-hand-index me-1"></i>Tomar</button>
                        </form>
                    <?php endif; ?>
                </div>
                <form method="post" action="<?= e(url('/tickets/' . (int) $t['id'] . '/gestion')) ?>" class="card-body">
                    <?= csrf_field() ?>
                    <div class="mb-2">
                        <label class="form-label small fw-semibold" for="estado_id">Estado</label>
                        <select class="form-select form-select-sm" id="estado_id" name="estado_id">
                            <?php foreach ($estados as $es): ?>
                                <option value="<?= e($es['id']) ?>" <?= $es['id'] === (int) $t['estado_id'] ? 'selected' : '' ?>><?= e($es['nombre']) ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="mb-2">
                        <label class="form-label small fw-semibold" for="agente_asignado_id">Asignado a</label>
                        <select class="form-select form-select-sm" id="agente_asignado_id" name="agente_asignado_id">
                            <option value="0">— Sin asignar —</option>
                            <?php foreach ($agentes as $a): ?>
                                <option value="<?= e($a['id']) ?>" <?= $a['id'] === (int) ($t['agente_asignado_id'] ?? 0) ? 'selected' : '' ?>><?= e($a['nombre']) ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="row g-2 mb-3">
                        <div class="col-6">
                            <label class="form-label small fw-semibold" for="prioridad_g">Prioridad</label>
                            <select class="form-select form-select-sm" id="prioridad_g" name="prioridad">
                                <?php foreach (\App\Services\Catalog::PRIORIDADES as $k => $label): ?>
                                    <option value="<?= e($k) ?>" <?= $k === $t['prioridad'] ? 'selected' : '' ?>><?= e($label) ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="col-6">
                            <label class="form-label small fw-semibold" for="departamento_id">Departamento</label>
                            <select class="form-select form-select-sm" id="departamento_id" name="departamento_id">
                                <?php foreach ($departamentos as $d): ?>
                                    <option value="<?= e($d['id']) ?>" <?= $d['id'] === (int) $t['departamento_id'] ? 'selected' : '' ?>><?= e($d['nombre']) ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                    </div>
                    <button type="submit" class="btn btn-primary btn-sm w-100"><i class="bi bi-check2 me-1"></i>Guardar cambios</button>
                </form>
            </div>
        <?php endif; ?>

        <div class="card border-0 shadow-sm mb-4">
            <div class="card-header bg-white fw-semibold"><i class="bi bi-info-circle me-1"></i>Detalles</div>
            <dl class="card-body small mb-0 detail-list">
                <dt>Departamento</dt><dd><?= e($t['departamento_nombre']) ?></dd>
                <dt>Atiende</dt><dd><?= e($t['agente_nombre'] ?? 'Sin asignar') ?></dd>
                <dt>Componente</dt><dd><?= e(componente_label($t['componente_afectado'])) ?></dd>
                <?php if ($t['serie'] !== null): ?>
                    <dt>Equipo</dt>
                    <dd class="font-monospace"><?= e($t['serie']) ?> <span class="font-sans-serif text-body-secondary"><?= e(trim(($t['marca'] ?? '') . ' ' . ($t['modelo'] ?? ''))) ?></span></dd>
                <?php endif; ?>
                <?php if ($monitor): ?>
                    <dt>Monitor</dt><dd><?= e($monitor) ?></dd>
                <?php endif; ?>
                <?php if ($t['hostname_reportado'] !== null): ?>
                    <dt>Hostname</dt><dd class="font-monospace"><?= e($t['hostname_reportado']) ?></dd>
                <?php endif; ?>
                <dt>Ubicación física</dt>
                <dd><?= e($t['ubicacion_fisica'] ?? ($t['ubicacion_reportada'] !== null ? $t['ubicacion_reportada'] . ' (GECA)' : 'Sin ubicación')) ?></dd>
                <?php if ($t['referencia'] !== null): ?>
                    <dt>Referencia</dt><dd><?= e($t['referencia']) ?></dd>
                <?php endif; ?>
                <?php if ($t['serie'] !== null): ?>
                    <dt>Ubicación en GECA</dt><dd><?= e($t['ubicacion_geca'] ?? 'Sin ubicación en GECA') ?></dd>
                <?php endif; ?>
                <?php if ($t['cerrado_en'] !== null): ?>
                    <dt>Cerrado</dt><dd><?= e(fecha_mx($t['cerrado_en'])) ?></dd>
                <?php endif; ?>
            </dl>
        </div>

        <?php if ($t['equipo_id'] !== null): ?>
            <?php
            $cubre = static fn (array $g): bool => $g['elemento_cubierto'] === $t['componente_afectado']
                || ($t['componente_afectado'] === 'general' && $g['elemento_cubierto'] === 'equipo');
            ?>
            <div class="card border-0 shadow-sm mb-4">
                <div class="card-header bg-white fw-semibold d-flex justify-content-between align-items-center">
                    <span><i class="bi bi-shield-check me-1"></i>Garantía</span>
                    <?php if ($esTi): ?>
                        <a class="small" href="<?= e(url('/ti/equipos/' . (int) $t['equipo_id'])) ?>">Ver equipo</a>
                    <?php endif; ?>
                </div>
                <?php if ($garantias === []): ?>
                    <div class="card-body small text-body-secondary">El equipo no tiene garantías activas registradas.</div>
                <?php else: ?>
                    <ul class="list-group list-group-flush small">
                        <?php foreach ($garantias as $g): ?>
                            <li class="list-group-item <?= $cubre($g) ? 'bg-success-subtle' : '' ?>">
                                <div class="d-flex justify-content-between gap-2">
                                    <strong><?= e(\App\Services\GarantiaService::ELEMENTOS[$g['elemento_cubierto']]) ?></strong>
                                    <?= garantia_badge($g['estado']) ?>
                                </div>
                                <div class="text-body-secondary"><?= e($g['proveedor']) ?> · vence <?= e(fecha_mx($g['fecha_vencimiento'], false)) ?></div>
                                <?php if ($esTi && $g['numero_poliza_contrato']): ?><div class="text-body-secondary">Póliza <?= e($g['numero_poliza_contrato']) ?></div><?php endif; ?>
                                <?php if ($cubre($g)): ?><div class="text-success-emphasis fw-semibold"><i class="bi bi-check2-circle me-1"></i>Cubre el componente reportado</div><?php endif; ?>
                            </li>
                        <?php endforeach; ?>
                    </ul>
                <?php endif; ?>
            </div>
        <?php endif; ?>

        <?php if ($esTi && !empty($sla)): ?>
            <div class="card border-0 shadow-sm mb-4">
                <div class="card-header bg-white fw-semibold d-flex justify-content-between align-items-center">
                    <span><i class="bi bi-stopwatch me-1"></i>Tiempos de atención</span><?= sla_badge($sla, $cerrado) ?>
                </div>
                <dl class="card-body small mb-0 detail-list">
                    <dt>Primera atención</dt>
                    <dd><?= !empty($t['atendido_en']) ? e(fecha_mx((string) $t['atendido_en'])) : 'Pendiente' ?> <span class="text-body-secondary">(límite <?= e(fecha_mx($sla['vence_respuesta'])) ?>)</span></dd>
                    <dt>Resolución</dt>
                    <dd><?= !empty($t['cerrado_en']) ? e(fecha_mx((string) $t['cerrado_en'])) : 'Pendiente' ?> <span class="text-body-secondary">(límite <?= e(fecha_mx($sla['vence_resolucion'])) ?>)</span></dd>
                </dl>
                <div class="progress rounded-0" style="height: 4px;" role="progressbar" aria-label="Avance del plazo de resolución" aria-valuenow="<?= e((int) round($sla['avance'] * 100)) ?>" aria-valuemin="0" aria-valuemax="100">
                    <div class="progress-bar <?= $sla['avance'] >= 1 ? 'bg-danger' : ($sla['avance'] >= 0.75 ? 'bg-warning' : '') ?>" style="width: <?= e((int) round($sla['avance'] * 100)) ?>%"></div>
                </div>
            </div>
        <?php endif; ?>
        <?php if ($esTi): ?>
            <div class="card border-0 shadow-sm">
                <div class="card-header bg-white fw-semibold"><i class="bi bi-person me-1"></i>Solicitante</div>
                <dl class="card-body small mb-0 detail-list">
                    <dt>Nombre</dt><dd><a href="<?= e(url('/ti/usuarios/' . (int) $t['usuario_solicitante_id'])) ?>"><?= e($t['solicitante_nombre']) ?></a></dd>
                    <dt>Ficha</dt><dd class="font-monospace"><?= e($t['solicitante_ficha']) ?></dd>
                    <dt>Correo</dt><dd class="text-break"><?= e($t['solicitante_email'] ?? '—') ?></dd>
                    <dt>Teléfono / ext.</dt><dd><?= e($t['solicitante_ext'] ?? '—') ?></dd>
                    <dt>Jefe</dt><dd><?= e($t['solicitante_jefe'] ?? '—') ?></dd>
                </dl>
            </div>
        <?php endif; ?>
    </div>
</div>
