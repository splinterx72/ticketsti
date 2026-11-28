<?php
/**
 * @var array<string, mixed> $tarea
 * @var bool $esRevisor
 * @var array<string, mixed>|null $mia
 */
$t = $tarea;
$estados = \App\Services\TareaService::ESTADOS;
$tipos = \App\Services\TareaService::TIPOS_EVIDENCIA;
$accept = '.' . implode(',.', array_keys(\App\Services\ArchivoService::PERMITIDOS));
$referencias = array_values(array_filter($t['archivos'], static fn (array $f): bool => $f['asignacion_id'] === null));
$deAsignacion = static fn (int $aid): array => array_values(array_filter($t['archivos'], static fn (array $f): bool => (int) $f['asignacion_id'] === $aid));
$vencida = $t['entrega_en'] < date('Y-m-d H:i:s');
$asignaciones = $esRevisor ? $t['asignaciones'] : [$mia];
$unidad = \App\Services\TareaService::unidad($t);
$meta = isset($t['meta_cantidad']) ? (int) $t['meta_cantidad'] : null;
$deAvance = static fn (int $avId): array => array_values(array_filter($t['archivos'], static fn (array $f): bool => (int) ($f['avance_id'] ?? 0) === $avId));
$barra = static function (?int $pct): string {
    if ($pct === null) {
        return '';
    }
    $color = $pct >= 100 ? 'bg-success' : 'bg-primary';
    return '<div class="progress mt-1" style="height: 8px;" role="progressbar" aria-label="Avance" aria-valuenow="' . $pct . '" aria-valuemin="0" aria-valuemax="100">'
        . '<div class="progress-bar ' . $color . '" style="width: ' . $pct . '%"></div></div>';
};
$archivoLink = static fn (array $f): string => '<a href="' . e(url('/tareas/archivo/' . (int) $f['id'])) . '" class="d-inline-flex align-items-center gap-1 me-3"><i class="bi bi-paperclip"></i>' . e($f['nombre_original']) . '</a> <span class="text-body-secondary small">' . e(number_format((int) $f['tamano'] / 1024, 0)) . ' KB</span>';
?>
<div class="d-flex flex-wrap justify-content-between align-items-start gap-2 mb-3">
    <div>
        <div class="small text-body-secondary font-monospace"><?= e($t['folio']) ?> · Asigna <?= e($t['supervisor_nombre'] ?? '—') ?></div>
        <h1 class="h4 mb-1"><?= e($t['titulo']) ?></h1>
        <div class="d-flex flex-wrap gap-1">
            <?= prioridad_badge((string) $t['prioridad']) ?>
            <span class="badge <?= $vencida ? 'text-bg-danger' : 'text-bg-light border' ?>"><i class="bi bi-calendar-event me-1"></i>Entrega <?= e(fecha_mx($t['entrega_en'])) ?></span>
        </div>
    </div>
    <a href="<?= e(url('/tareas')) ?>" class="btn btn-link"><i class="bi bi-arrow-left me-1"></i>Tareas</a>
</div>

