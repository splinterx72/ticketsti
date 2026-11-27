<?php
/**
 * @var array<string, mixed> $equipo
 * @var list<array<string, mixed>> $registros
 * @var int|null $revelado
 * @var string|null $llave
 * @var list<array<string, mixed>> $bitacora
 * @var bool $esTi
 * @var array<string, string> $old
 */
$unidades = \App\Services\BitLockerService::UNIDADES;
?>
<div class="d-flex flex-wrap justify-content-between align-items-start gap-2 mb-3">
    <div>
        <div class="small text-body-secondary text-uppercase fw-semibold">BitLocker</div>
        <h1 class="h4 mb-1"><span class="font-monospace"><?= e($equipo['serie']) ?></span>
            <span class="fs-6 text-body-secondary"><?= e(trim(tipo_equipo($equipo['tipo_componente']) . ' · ' . trim(($equipo['marca'] ?? '') . ' ' . ($equipo['modelo'] ?? '')), ' ·')) ?></span></h1>
        <?php if (!empty($equipo['resguardante'])): ?><div class="small text-body-secondary">Resguardante: <?= e($equipo['resguardante']) ?> (<?= e($equipo['ficha']) ?>)</div><?php endif; ?>
    </div>
    <a href="<?= e(url($esTi ? '/ti/equipos/' . (int) $equipo['id'] : '/')) ?>" class="btn btn-link"><i class="bi bi-arrow-left me-1"></i><?= $esTi ? 'Ficha del equipo' : 'Inicio' ?></a>
</div>

<div class="alert alert-info small">
    <i class="bi bi-shield-lock me-1"></i>
    Si al encender aparece la pantalla azul de <strong>recuperación de BitLocker</strong>, te pedirá una llave de 48 dígitos e indicará su
    <strong>identificador</strong>. Registra aquí el identificador (y si quieres la llave) para que TI la ubique rápido. La llave se guarda
    cifrada, para verla hay que escribir la contraseña y cada consulta queda registrada. Nunca se envía por correo.
</div>

