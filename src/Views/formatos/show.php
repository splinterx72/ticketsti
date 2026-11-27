<?php
/**
 * @var array<string, mixed> $f
 * @var array<string, mixed> $doc
 * @var string|null $turno  rol que toca firmar (null = completo o cancelado)
 * @var bool $puede  el usuario en sesión puede firmar ese rol (en su sesión o capturando en persona)
 * @var bool $esTi
 * @var bool $esTitular
 * @var bool $esJefe
 */
use App\Services\FormatoService;

$roles = FormatoService::ROLES;
$firmas = $doc['firmas'];
$d = $doc['d'];
$c = $f['contenido'];
$estadoTxt = match ($f['estado']) {
    'completo' => ['Completo: las tres firmas', 'text-bg-success', 'bi-check-circle-fill'],
    'cancelado' => ['Cancelado', 'text-bg-secondary', 'bi-x-circle'],
    default => ['Pendiente de firma: ' . mb_strtolower($roles[$turno]), 'text-bg-warning', 'bi-hourglass-split'],
};
// Firma en persona: TI captura en su dispositivo cuando no firma el titular (o el jefe) en su propia sesión.
$presencial = $puede && $esTi && !($turno === 'usuario' && $esTitular) && !($turno === 'responsable' && $esJefe);
$sugerido = $d['responsable_sugerido'] ?? null;
?>
<div class="d-flex flex-wrap justify-content-between align-items-center gap-2 mb-3">
    <div>
        <div class="small text-body-secondary font-monospace"><?= e($f['folio']) ?><?php if ($f['ticket_id']): ?> · <a href="<?= e(url('/tickets/' . (int) $f['ticket_id'])) ?>"><?= e($f['ticket_folio']) ?></a><?php endif; ?></div>
        <h1 class="h4 mb-1"><?= e($f['titulo']) ?></h1>
        <span class="badge <?= e($estadoTxt[1]) ?>"><i class="bi <?= e($estadoTxt[2]) ?> me-1"></i><?= e($estadoTxt[0]) ?></span>
    </div>
    <div class="d-flex gap-2">
        <a class="btn btn-outline-primary btn-sm" href="<?= e(url('/formatos/' . (int) $f['id'] . '/imprimir')) ?>" target="_blank" rel="noopener"><i class="bi bi-printer me-1"></i>Imprimir / PDF</a>
        <?php if (!empty($f['archivo_autografo'])): ?>
            <a class="btn btn-outline-secondary btn-sm" href="<?= e(url('/formatos/' . (int) $f['id'] . '/escaneo')) ?>"><i class="bi bi-file-earmark-arrow-down me-1"></i>Original firmado</a>
        <?php endif; ?>
    </div>
</div>

<ol class="formato-pasos list-unstyled d-flex flex-wrap gap-2 mb-4">
    <?php foreach ($roles as $rol => $nombre): $fi = $firmas[$rol] ?? null; $actual = $turno === $rol; ?>
        <li class="formato-paso<?= $fi ? ' is-hecho' : ($actual ? ' is-actual' : '') ?>">
            <i class="bi <?= $fi ? 'bi-check-circle-fill' : ($actual ? 'bi-pencil-square' : 'bi-circle') ?>"></i>
            <div><strong><?= e($nombre) ?></strong>
                <div class="small"><?= $fi ? e($fi['nombre']) . ' · ' . e(fecha_mx((string) $fi['firmado_en'])) . ' · ' . e(FormatoService::MODOS[$fi['modo']]) : ($actual ? 'Le toca firmar' : 'En espera') ?></div>
                <?php if ($fi && $fi['motivo']): ?><div class="small text-warning-emphasis"><i class="bi bi-info-circle me-1"></i><?= e($fi['motivo']) ?></div><?php endif; ?>
            </div>
        </li>
    <?php endforeach; ?>
</ol>

<?php if ($f['estado'] === 'cancelado'): ?>
    <div class="msg msg-info mb-4"><span class="msg-ico"><i class="bi bi-info-circle"></i></span><div class="msg-cuerpo"><div class="msg-texto">Cancelado el <?= e(fecha_mx((string) $f['cancelado_en'])) ?>: <?= e($f['motivo_cancelacion']) ?></div></div></div>
<?php endif; ?>

