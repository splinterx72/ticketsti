<?php
/**
 * Ficha del trabajador (todo TI; acciones de cuenta solo Admin TI).
 *
 * @var array<string, mixed> $f
 * @var array<string, string> $roles
 * @var array<string, string> $tipos
 * @var bool $esAdmin
 * @var array<string, mixed> $authUser
 */
$u = $f['usuario'];
$self = (int) $u['id'] === (int) $authUser['id'];
$estadoCuenta = match (true) {
    (int) $u['activo'] === 0                                  => ['secondary', 'Desactivada'],
    (int) $u['registrado'] === 0 && $u['email'] === null      => ['light', 'Sin activar (solo datos del GECA)'],
    (int) $u['registrado'] === 0                              => ['warning', 'Registro pendiente de verificar correo'],
    $u['email_verificado_en'] === null                        => ['warning', 'Activa · correo sin verificar'],
    default                                                   => ['success', 'Activa'],
};
$vigentes = array_values(array_filter($f['equipos'], static fn (array $e): bool => (int) $e['vigente_en_geca'] === 1));
?>
<div class="d-flex flex-wrap justify-content-between align-items-start gap-2 mb-4">
    <div>
        <div class="small text-body-secondary text-uppercase fw-semibold">Ficha del trabajador</div>
        <h1 class="h3 mb-1"><?= e($u['nombre']) ?></h1>
        <div class="d-flex flex-wrap gap-2 align-items-center">
            <span class="font-monospace fs-6"><?= e($u['ficha']) ?></span>
            <span class="badge text-bg-<?= e($estadoCuenta[0]) ?> <?= $estadoCuenta[0] === 'light' ? 'border' : '' ?>"><?= e($estadoCuenta[1]) ?></span>
            <span class="badge text-bg-primary"><?= e($roles[$u['rol']] ?? $u['rol']) ?></span>
            <?php if ($u['tipo_cuenta'] !== 'geca'): ?><span class="badge text-bg-dark"><?= e($tipos[$u['tipo_cuenta']] ?? $u['tipo_cuenta']) ?></span><?php endif; ?>
        </div>
    </div>
    <a href="<?= e(url($esAdmin ? '/ti/usuarios' : '/ti/equipos')) ?>" class="btn btn-link"><i class="bi bi-arrow-left me-1"></i><?= $esAdmin ? 'Usuarios' : 'Inventario' ?></a>
</div>