<div class="row g-4">
    <div class="col-lg-7">
        <div class="card border-0 shadow-sm">
            <div class="card-header bg-white fw-semibold"><i class="bi bi-key me-1"></i>Registros de este equipo</div>
            <?php if ($registros === []): ?>
                <div class="card-body small text-body-secondary">Aún no hay datos de BitLocker de este equipo.</div>
            <?php else: ?>
                <ul class="list-group list-group-flush">
                    <?php foreach ($registros as $r): ?>
                        <li class="list-group-item">
                            <div class="d-flex flex-wrap justify-content-between gap-2">
                                <div>
                                    <span class="badge text-bg-light border me-1"><?= e($r['unidad']) ?></span>
                                    <span class="small text-body-secondary">Identificador</span>
                                    <span class="font-monospace fw-semibold"><?= e($r['identificador']) ?></span>
                                    <?php if (!empty($r['nota'])): ?><div class="small text-body-secondary"><?= e($r['nota']) ?></div><?php endif; ?>
                                    <div class="small text-body-secondary"><?= e(fecha_mx((string) $r['creado_en'])) ?> · <?= e($r['capturado_por_nombre'] ?? '—') ?>
                                        <?php if ($esTi && (int) $r['consultas'] > 0): ?> · consultada <?= e($r['consultas']) ?> vez/veces<?php endif; ?></div>
                                </div>
                                <div class="text-end">
                                    <?php if ((int) $r['tiene_llave'] === 1): ?>
                                        <?php if ($revelado === (int) $r['id'] && $llave !== null): ?>
                                            <div class="font-monospace fs-6 fw-bold bitlocker-llave user-select-all"><?= e($llave) ?></div>
                                            <div class="small text-body-secondary">Visible solo en esta pantalla. Al recargar se oculta.</div>
                                        <?php else: ?>
                                            <div class="font-monospace text-body-secondary">••••••-••••••-••••••-…</div>
                                            <button class="btn btn-sm btn-outline-primary mt-1" type="button" data-bs-toggle="collapse" data-bs-target="#ver<?= e($r['id']) ?>">Mostrar llave</button>
                                        <?php endif; ?>
                                    <?php else: ?>
                                        <span class="small text-body-secondary">Sin llave registrada</span>
                                    <?php endif; ?>
                                </div>
                            </div>
                            <?php if ((int) $r['tiene_llave'] === 1 && $revelado !== (int) $r['id']): ?>
                                <form method="post" action="<?= e(url('/bitlocker/' . (int) $r['id'] . '/mostrar')) ?>" class="collapse mt-2" id="ver<?= e($r['id']) ?>" autocomplete="off">
                                    <?= csrf_field() ?>
                                    <div class="row g-2">
                                        <div class="col-sm-6"><input type="text" name="motivo" class="form-control form-control-sm" required maxlength="255" placeholder="¿Para qué? (ej. ticket TK-2026-483920)" aria-label="Motivo"></div>
                                        <div class="col-sm-4"><input type="password" name="password" class="form-control form-control-sm" required placeholder="Tu contraseña" autocomplete="current-password" aria-label="Tu contraseña"></div>
                                        <div class="col-sm-2 d-grid"><button class="btn btn-sm btn-primary" type="submit">Ver</button></div>
                                    </div>
                                </form>
                            <?php endif; ?>
                        </li>
                    <?php endforeach; ?>
                </ul>
            <?php endif; ?>
        </div>

        <?php if ($esTi && $bitacora !== []): ?>
            <div class="card border-0 shadow-sm mt-4">
                <div class="card-header bg-white fw-semibold"><i class="bi bi-journal-text me-1"></i>Bitácora de consultas</div>
                <ul class="list-group list-group-flush small">
                    <?php foreach ($bitacora as $c): ?>
                        <li class="list-group-item d-flex justify-content-between gap-2">
                            <span><?= e($c['nombre'] ?? '—') ?> <span class="text-body-secondary">(<?= e($c['ficha'] ?? '') ?>) · <?= e($c['unidad']) ?> · <?= e($c['motivo']) ?></span></span>
                            <span class="text-body-secondary text-nowrap"><?= e(fecha_mx((string) $c['consultado_en'])) ?></span>
                        </li>
                    <?php endforeach; ?>
                </ul>
            </div>
        <?php endif; ?>
    </div>

    <div class="col-lg-5">
        <form method="post" action="<?= e(url('/equipos/' . (int) $equipo['id'] . '/bitlocker')) ?>" class="card border-0 shadow-sm" autocomplete="off">
            <?= csrf_field() ?>
            <div class="card-header bg-white fw-semibold"><i class="bi bi-plus-lg me-1"></i>Registrar</div>
            <div class="card-body">
                <div class="mb-2">
                    <label class="form-label small" for="unidad">Unidad</label>
                    <select class="form-select" id="unidad" name="unidad">
                        <?php foreach ($unidades as $u): ?><option value="<?= e($u) ?>" <?= ($old['unidad'] ?? 'C:') === $u ? 'selected' : '' ?>><?= e($u) ?></option><?php endforeach; ?>
                    </select>
                </div>
                <div class="mb-2">
                    <label class="form-label small" for="identificador">Identificador de la llave <span class="text-danger">*</span></label>
                    <input class="form-control font-monospace text-uppercase" id="identificador" name="identificador" required maxlength="40" value="<?= e($old['identificador'] ?? '') ?>" placeholder="1A2B3C4D o 1A2B3C4D-5E6F-7A8B-9C0D-1E2F3A4B5C6D">
                    <div class="form-text">Aparece en la pantalla de recuperación o en el respaldo de la llave.</div>
                </div>
                <div class="mb-2">
                    <label class="form-label small" for="llave">Llave de recuperación <span class="text-body-secondary">(opcional, 48 dígitos)</span></label>
                    <input class="form-control font-monospace" id="llave" name="llave" maxlength="60" inputmode="numeric" placeholder="000000-000000-000000-000000-000000-000000-000000-000000" autocomplete="off">
                    <div class="form-text">Se guarda cifrada. Puedes dejarla vacía y registrar solo el identificador.</div>
                </div>
                <div>
                    <label class="form-label small" for="nota">Nota <span class="text-body-secondary">(opcional)</span></label>
                    <input class="form-control" id="nota" name="nota" maxlength="255" value="<?= e($old['nota'] ?? '') ?>" placeholder="Ej. llave también en AD/Intune">
                </div>
            </div>
            <div class="card-footer bg-white text-end"><button class="btn btn-primary" type="submit"><i class="bi bi-check2 me-1"></i>Guardar</button></div>
        </form>
    </div>
</div>