<div class="row g-4">
    <div class="col-xl-8">
        <div class="hoja-scroll"><?= partial('formato_hoja', ['f' => $f, 'doc' => $doc]) ?></div>
    </div>

    <div class="col-xl-4">
        <?php if ($puede): ?>
            <form method="post" action="<?= e(url('/formatos/' . (int) $f['id'] . '/firmar')) ?>" class="card border-0 shadow-sm mb-4 formato-firmar" data-firma-form>
                <?= csrf_field() ?>
                <input type="hidden" name="rol" value="<?= e($turno) ?>">
                <input type="hidden" name="firma" value="" data-firma-dato>
                <?php if ($presencial): ?><input type="hidden" name="modo" value="presencial"><?php endif; ?>
                <div class="card-header bg-white fw-semibold"><i class="bi bi-pen me-1"></i>Firma: <?= e(mb_strtolower($roles[$turno])) ?></div>
                <div class="card-body">
                    <?php if ($turno === 'usuario'): ?>
                        <?php if ($presencial): ?><div class="small text-body-secondary mb-2">Captura en persona: <strong><?= e($d['usuario']) ?></strong> firma en este dispositivo.</div><?php endif; ?>
                        <div class="msg msg-advertencia mb-3"><span class="msg-ico"><i class="bi bi-cloud-arrow-up"></i></span><div class="msg-cuerpo"><div class="msg-texto small">Antes de firmar, respalda tu información en OneDrive, Teams o SharePoint. Al formatear se elimina y <strong>no se puede recuperar</strong>.</div></div></div>
                        <span class="form-label small fw-semibold d-block">Motivo del formateo</span>
                        <?php foreach ((array) $c['motivos'] as $i => $m): ?>
                            <div class="form-check">
                                <input class="form-check-input" type="checkbox" name="motivos[]" value="<?= (int) $i ?>" id="mot<?= (int) $i ?>" <?= in_array((int) $i, $f['motivos_idx'], true) ? 'checked' : '' ?>>
                                <label class="form-check-label small" for="mot<?= (int) $i ?>"><?= e($m) ?></label>
                            </div>
                        <?php endforeach; ?>
                    <?php elseif ($turno === 'responsable'): ?>
                        <?php if ($presencial || $esTitular): ?>
                            <?php if ($presencial): ?>
                                <label class="form-label small fw-semibold" for="ficha">Ficha de quien autoriza</label>
                                <input class="form-control form-control-sm font-monospace mb-1" id="ficha" name="ficha" maxlength="20" value="<?= e($sugerido['ficha'] ?? '') ?>" data-ficha-firmante>
                                <div class="form-text mb-2"><?= $sugerido ? 'Su jefe inmediato según el GECA: ' . e($sugerido['nombre']) . '. Si firma otro jefe de área, escribe su ficha.' : 'Escribe la ficha del jefe que autoriza.' ?> El nombre se toma de la base de usuarios.</div>
                            <?php endif; ?>
                            <div class="form-check mb-2">
                                <input class="form-check-input" type="checkbox" name="mismo_usuario" value="1" id="mismo" data-mismo-usuario>
                                <label class="form-check-label small" for="mismo">No hay jefe disponible: <?= e($d['usuario']) ?> firma también como responsable</label>
                            </div>
                            <div class="mb-2 d-none" data-motivo-mismo>
                                <label class="form-label small fw-semibold" for="motivo">Motivo</label>
                                <input class="form-control form-control-sm" id="motivo" name="motivo" maxlength="255" placeholder="Ej. Jefe de vacaciones y el equipo se necesita hoy">
                            </div>
                        <?php else: ?>
                            <p class="small mb-2">Autorizas, como jefe de <strong><?= e($d['usuario']) ?></strong>, el formateo de su equipo <span class="font-monospace"><?= e($d['serie']) ?></span>.</p>
                        <?php endif; ?>
                    <?php else: ?>
                        <label class="form-label small fw-semibold" for="metodo">Método de formateo</label>
                        <select class="form-select form-select-sm mb-2" id="metodo" name="metodo" required>
                            <option value="">Elige…</option>
                            <?php foreach ((array) $c['metodos'] as $m): ?><option value="<?= e($m) ?>"><?= e($m) ?></option><?php endforeach; ?>
                        </select>
                    <?php endif; ?>

                    <span class="form-label small fw-semibold d-block mt-2">Firma <?= $presencial ? '(con el dedo o el mouse)' : '' ?></span>
                    <div class="firma-pad">
                        <canvas data-firma-canvas aria-label="Recuadro para firmar"></canvas>
                        <button type="button" class="btn btn-sm btn-light firma-limpiar" data-firma-limpiar>Limpiar</button>
                    </div>
                    <label class="form-label small fw-semibold mt-3" for="password">Tu contraseña <?= $presencial ? '(TI, para confirmar la captura)' : 'para confirmar' ?></label>
                    <input type="password" class="form-control form-control-sm" id="password" name="password" required autocomplete="current-password">
                </div>
                <div class="card-footer bg-white text-end"><button class="btn btn-primary" type="submit"><i class="bi bi-pen me-1"></i>Firmar</button></div>
            </form>
        <?php elseif ($turno !== null): ?>
            <div class="card border-0 shadow-sm mb-4"><div class="card-body small text-body-secondary"><i class="bi bi-hourglass-split me-1"></i>Esperando la firma de: <strong><?= e(mb_strtolower($roles[$turno])) ?></strong>.</div></div>
        <?php endif; ?>

        <?php if ($esTi && $turno !== null): ?>
            <form method="post" action="<?= e(url('/formatos/' . (int) $f['id'] . '/inventario')) ?>" class="card border-0 shadow-sm mb-4">
                <?= csrf_field() ?>
                <div class="card-body">
                    <label class="form-label small fw-semibold" for="inventario">Inventario PEMEX o compañía propietaria</label>
                    <div class="input-group input-group-sm">
                        <input class="form-control font-monospace" id="inventario" name="inventario" maxlength="40" value="<?= e($d['inventario'] === 'N/A' ? '' : $d['inventario']) ?>" placeholder="N/A si no aplica">
                        <button class="btn btn-outline-primary" type="submit">Guardar</button>
                    </div>
                    <div class="form-text">Se guarda en el equipo y aparecerá en sus próximos formatos. El folio de Remedy se captura en el ticket.</div>
                </div>
            </form>

            <details class="card border-0 shadow-sm mb-4">
                <summary class="card-header bg-white fw-semibold"><i class="bi bi-file-earmark-arrow-up me-1"></i>Se firmó en papel</summary>
                <form method="post" action="<?= e(url('/formatos/' . (int) $f['id'] . '/autografa')) ?>" enctype="multipart/form-data" class="card-body">
                    <?= csrf_field() ?>
                    <p class="small text-body-secondary">Imprime el formato, recaba las firmas y sube el escaneo o una foto legible. Las firmas que falten se registran como autógrafas.</p>
                    <?php if (!isset($firmas['usuario'])): ?>
                        <span class="form-label small fw-semibold d-block">Motivos marcados en el papel</span>
                        <?php foreach ((array) $c['motivos'] as $i => $m): ?>
                            <div class="form-check"><input class="form-check-input" type="checkbox" name="motivos[]" value="<?= (int) $i ?>" id="pmot<?= (int) $i ?>"><label class="form-check-label small" for="pmot<?= (int) $i ?>"><?= e($m) ?></label></div>
                        <?php endforeach; ?>
                    <?php endif; ?>
                    <?php if (!isset($firmas['responsable'])): ?>
                        <label class="form-label small fw-semibold mt-2" for="pficha">Ficha de quien autorizó</label>
                        <input class="form-control form-control-sm font-monospace" id="pficha" name="ficha" maxlength="20" value="<?= e($sugerido['ficha'] ?? '') ?>">
                        <div class="form-check mt-1"><input class="form-check-input" type="checkbox" name="mismo_usuario" value="1" id="pmismo"><label class="form-check-label small" for="pmismo">El usuario firmó también como responsable</label></div>
                        <input class="form-control form-control-sm mt-1" name="motivo" maxlength="255" placeholder="Motivo (si firmó el mismo usuario)">
                    <?php endif; ?>
                    <label class="form-label small fw-semibold mt-2" for="pmetodo">Método de formateo</label>
                    <select class="form-select form-select-sm" id="pmetodo" name="metodo" required>
                        <option value="">Elige…</option>
                        <?php foreach ((array) $c['metodos'] as $m): ?><option value="<?= e($m) ?>"><?= e($m) ?></option><?php endforeach; ?>
                    </select>
                    <label class="form-label small fw-semibold mt-2" for="escaneo">Formato firmado (PDF o imagen)</label>
                    <input type="file" class="form-control form-control-sm" id="escaneo" name="escaneo" accept=".pdf,.jpg,.jpeg,.png,.webp" required>
                    <label class="form-label small fw-semibold mt-2" for="ppass">Tu contraseña</label>
                    <input type="password" class="form-control form-control-sm" id="ppass" name="password" required autocomplete="current-password">
                    <div class="text-end mt-3"><button class="btn btn-sm btn-primary" type="submit">Registrar papel firmado</button></div>
                </form>
            </details>

            <details class="card border-0 shadow-sm">
                <summary class="card-header bg-white fw-semibold text-danger"><i class="bi bi-x-circle me-1"></i>Cancelar formato</summary>
                <form method="post" action="<?= e(url('/formatos/' . (int) $f['id'] . '/cancelar')) ?>" class="card-body" data-confirm="¿Cancelar el formato <?= e($f['folio']) ?>?">
                    <?= csrf_field() ?>
                    <input class="form-control form-control-sm" name="motivo_cancelacion" maxlength="255" required placeholder="Motivo (p. ej. se resolvió sin formatear)">
                    <div class="text-end mt-2"><button class="btn btn-sm btn-outline-danger" type="submit">Cancelar formato</button></div>
                </form>
            </details>
        <?php endif; ?>
    </div>
</div>
