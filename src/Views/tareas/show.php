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
            $evid = $deAsignacion((int) $a['id']);
            ?>
            <div class="card border-0 shadow-sm mb-3">
                <div class="card-header bg-white d-flex justify-content-between align-items-center">
                    <span class="fw-semibold"><?= e($esMia ? 'Mi entrega' : $a['agente_nombre']) ?></span>
                    <span class="badge text-bg-<?= e($color) ?>"><?= e($label) ?></span>
                </div>
                <div class="card-body small">
                    <?php if ($a['entregado_en']): ?>
                        <div class="text-body-secondary mb-1">Entregada <?= e(fecha_mx($a['entregado_en'])) ?></div>
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

                    <?php if ($esMia && in_array($a['estado'], ['asignada', 'devuelta'], true)): ?>
                        <form method="post" action="<?= e(url('/tareas/asignacion/' . (int) $a['id'] . '/iniciar')) ?>" class="d-inline">
                            <?= csrf_field() ?>
                            <button class="btn btn-sm btn-outline-primary" type="submit"><i class="bi bi-play me-1"></i>Marcar en proceso</button>
                        </form>
                    <?php endif; ?>
                    <?php if ($esMia && in_array($a['estado'], ['asignada', 'en_proceso', 'devuelta'], true)): ?>
                        <form method="post" action="<?= e(url('/tareas/asignacion/' . (int) $a['id'] . '/entregar')) ?>" enctype="multipart/form-data" class="needs-loading mt-3 border-top pt-3">
                            <?= csrf_field() ?>
                            <label class="form-label fw-semibold" for="evidencias<?= e($a['id']) ?>">Evidencias</label>
                            <input class="form-control mb-2" type="file" id="evidencias<?= e($a['id']) ?>" name="evidencias[]" multiple accept="<?= e($accept) ?>">
                            <label class="form-label fw-semibold" for="comentario<?= e($a['id']) ?>">Comentario</label>
                            <textarea class="form-control mb-2" id="comentario<?= e($a['id']) ?>" name="comentario" rows="2" maxlength="3000" placeholder="¿Qué hiciste? ¿Algo pendiente?"></textarea>
                            <button class="btn btn-primary" type="submit"><span class="spinner-border spinner-border-sm me-1 d-none" aria-hidden="true"></span><i class="bi bi-upload me-1"></i>Entregar</button>
                        </form>
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
