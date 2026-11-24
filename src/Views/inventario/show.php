<?php
/**
 * @var array<string, mixed> $equipo
 * @var list<array<string, mixed>> $garantias
 * @var list<array<string, mixed>> $tickets
 */
use App\Services\GarantiaService;

$eq = $equipo;
$discrepancia = $eq['discrepancia'];
$dl = static function (array $pairs): string {
    $out = '';
    foreach ($pairs as $k => $v) {
        $out .= '<dt>' . e($k) . '</dt><dd>' . ($v === null || $v === '' ? '<span class="text-body-tertiary">—</span>' : e($v)) . '</dd>';
    }
    return $out;
};
?>
<div class="d-flex flex-wrap justify-content-between align-items-start gap-2 mb-3">
    <div>
        <div class="small text-body-secondary">Equipo</div>
        <h1 class="h4 mb-1 font-monospace"><?= e($eq['serie']) ?></h1>
        <div class="d-flex flex-wrap gap-1">
            <?= (int) $eq['vigente_en_geca'] === 1 ? '<span class="badge text-bg-success">Vigente en GECA</span>' : '<span class="badge text-bg-secondary">No vigente en GECA</span>' ?>
            <?php if ((int) $eq['es_principal'] === 1): ?><span class="badge text-bg-primary">Equipo principal</span><?php endif; ?>
            <?php if ($discrepancia): ?><span class="badge text-bg-warning"><i class="bi bi-geo-alt me-1"></i>Ubicación real ≠ GECA</span><?php endif; ?>
        </div>
    </div>
    <a href="<?= e(url('/ti/equipos')) ?>" class="btn btn-link"><i class="bi bi-arrow-left me-1"></i>Inventario</a>
</div>

