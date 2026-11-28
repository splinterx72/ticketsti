<?php
/**
 * @var array<string, mixed> $ticket
 * @var list<array<string, mixed>> $comentarios
 * @var bool $esTi
 * @var list<array<string, mixed>> $estados
 * @var list<array<string, mixed>> $departamentos
 * @var list<array<string, mixed>> $agentes
 * @var list<array<string, mixed>> $garantias
 * @var list<array{id: int, nombre: string, descripcion: ?string, activo: int}> $categorias
 * @var list<array<string, mixed>> $equiposTi  equipos de un ticket de TI
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
                    <?php if (!empty($t['creado_por_nombre'])): ?>
                        <strong class="text-body"><?= e($t['creado_por_nombre']) ?></strong> levantó este ticket a nombre de <strong class="text-body"><?= e($t['solicitante_nombre']) ?></strong> <?= e(hace($t['creado_en'])) ?>
                    <?php else: ?>
                        <strong class="text-body"><?= e($t['solicitante_nombre']) ?></strong> abrió este ticket <?= e(hace($t['creado_en'])) ?>
                    <?php endif; ?>
                    <span title="<?= e(fecha_mx($t['creado_en'])) ?>">(<?= e(fecha_mx($t['creado_en'])) ?>)</span>
                </div>
                <div class="ticket-description"><?= texto_multilinea($t['descripcion']) ?></div>
                <?= partial('adjuntos', ['adjuntos' => array_values(array_filter($adjuntos ?? [], static fn (array $x): bool => $x['comentario_id'] === null))]) ?>
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
                            <?= partial('adjuntos', ['adjuntos' => array_values(array_filter($adjuntos ?? [], static fn (array $x): bool => (int) $x['comentario_id'] === (int) $c['id']))]) ?>
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
            <form method="post" action="<?= e(url('/tickets/' . (int) $t['id'] . '/comentarios')) ?>" class="card border-0 shadow-sm needs-loading" enctype="multipart/form-data">
                <div class="card-body">
                    <?= csrf_field() ?>
                    <label for="mensaje" class="form-label fw-semibold">Agregar comentario</label>
                    <textarea class="form-control" id="mensaje" name="mensaje" rows="3" maxlength="5000" required
                              placeholder="<?= $esTi ? 'Respuesta al usuario o nota interna…' : 'Escribe información adicional o responde a TI…' ?>"><?= e($old['mensaje'] ?? '') ?></textarea>
                    <input type="file" class="form-control form-control-sm mt-2" name="adjuntos[]" multiple accept=".jpg,.jpeg,.png,.webp,.pdf" aria-label="Adjuntar fotos o PDF (opcional)">
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
        <?php if ($esTi && ($plantillas ?? null) !== null): ?>
            <div class="mt-4"><?= partial('enviar_correo', [
                'plantillas' => $plantillas, 'destinatarioId' => (int) $t['usuario_solicitante_id'], 'ticketId' => (int) $t['id'],
                'destinatario' => $t['solicitante_nombre'] . ' <' . ($t['solicitante_email'] ?? 'sin correo') . '>',
            ]) ?></div>
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
                <?php if (($candidatos ?? []) !== []): $sug = $candidatos[0]; ?>
                    <div class="card-body border-bottom pb-3">
                        <div class="small text-body-secondary mb-1"><i class="bi bi-stars me-1"></i>Agente sugerido</div>
                        <div class="d-flex justify-content-between align-items-center gap-2">
                            <div><strong><?= e($sug['nombre']) ?></strong><div class="small text-body-secondary"><?= e($sug['motivo']) ?></div></div>
                            <form method="post" action="<?= e(url('/tickets/' . (int) $t['id'] . '/asignar-sugerido')) ?>" class="m-0">
                                <?= csrf_field() ?>
                                <input type="hidden" name="agente_id" value="<?= (int) $sug['id'] ?>">
                                <button class="btn btn-sm btn-primary text-nowrap" type="submit">Asignar</button>
                            </form>
                        </div>
                        <?php if (count($candidatos) > 1): ?>
                            <div class="small text-body-secondary mt-2">Otras opciones:
                                <?= e(implode(' · ', array_map(static fn (array $c): string => $c['nombre'] . ' (' . $c['puntos'] . ' pts)', array_slice($candidatos, 1)))) ?>
                            </div>
                        <?php endif; ?>
                    </div>
                <?php elseif ($esTi && $t['agente_asignado_id'] === null && (int) $t['es_cierre'] === 0): ?>
                    <div class="card-body border-bottom small text-body-secondary"><i class="bi bi-info-circle me-1"></i>Sin agente disponible para sugerir en esta instalación.</div>
                <?php endif; ?>
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
                    <?php if (($categorias ?? []) !== []): ?>
                        <div class="mb-3">
                            <label class="form-label small fw-semibold" for="categoria_g">Categoría</label>
                            <select class="form-select form-select-sm" id="categoria_g" name="categoria_id">
                                <?php if (empty($t['categoria_id'])): ?><option value="">Sin categoría</option><?php endif; ?>
                                <?php foreach ($categorias as $c): if ($c['activo'] !== 1 && $c['id'] !== (int) ($t['categoria_id'] ?? 0)) { continue; } ?>
                                    <option value="<?= e($c['id']) ?>" <?= $c['id'] === (int) ($t['categoria_id'] ?? 0) ? 'selected' : '' ?>><?= e($c['nombre']) ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                    <?php endif; ?>
                    <div class="mb-3">
                        <label class="form-label small fw-semibold" for="folio_remedy">Folio Remedy</label>
                        <input class="form-control form-control-sm font-monospace" id="folio_remedy" name="folio_remedy" maxlength="40" value="<?= e($t['folio_remedy'] ?? '') ?>" placeholder="Captúralo cuando llegue (p. ej. WO0000014931785)">
                    </div>
                    <button type="submit" class="btn btn-primary btn-sm w-100"><i class="bi bi-check2 me-1"></i>Guardar cambios</button>
                </form>
            </div>
        <?php endif; ?>

        <?php $formatos = $formatos ?? []; $hayActivo = array_filter($formatos, static fn (array $x): bool => str_starts_with((string) $x['estado'], 'pendiente')) !== []; ?>
        <?php if ($formatos !== [] || ($esTi && modulo('formatos') && $t['equipo_id'] !== null && !$cerrado)): ?>
            <div class="card border-0 shadow-sm mb-4">
                <div class="card-header bg-white fw-semibold"><i class="bi bi-file-earmark-check me-1"></i>Autorización de formateo</div>
                <?php if ($formatos !== []): ?>
                    <ul class="list-group list-group-flush">
                        <?php foreach ($formatos as $fm):
                            $txt = match ($fm['estado']) {
                                'completo' => ['Completo', 'text-success', 'bi-check-circle-fill'],
                                'cancelado' => ['Cancelado', 'text-body-secondary', 'bi-x-circle'],
                                'pendiente_usuario' => ['Falta firma del usuario', 'text-warning-emphasis', 'bi-hourglass-split'],
                                'pendiente_responsable' => ['Falta firma del jefe', 'text-warning-emphasis', 'bi-hourglass-split'],
                                default => ['Falta firma de TI', 'text-warning-emphasis', 'bi-hourglass-split'],
                            }; ?>
                            <li class="list-group-item d-flex justify-content-between align-items-center gap-2">
                                <span><span class="font-monospace small"><?= e($fm['folio']) ?></span>
                                    <div class="small <?= e($txt[1]) ?>"><i class="bi <?= e($txt[2]) ?> me-1"></i><?= e($txt[0]) ?></div></span>
                                <a class="btn btn-sm btn-outline-primary" href="<?= e(url('/formatos/' . (int) $fm['id'])) ?>"><?= str_starts_with((string) $fm['estado'], 'pendiente') ? 'Abrir y firmar' : 'Ver' ?></a>
                            </li>
                        <?php endforeach; ?>
                    </ul>
                <?php endif; ?>
                <?php if ($esTi && modulo('formatos') && $t['equipo_id'] !== null && !$cerrado && !$hayActivo): ?>
                    <form method="post" action="<?= e(url('/tickets/' . (int) $t['id'] . '/formato')) ?>" class="card-body">
                        <?= csrf_field() ?>
                        <p class="small text-body-secondary mb-2">Si el equipo se va a formatear, genera la autorización: la firman el usuario, su jefe y TI, y el ticket no se cierra hasta completarla.</p>
                        <button class="btn btn-sm btn-outline-primary w-100" type="submit"><i class="bi bi-file-earmark-plus me-1"></i>Generar formato de formateo</button>
                    </form>
                <?php endif; ?>
            </div>
        <?php endif; ?>

        <?php if (($equiposTi ?? []) !== []): ?>
            <div class="card border-0 shadow-sm mb-4">
                <div class="card-header bg-white fw-semibold"><i class="bi bi-pc-display me-1"></i>Equipos del trabajo (<?= e(count($equiposTi)) ?>)</div>
                <ul class="list-group list-group-flush small" style="max-height: 22rem; overflow-y: auto;">
                    <?php foreach ($equiposTi as $q): ?>
                        <li class="list-group-item">
                            <?php if ($esTi): ?><a class="font-monospace" href="<?= e(url('/ti/equipos/' . (int) $q['id'])) ?>"><?= e($q['serie']) ?></a><?php else: ?><span class="font-monospace"><?= e($q['serie']) ?></span><?php endif; ?>
                            <span class="text-body-secondary"><?= e(trim(($q['tipo_componente'] ? tipo_equipo((string) $q['tipo_componente']) . ' · ' : '') . ($q['marca'] ?? '') . ' ' . ($q['modelo'] ?? ''))) ?></span>
                            <div class="text-body-secondary"><?= $q['ficha'] ? e($q['ficha'] . ' · ' . $q['usuario_nombre']) : 'Sin resguardante' ?></div>
                        </li>
                    <?php endforeach; ?>
                </ul>
            </div>
        <?php endif; ?>

        <div class="card border-0 shadow-sm mb-4">
            <div class="card-header bg-white fw-semibold"><i class="bi bi-info-circle me-1"></i>Detalles</div>
            <dl class="card-body small mb-0 detail-list">
                <?php if (!empty($t['categoria_nombre'])): ?><dt>Categoría</dt><dd title="<?= e($t['categoria_descripcion'] ?? '') ?>"><?= e($t['categoria_nombre']) ?></dd><?php endif; ?>
                <dt>Departamento</dt><dd><?= e($t['departamento_nombre']) ?></dd>
                <?php if (!empty($t['folio_remedy'])): ?><dt>Folio Remedy</dt><dd class="font-monospace"><?= e($t['folio_remedy']) ?></dd><?php endif; ?>
                <dt>Atiende</dt><dd><?= e($t['agente_nombre'] ?? 'Sin asignar') ?></dd>
                <dt>Componente</dt><dd><?= e(componente_label($t['componente_afectado'])) ?></dd>
                <?php if ($t['serie'] !== null): ?>
                    <dt>Equipo</dt>
                    <dd class="font-monospace"><?= e($t['serie']) ?> <span class="font-sans-serif text-body-secondary"><?= e(trim(($t['marca'] ?? '') . ' ' . ($t['modelo'] ?? ''))) ?></span>
                        <?php if (!empty($t['equipo_id']) && modulo('bitlocker')): ?><a class="font-sans-serif small ms-1" href="<?= e(url('/equipos/' . (int) $t['equipo_id'] . '/bitlocker')) ?>"><i class="bi bi-key me-1"></i>BitLocker</a><?php endif; ?></dd>
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
                    <?php if (!empty($t['solicitante_celular'])): ?>
                        <dt>Celular</dt><dd><a href="tel:<?= e(preg_replace('/[^0-9+]/', '', (string) $t['solicitante_celular'])) ?>"><?= e($t['solicitante_celular']) ?></a></dd>
                    <?php endif; ?>
                    <?php if (!empty($t['creado_por_nombre'])): ?>
                        <dt>Levantado por</dt><dd><a href="<?= e(url('/ti/usuarios/' . (int) $t['creado_por_id'])) ?>"><?= e($t['creado_por_nombre']) ?></a>
                            <div class="small text-body-secondary"><?= e(implode(' · ', array_filter([
                                !empty($t['creado_por_ext']) ? 'Ext. ' . $t['creado_por_ext'] : null,
                                !empty($t['creado_por_celular']) ? 'Cel. ' . $t['creado_por_celular'] : null,
                            ]))) ?></div></dd>
                    <?php endif; ?>
                    <dt>Jefe</dt><dd><?= e($t['solicitante_jefe'] ?? '—') ?><?= !empty($t['solicitante_jefe_ficha']) ? ' <span class="font-monospace text-body-secondary">(' . e($t['solicitante_jefe_ficha']) . ')</span>' : '' ?></dd>
                </dl>
            </div>
        <?php endif; ?>
    </div>
</div>