<div class="row g-4">
    <div class="col-lg-5">
        <?php if (($t['porcentaje'] ?? null) !== null): ?>
            <div class="card border-0 shadow-sm mb-4">
                <div class="card-header bg-white fw-semibold"><i class="bi bi-graph-up me-1"></i>Avance<?= $esRevisor && count($t['asignaciones']) > 1 ? ' de la tarea' : '' ?></div>
                <div class="card-body">
                    <?php if ($esRevisor): ?>
                        <?= \App\Core\Grafica::anillo((int) $t['porcentaje'], number_format((int) $t['hecho']) . ' de ' . number_format((int) $t['meta_total']) . ' ' . $unidad . ' (' . number_format($meta) . ' por agente)') ?>
                        <?php if (count($t['asignaciones']) > 1): ?>
                            <ul class="list-unstyled small mt-3 mb-0">
                                <?php foreach ($t['asignaciones'] as $a): ?>
                                    <li class="mb-2"><div class="d-flex justify-content-between"><span><?= e($a['agente_nombre']) ?></span><strong><?= e($a['porcentaje']) ?> %</strong></div><?= $barra($a['porcentaje']) ?></li>
                                <?php endforeach; ?>
                            </ul>
                        <?php endif; ?>
                    <?php elseif ($mia !== null): ?>
                        <?= \App\Core\Grafica::anillo((int) $mia['porcentaje'], number_format((int) $mia['hecho']) . ' de ' . number_format($meta) . ' ' . $unidad) ?>
                    <?php endif; ?>
                </div>
            </div>
        <?php endif; ?>
        <div class="card border-0 shadow-sm mb-4">
            <div class="card-header bg-white fw-semibold">Instrucciones</div>
            <div class="card-body">
                <div class="ticket-description"><?= texto_multilinea($t['instrucciones']) ?></div>
                <?php if ($t['evidencias'] !== []): ?>
                    <hr>
                    <div class="small fw-semibold mb-1">Evidencias requeridas</div>
                    <ul class="small mb-0">
                        <?php foreach ($t['evidencias'] as $ev): ?><li><span class="badge text-bg-light border"><?= e($tipos[$ev['tipo']] ?? $ev['tipo']) ?></span> <?= e($ev['descripcion']) ?></li><?php endforeach; ?>
                    </ul>
                <?php endif; ?>
                <?php if ($referencias !== []): ?>
                    <hr>
                    <div class="small fw-semibold mb-1">Archivos de referencia</div>
                    <div class="small"><?php foreach ($referencias as $f): ?><div><?= $archivoLink($f) ?></div><?php endforeach; ?></div>
                <?php endif; ?>
            </div>
        </div>
    </div>

    <div class="col-lg-7">
        <?php foreach ($asignaciones as $a):
            [$label, $color] = $estados[$a['estado']];
            $esMia = $mia !== null && (int) $a['id'] === (int) $mia['id'];
            $evid = array_values(array_filter($deAsignacion((int) $a['id']), static fn (array $f): bool => empty($f['avance_id'])));
            $abierta = in_array($a['estado'], ['asignada', 'en_proceso', 'devuelta'], true);
            ?>
            <div class="card border-0 shadow-sm mb-3">
                <div class="card-header bg-white">
                    <div class="d-flex justify-content-between align-items-center gap-2">
                        <span class="fw-semibold"><?= e($esMia ? 'Mi hoja de reportes' : $a['agente_nombre']) ?></span>
                        <span>
                            <?php if (($a['porcentaje'] ?? null) !== null): ?><strong class="small me-1"><?= e($a['porcentaje']) ?> %</strong><?php endif; ?>
                            <?php if (($a['porcentaje'] ?? 0) >= 100 && in_array($a['estado'], ['asignada', 'en_proceso', 'devuelta'], true)): ?><span class="badge text-bg-success"><i class="bi bi-check2-circle me-1"></i>Lista para cierre</span><?php endif; ?>
                            <span class="badge text-bg-<?= e($color) ?>"><?= e($label) ?></span>
                        </span>
                    </div>
                    <?= $barra($a['porcentaje'] ?? null) ?>
                </div>
                <div class="card-body small">
                    <?php if ($a['entregado_en']): ?>
                        <div class="text-body-secondary mb-1"><i class="bi bi-flag me-1"></i>Cierre <?= e(fecha_mx($a['entregado_en'])) ?></div>
                    <?php endif; ?>
                    <?php if ($a['comentario_entrega']): ?><div class="mb-2"><?= texto_multilinea($a['comentario_entrega']) ?></div><?php endif; ?>
                    <?php if ($evid !== []): ?>
                        <div class="mb-2"><?php foreach ($evid as $f): ?><div><?= $archivoLink($f) ?></div><?php endforeach; ?></div>
                    <?php endif; ?>
                    <?php if ($a['comentario_revision']): ?>
                        <div class="alert <?= $a['estado'] === 'devuelta' ? 'alert-warning' : 'alert-success' ?> py-2 mb-2">
                            <strong>Supervisor:</strong> <?= texto_multilinea($a['comentario_revision']) ?>
                        </div>
                    <?php endif; ?>

                    <?php if ($esMia && $abierta): ?>
                        <form method="post" action="<?= e(url('/tareas/asignacion/' . (int) $a['id'] . '/reportar')) ?>" enctype="multipart/form-data" class="needs-loading border rounded-3 p-3 mb-3 bg-body-tertiary" id="hoja">
                            <?= csrf_field() ?>
                            <label class="form-label fw-semibold" for="texto<?= e($a['id']) ?>"><i class="bi bi-journal-plus me-1"></i>Nuevo reporte</label>
                            <textarea class="form-control mb-2" id="texto<?= e($a['id']) ?>" name="texto" rows="3" maxlength="3000" required placeholder="¿Qué hiciste? Ej. Se formatearon 6 equipos del nivel 2, falta el ala sur."></textarea>
                            <div class="row g-2 align-items-end">
                                <?php if ($meta !== null): ?>
                                    <div class="col-sm-4">
                                        <label class="form-label small fw-semibold mb-1" for="cant<?= e($a['id']) ?>"><?= e(ucfirst($unidad)) ?> atendidos</label>
                                        <input type="number" class="form-control" id="cant<?= e($a['id']) ?>" name="cantidad" min="0" max="100000" value="0">
                                    </div>
                                <?php endif; ?>
                                <div class="col">
                                    <label class="form-label small fw-semibold mb-1" for="ev<?= e($a['id']) ?>">Evidencias <span class="fw-normal text-body-secondary">(opcional)</span></label>
                                    <input class="form-control" type="file" id="ev<?= e($a['id']) ?>" name="evidencias[]" multiple accept="<?= e($accept) ?>">
                                </div>
                                <div class="col-12 text-end">
                                    <button class="btn btn-primary" type="submit"><span class="spinner-border spinner-border-sm me-1 d-none" aria-hidden="true"></span><i class="bi bi-plus-lg me-1"></i>Agregar reporte</button>
                                </div>
                            </div>
                        </form>
                    <?php endif; ?>

                    <?php if (($a['avances'] ?? []) !== []): ?>
                        <ol class="list-unstyled tarea-hoja mb-3">
                            <?php foreach ($a['avances'] as $av): $evAv = $deAvance((int) $av['id']); ?>
                                <li class="border-start border-3 ps-3 pb-2 mb-2">
                                    <div class="text-body-secondary"><?= e(fecha_mx($av['creado_en'])) ?><?= !$esMia ? ' · ' . e($av['autor'] ?? '') : '' ?>
                                        <?php if ($meta !== null && (int) $av['cantidad'] > 0): ?><span class="badge text-bg-light border ms-1">+<?= e($av['cantidad']) ?> <?= e($unidad) ?></span><?php endif; ?>
                                        <?php if (!empty($av['ticket_folio'])): ?><a class="ms-1 font-monospace" href="<?= e(url('/tickets/' . (int) $av['ticket_id'])) ?>"><?= e($av['ticket_folio']) ?></a><?php endif; ?>
                                    </div>
                                    <div><?= texto_multilinea((string) $av['texto']) ?></div>
                                    <?php foreach ($evAv as $f): ?><div><?= $archivoLink($f) ?></div><?php endforeach; ?>
                                </li>
                            <?php endforeach; ?>
                        </ol>
                    <?php elseif (!$esMia): ?>
                        <div class="text-body-secondary mb-2">Aún no hay reportes.</div>
                    <?php endif; ?>

                    <?php if ($esMia && $abierta): $bajo = ($a['porcentaje'] ?? 100) < 100; ?>
                        <details class="border-top pt-3" <?= !$bajo && $meta !== null ? 'open' : '' ?>>
                            <summary class="fw-semibold"><i class="bi bi-flag me-1"></i>Cierre de la tarea</summary>
                            <form method="post" action="<?= e(url('/tareas/asignacion/' . (int) $a['id'] . '/entregar')) ?>" enctype="multipart/form-data" class="needs-loading mt-2"
                                  data-confirm="¿Cerrar tu parte de la tarea? Se envía al supervisor para su revisión.">
                                <?= csrf_field() ?>
                                <label class="form-label small fw-semibold" for="evidencias<?= e($a['id']) ?>">Evidencias finales <span class="fw-normal text-body-secondary">(opcional si ya las subiste en tus reportes)</span></label>
                                <input class="form-control mb-2" type="file" id="evidencias<?= e($a['id']) ?>" name="evidencias[]" multiple accept="<?= e($accept) ?>">
                                <label class="form-label small fw-semibold" for="comentario<?= e($a['id']) ?>"><?= $bajo && $meta !== null ? 'Motivo del cierre antes del 100 % (obligatorio)' : 'Comentario final' ?></label>
                                <textarea class="form-control mb-2" id="comentario<?= e($a['id']) ?>" name="comentario" rows="2" maxlength="3000" <?= $bajo && $meta !== null ? 'required' : '' ?> placeholder="Resumen de lo realizado o lo que quedó pendiente."></textarea>
                                <button class="btn btn-success" type="submit"><span class="spinner-border spinner-border-sm me-1 d-none" aria-hidden="true"></span><i class="bi bi-flag-fill me-1"></i>Cierre</button>
                            </form>
                        </details>
                    <?php endif; ?>
                    <?php if ($esRevisor && $a['estado'] === 'entregada'): ?>
                        <div class="d-flex flex-wrap gap-2 mt-2">
                            <form method="post" action="<?= e(url('/tareas/asignacion/' . (int) $a['id'] . '/aceptar')) ?>" class="m-0">
                                <?= csrf_field() ?>
                                <button class="btn btn-sm btn-success" type="submit"><i class="bi bi-check2 me-1"></i>Aceptar</button>
                            </form>
                            <form method="post" action="<?= e(url('/tareas/asignacion/' . (int) $a['id'] . '/devolver')) ?>" class="m-0 d-flex gap-2 flex-grow-1">
                                <?= csrf_field() ?>
                                <input type="text" class="form-control form-control-sm" name="comentario" maxlength="3000" required placeholder="¿Qué debe corregir?" aria-label="Comentario para devolver">
                                <button class="btn btn-sm btn-outline-warning text-nowrap" type="submit">Devolver</button>
                            </form>
                        </div>
                    <?php endif; ?>
                </div>
            </div>
        <?php endforeach; ?>
    </div>
</div>