<div class="row g-4">
    <div class="col-lg-4">
        <div class="card border-0 shadow-sm mb-4">
            <div class="card-header bg-white fw-semibold"><i class="bi bi-person-vcard me-1"></i>Datos</div>
            <dl class="card-body small mb-0 detail-list">
                <dt>Jefe</dt><dd><?= e($u['jefe_nombre'] ?? '—') ?><?= $u['jefe_ficha'] ? ' <span class="font-monospace text-body-secondary">(' . e($u['jefe_ficha']) . ')</span>' : '' ?></dd>
                <dt>Correo</dt><dd class="text-break"><?= e($u['email'] ?? '—') ?></dd>
                <dt>Teléfono / ext.</dt><dd><?= e($u['telefono_ext'] ?? '—') ?></dd>
            </dl>
        </div>

        <div class="card border-0 shadow-sm mb-4">
            <div class="card-header bg-white fw-semibold"><i class="bi bi-geo-alt me-1"></i>¿Dónde está?</div>
            <dl class="card-body small mb-0 detail-list">
                <dt>Lugar de trabajo (lo indicó él)</dt>
                <dd><?= e($f['ubicacion_fisica'] ?? 'Aún no lo registra') ?><?= !empty($u['habitual_referencia']) ? '<div class="text-body-secondary">' . e($u['habitual_referencia']) . '</div>' : '' ?></dd>
                <?php $g = $f['ubicacion_geca']; ?>
                <dt>Según su resguardo GECA</dt>
                <dd>
                    <?php if ($g === null): ?>Sin equipos en el GECA<?php else: ?>
                        <?= e($g['inmueble']) ?>
                        <?php if ($g['referencia']): ?><div><?= e($g['referencia']) ?></div><?php endif; ?>
                        <div class="text-body-secondary"><?= e(implode(' / ', array_filter([$g['gerencia'], $g['subdireccion']]))) ?></div>
                        <?php if ($g['entidad']): ?><div class="text-body-secondary"><?= e($g['entidad']) ?></div><?php endif; ?>
                    <?php endif; ?>
                </dd>
            </dl>
        </div>

        <?php if ($esAdmin && !$self): ?>
            <div class="card border-0 shadow-sm mb-4">
                <div class="card-header bg-white fw-semibold"><i class="bi bi-sliders me-1"></i>Cuenta (Admin TI)</div>
                <div class="card-body small">
                    <form method="post" action="<?= e(url('/ti/usuarios/' . (int) $u['id'])) ?>" class="d-flex flex-wrap gap-2 align-items-center mb-3">
                        <?= csrf_field() ?>
                        <input type="hidden" name="volver_ficha" value="<?= e($u['id']) ?>">
                        <select name="rol" class="form-select form-select-sm w-auto" aria-label="Rol">
                            <?php foreach ($roles as $k => $label): ?><option value="<?= e($k) ?>" <?= $u['rol'] === $k ? 'selected' : '' ?>><?= e($label) ?></option><?php endforeach; ?>
                        </select>
                        <select name="activo" class="form-select form-select-sm w-auto" aria-label="Estado">
                            <option value="1" <?= (int) $u['activo'] === 1 ? 'selected' : '' ?>>Activo</option>
                            <option value="0" <?= (int) $u['activo'] === 0 ? 'selected' : '' ?>>Desactivado</option>
                        </select>
                        <button type="submit" class="btn btn-sm btn-outline-primary">Guardar</button>
                    </form>
                    <form method="post" action="<?= e(url('/ti/usuarios/' . (int) $u['id'] . '/tipo')) ?>" class="d-flex flex-wrap gap-2 align-items-center mb-2">
                        <?= csrf_field() ?>
                        <label class="text-body-secondary" for="tipo_cuenta">Tipo de cuenta</label>
                        <select name="tipo_cuenta" id="tipo_cuenta" class="form-select form-select-sm w-auto">
                            <?php foreach ($tipos as $k => $label): ?><option value="<?= e($k) ?>" <?= $u['tipo_cuenta'] === $k ? 'selected' : '' ?>><?= e($label) ?></option><?php endforeach; ?>
                        </select>
                        <button type="submit" class="btn btn-sm btn-outline-secondary">Cambiar</button>
                    </form>
                    <div class="form-text mb-3">Administración: sin contraseña de admin local. Prueba: no aparece en reportes.</div>
                    <?php if ((int) $u['registrado'] === 1): ?>
                        <form method="post" action="<?= e(url('/ti/usuarios/' . (int) $u['id'] . '/restablecer')) ?>" class="m-0"
                              data-confirm="¿Generar una contraseña temporal para <?= e($u['nombre']) ?>? La actual dejará de funcionar.">
                            <?= csrf_field() ?>
                            <input type="hidden" name="volver_ficha" value="<?= e($u['id']) ?>">
                            <button type="submit" class="btn btn-sm btn-link px-0"><i class="bi bi-key me-1"></i>Restablecer contraseña</button>
                        </form>
                    <?php endif; ?>
                </div>
            </div>
        <?php endif; ?>
    </div>

    <div class="col-lg-8">
        <div class="card border-0 shadow-sm mb-4">
            <div class="card-header bg-white d-flex justify-content-between align-items-center">
                <span class="fw-semibold"><i class="bi bi-pc-display me-1"></i>Equipos a su cargo</span>
                <span class="small text-body-secondary"><?= e(count($vigentes)) ?> vigentes en el GECA</span>
            </div>
            <?php if ($f['equipos'] === []): ?>
                <div class="card-body small text-body-secondary">No tiene equipos a su nombre en el GECA.</div>
            <?php else: ?>
                <div class="table-responsive">
                    <table class="table table-sm align-middle mb-0">
                        <thead class="table-light"><tr><th>Equipo</th><th>Monitores</th><th>Instalación / ubicación</th><th>Resguardo</th></tr></thead>
                        <tbody>
                        <?php foreach ($f['equipos'] as $eq): ?>
                            <tr class="<?= (int) $eq['vigente_en_geca'] === 0 ? 'opacity-50' : '' ?>">
                                <td>
                                    <a href="<?= e(url('/ti/equipos/' . (int) $eq['id'])) ?>" class="font-monospace fw-semibold"><?= e($eq['serie']) ?></a>
                                    <?php if ((int) $eq['es_principal'] === 1): ?><span class="badge text-bg-primary ms-1">Principal</span><?php endif; ?>
                                    <?php if ((int) $eq['vigente_en_geca'] === 0): ?><span class="badge text-bg-secondary ms-1">No vigente</span><?php endif; ?>
                                    <div class="small text-body-secondary"><?= e(trim(tipo_equipo($eq['tipo_componente']) . ' · ' . trim(($eq['marca'] ?? '') . ' ' . ($eq['modelo'] ?? '')), ' ·')) ?></div>
                                </td>
                                <td class="small font-monospace"><?= e(implode(', ', array_filter([$eq['serie_monitor1'], $eq['serie_monitor2']])) ?: '—') ?></td>
                                <td class="small">
                                    <?= $eq['instalacion'] !== null ? '<span class="fw-semibold">' . e($eq['instalacion']) . '</span>' : '<span class="text-body-secondary">Sin instalación</span>' ?>
                                    <?php if ((int) $eq['discrepancia'] === 1): ?><span class="badge text-bg-warning ms-1" title="La ubicación real no coincide con el GECA"><i class="bi bi-geo-alt"></i> ≠ GECA</span><?php endif; ?>
                                    <div class="text-body-secondary"><?= e($eq['ubicacion_real'] ?? $eq['inmueble'] ?? '') ?></div>
                                </td>
                                <td class="small">
                                    <?= e($eq['estado_resguardo'] ?? '—') ?>
                                    <div class="text-body-secondary font-monospace"><?= e($eq['folio_resguardo'] ?? '') ?></div>
                                    <?php if ((int) $eq['garantias_activas'] > 0): ?><span class="badge text-bg-success"><i class="bi bi-shield-check"></i> Garantía</span><?php endif; ?>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            <?php endif; ?>
        </div>

        <?php if ($f['anteriores'] !== []): ?>
            <div class="card border-0 shadow-sm mb-4">
                <div class="card-header bg-white fw-semibold"><i class="bi bi-clock-history me-1"></i>Equipos que tuvo antes</div>
                <ul class="list-group list-group-flush small">
                    <?php foreach ($f['anteriores'] as $a): ?>
                        <li class="list-group-item d-flex flex-wrap justify-content-between gap-2">
                            <span>
                                <a href="<?= e(url('/ti/equipos/' . (int) $a['id'])) ?>" class="font-monospace"><?= e($a['serie']) ?></a>
                                <span class="text-body-secondary"><?= e(trim(tipo_equipo($a['tipo_componente']) . ' ' . ($a['marca'] ?? '') . ' ' . ($a['modelo'] ?? ''))) ?></span>
                            </span>
                            <span class="text-body-secondary">
                                <?= $a['pr_fecha'] ? e(fecha_mx($a['pr_fecha'], false)) . ' · ' : '' ?><?= e($a['pr_estado_resguardo'] ?? '') ?>
                                · ahora: <?= e($a['actual_nombre'] ?? 'sin resguardante') ?>
                            </span>
                        </li>
                    <?php endforeach; ?>
                </ul>
            </div>
        <?php endif; ?>

        <div class="card border-0 shadow-sm">
            <div class="card-header bg-white fw-semibold"><i class="bi bi-ticket-detailed me-1"></i>Tickets (<?= e(count($f['tickets'])) ?>)</div>
            <?php if ($f['tickets'] === []): ?>
                <div class="card-body small text-body-secondary">Sin tickets en tus instalaciones.</div>
            <?php else: ?>
                <div class="list-group list-group-flush">
                    <?php foreach ($f['tickets'] as $t): ?>
                        <a class="list-group-item list-group-item-action d-flex justify-content-between gap-2" href="<?= e(url('/tickets/' . (int) $t['id'])) ?>">
                            <span class="text-truncate"><span class="font-monospace small text-body-secondary"><?= e($t['folio']) ?></span> <?= e($t['titulo']) ?></span>
                            <span class="small text-nowrap"><span class="badge" style="background-color: <?= e($t['estado_color']) ?>"><?= e($t['estado_nombre']) ?></span> <?= e(fecha_mx($t['creado_en'], false)) ?></span>
                        </a>
                    <?php endforeach; ?>
                </div>
            <?php endif; ?>
        </div>
    </div>
</div>