<div class="row g-4">
    <div class="col-lg-4">
        <div class="card border-0 shadow-sm mb-4">
            <div class="card-header bg-white fw-semibold"><i class="bi bi-person me-1"></i>Resguardante</div>
            <dl class="card-body small mb-0 detail-list">
                <?= $dl([
                    'Nombre' => $eq['usuario_nombre'], 'Ficha' => $eq['ficha'],
                    'Jefe' => $eq['jefe_nombre'] ? $eq['jefe_nombre'] . ($eq['jefe_ficha'] ? ' (ficha ' . $eq['jefe_ficha'] . ')' : '') : null,
                    'Correo' => $eq['email'], 'Teléfono / ext.' => $eq['telefono_ext'],
                    'Cuenta' => $eq['ficha'] === null ? null : ((int) $eq['registrado'] === 1 ? 'Activada' : 'Sin activar'),
                ]) ?>
            </dl>
            <?php if (!empty($eq['usuario_id'])): ?>
                <div class="card-footer bg-white small"><a href="<?= e(url('/ti/usuarios/' . (int) $eq['usuario_id'])) ?>"><i class="bi bi-person-vcard me-1"></i>Ver ficha y todos sus equipos</a></div>
            <?php endif; ?>
        </div>
        <div class="card border-0 shadow-sm mb-4">
            <div class="card-header bg-white fw-semibold"><i class="bi bi-geo-alt me-1"></i>Ubicación</div>
            <dl class="card-body small mb-0 detail-list">
                <?= $dl([
                    'Según GECA' => $eq['ubicacion_geca'],
                    'Referencia GECA' => $eq['ubicacion_referencia'],
                    'Entidad / municipio' => trim(implode(' / ', array_filter([$eq['entidad_federativa'], $eq['municipio']]))) ?: null,
                    'Física (último reporte)' => $eq['ubicacion_real'],
                ]) ?>
            </dl>
        </div>
    </div>

    <div class="col-lg-8">
        <div class="card border-0 shadow-sm mb-4">
            <div class="card-header bg-white fw-semibold"><i class="bi bi-file-earmark-text me-1"></i>Datos del resguardo (GECA)</div>
            <div class="card-body">
                <div class="row">
                    <dl class="col-sm-6 small mb-0 detail-list">
                        <?= $dl([
                            'Tipo' => $eq['tipo_componente'] ? tipo_equipo($eq['tipo_componente']) : null,
                            'Marca / modelo' => trim(($eq['marca'] ?? '') . ' ' . ($eq['modelo'] ?? '')), 'Perfil' => $eq['perfil'],
                            'Tipo de asignación' => $eq['tipo_asignacion'], 'Lote' => $eq['lote'], 'Orden' => $eq['numero_orden'],
                            'Folio de resguardo' => $eq['folio_resguardo'], 'Fecha de resguardo' => $eq['fecha_resguardo'] ? fecha_mx($eq['fecha_resguardo'], false) : null,
                            'Estado del resguardo' => $eq['estado_resguardo'], 'Contrato' => $eq['numero_contrato'],
                        ]) ?>
                    </dl>
                    <dl class="col-sm-6 small mb-0 detail-list">
                        <?= $dl([
                            'Estado de inventario' => $eq['estado_inventario'], 'Fuera de consola' => $eq['fuera_consola'],
                            'Aceptación del usuario' => $eq['usuario_fecha_aceptacion'] ? fecha_mx($eq['usuario_fecha_aceptacion']) : null,
                            'Validación TI' => $eq['ti_fecha_validacion'] ? fecha_mx($eq['ti_fecha_validacion']) : null,
                            'Baja' => $eq['fecha_baja'] ? fecha_mx($eq['fecha_baja']) . ($eq['motivo_baja'] ? ' — ' . $eq['motivo_baja'] : '') : null,
                            'Última carga GECA' => $eq['ultima_carga_fecha'] ? fecha_mx($eq['ultima_carga_fecha']) : null,
                        ]) ?>
                    </dl>
                </div>
                <?php if ($eq['pr_folio'] || $eq['pr_ficha'] || $eq['pr_nombre']): ?>
                    <hr>
                    <div class="small fw-semibold mb-1"><i class="bi bi-clock-history me-1"></i>Resguardo anterior</div>
                    <dl class="small mb-0 detail-list">
                        <?= $dl([
                            'Resguardante' => trim(($eq['pr_nombre'] ?? '') . ($eq['pr_ficha'] ? ' (ficha ' . $eq['pr_ficha'] . ')' : '')) ?: null,
                            'Folio' => $eq['pr_folio'], 'Fecha' => $eq['pr_fecha'] ? fecha_mx($eq['pr_fecha']) : null,
                            'Estado' => $eq['pr_estado_resguardo'],
                            'EPS / zona' => trim(implode(' / ', array_filter([$eq['pr_eps'], $eq['pr_zona']]))) ?: null,
                        ]) ?>
                    </dl>
                <?php endif; ?>
                <?php if ($eq['serie_monitor1'] || $eq['serie_monitor2']): ?>
                    <hr>
                    <div class="row small">
                        <?php foreach ([1, 2] as $n): if (!$eq['serie_monitor' . $n]) { continue; } ?>
                            <div class="col-sm-6">
                                <span class="text-body-secondary">Monitor <?= e($n) ?>:</span>
                                <span class="font-monospace"><?= e($eq['serie_monitor' . $n]) ?></span>
                                <?= e(trim(($eq['marca_monitor' . $n] ?? '') . ' ' . ($eq['modelo_monitor' . $n] ?? ''))) ?>
                            </div>
                        <?php endforeach; ?>
                    </div>
                <?php endif; ?>
            </div>
        </div>

        <div class="card border-0 shadow-sm mb-4">
            <div class="card-header bg-white d-flex justify-content-between align-items-center">
                <span class="fw-semibold"><i class="bi bi-shield-check me-1"></i>Garantías</span>
                <a href="<?= e(url('/ti/garantias/nueva?equipo=' . (int) $eq['id'])) ?>" class="btn btn-sm btn-outline-primary"><i class="bi bi-plus-lg me-1"></i>Registrar</a>
            </div>
            <?php if ($garantias === []): ?>
                <div class="card-body small text-body-secondary">Sin garantías registradas.</div>
            <?php else: ?>
                <ul class="list-group list-group-flush">
                    <?php foreach ($garantias as $g): $dias = GarantiaService::diasRestantes($g['fecha_vencimiento']); ?>
                        <li class="list-group-item d-flex justify-content-between gap-2 align-items-start">
                            <div class="small">
                                <div class="fw-semibold"><?= e(GarantiaService::ELEMENTOS[$g['elemento_cubierto']]) ?> · <?= e($g['proveedor']) ?></div>
                                <div class="text-body-secondary">
                                    Póliza <?= e($g['numero_poliza_contrato'] ?? '—') ?> ·
                                    <?= e(fecha_mx($g['fecha_inicio'], false)) ?> – <?= e(fecha_mx($g['fecha_vencimiento'], false)) ?>
                                    <?= $dias >= 0 ? '· quedan ' . e($dias) . ' días' : '' ?>
                                </div>
                                <?php if ($g['notas']): ?><div class="text-body-secondary fst-italic"><?= texto_multilinea($g['notas']) ?></div><?php endif; ?>
                            </div>
                            <div class="text-end flex-shrink-0">
                                <?= garantia_badge($g['estado']) ?>
                                <a href="<?= e(url('/ti/garantias/' . (int) $g['id'] . '/editar')) ?>" class="btn btn-sm btn-link px-1" title="Editar"><i class="bi bi-pencil"></i><span class="visually-hidden">Editar</span></a>
                            </div>
                        </li>
                    <?php endforeach; ?>
                </ul>
            <?php endif; ?>
        </div>

        <div class="card border-0 shadow-sm">
            <div class="card-header bg-white fw-semibold"><i class="bi bi-ticket-detailed me-1"></i>Historial de tickets (<?= e(count($tickets)) ?>)</div>
            <?php if ($tickets === []): ?>
                <div class="card-body small text-body-secondary">Este equipo no tiene tickets.</div>
            <?php else: ?>
                <div class="list-group list-group-flush">
                    <?php foreach ($tickets as $t): ?>
                        <a href="<?= e(url('/tickets/' . (int) $t['id'])) ?>" class="list-group-item list-group-item-action">
                            <div class="d-flex justify-content-between gap-2">
                                <div class="small">
                                    <span class="font-monospace text-body-secondary"><?= e($t['folio']) ?></span> · <?= e(componente_label($t['componente_afectado'])) ?>
                                    <div class="text-body fw-medium"><?= e($t['titulo']) ?></div>
                                    <div class="text-body-secondary"><?= e($t['solicitante_nombre']) ?> · <?= e(fecha_mx($t['creado_en'], false)) ?></div>
                                </div>
                                <div class="flex-shrink-0"><?= estado_badge($t['estado_nombre'], $t['estado_color']) ?></div>
                            </div>
                        </a>
                    <?php endforeach; ?>
                </div>
            <?php endif; ?>
        </div>
    </div>
</div>
