<?php
/**
 * @var list<array{id: int, nombre: string}> $instalaciones
 * @var list<array<string, mixed>> $semaforo
 * @var list<array<string, mixed>> $porInstalacion
 * @var list<array<string, mixed>> $tareas
 * @var array<string, mixed> $panel
 * @var bool $esCoordinador
 * @var array<int, array{equipos: int, con_garantia: int, discrepancias: int, sin_trabajador: int}> $equipos
 * @var array<int, list<array<string, mixed>>> $tecnicos
 */
$luz = [
    'verde'    => ['success', 'bi-check-circle-fill', 'Al día'],
    'amarillo' => ['warning', 'bi-exclamation-triangle-fill', 'Por vencer'],
    'rojo'     => ['danger', 'bi-x-octagon-fill', 'Bloqueado'],
];
$conteo = ['verde' => 0, 'amarillo' => 0, 'rojo' => 0];
foreach ($semaforo as $s) {
    $conteo[$s['color']]++;
}
?>
<div class="d-flex flex-wrap justify-content-between align-items-center gap-2 mb-3">
    <h1 class="h4 mb-0"><i class="bi bi-person-badge me-1"></i><?= $esCoordinador ? 'Mi zona' : 'Supervisión' ?></h1>
    <div class="d-flex flex-wrap gap-2">
        <a class="btn btn-primary btn-sm" href="<?= e(url('/tareas/nueva')) ?>"><i class="bi bi-plus-lg me-1"></i>Nueva tarea</a>
        <a class="btn btn-outline-primary btn-sm" href="<?= e(url('/supervisor/avisos/nuevo')) ?>"><i class="bi bi-megaphone me-1"></i>Publicar aviso</a>
        <a class="btn btn-outline-primary btn-sm" href="<?= e(url('/supervisor/ubicaciones')) ?>"><i class="bi bi-geo-alt me-1"></i>Ubicaciones</a>
        <a class="btn btn-outline-primary btn-sm" href="<?= e(url('/supervisor/agentes')) ?>"><i class="bi bi-people me-1"></i>Agentes</a>
        <?php if ($esCoordinador): ?><a class="btn btn-outline-primary btn-sm" href="<?= e(url('/supervisor/supervisores')) ?>"><i class="bi bi-diagram-3 me-1"></i>Supervisores</a><?php endif; ?>
    </div>
</div>

<?php if ($instalaciones === []): ?>
    <div class="alert alert-info">Aún no tienes instalaciones a tu cargo. <?= $esCoordinador ? 'El Admin TI te asigna zonas en Gestión › Zonas.' : 'El Admin TI o tu coordinador te las asignan.' ?></div>
<?php else:
    $k = $panel['kpi'];
    $tipos = \App\Services\Catalog::TIPOS;
    $kpis1 = [
        ['abiertos', 'Tickets abiertos', '/ti/tickets?vista=abiertos', false],
        ['sin_asignar', 'Sin asignar', '/ti/tickets?vista=sin_asignar', $k['sin_asignar'] > 0],
        ['vencidos', 'Fuera de tiempo', '/ti/tickets?vista=sla', $k['vencidos'] > 0],
        ['cerrados_7d', 'Cerrados (7 días)', '/ti/tickets?vista=cerrados', false],
    ];
    $kpis2 = [
        ['equipos', 'Equipos', '/ti/equipos?inst=mias'],
        ['con_garantia', 'Con garantía', '/ti/equipos?inst=mias'],
        ['discrepancias', 'Fuera de su ubicación GECA', '/ti/equipos?inst=mias&filtro=discrepancia'],
        ['sin_trabajador', 'Sin trabajador (baja)', '/ti/equipos?inst=mias'],
        ['tecnicos', 'Técnicos', '/supervisor/agentes'],
    ];
    ?>
<div class="row g-3 mb-3">
    <?php foreach ($kpis1 as [$key, $label, $link, $alerta]): ?>
        <div class="col-6 col-md-4 col-xl-2"><a class="kpi <?= $alerta ? 'kpi-alerta' : '' ?>" href="<?= e(url($link)) ?>"><div class="kpi-valor"><?= e(number_format($k[$key])) ?></div><div class="kpi-etq"><?= e($label) ?></div></a></div>
    <?php endforeach; ?>
    <div class="col-6 col-md-4 col-xl-2"><div class="kpi"><div class="kpi-valor"><?= $k['horas_promedio'] === null ? '—' : e($k['horas_promedio']) . ' h' ?></div><div class="kpi-etq">Resolución promedio (30 días)</div></div></div>
    <div class="col-6 col-md-4 col-xl-2"><div class="kpi <?= $k['cumplimiento'] !== null && $k['cumplimiento'] < 80 ? 'kpi-alerta' : '' ?>"><div class="kpi-valor"><?= $k['cumplimiento'] === null ? '—' : e($k['cumplimiento']) . ' %' ?></div><div class="kpi-etq">Resueltos a tiempo (30 días)</div></div></div>
</div>
<div class="row g-3 mb-4">
    <?php foreach ($kpis2 as [$key, $label, $link]): ?>
        <div class="col-6 col-md-4 col-xl"><a class="kpi" href="<?= e(url($link)) ?>"><div class="kpi-valor"><?= e(number_format($k[$key])) ?></div><div class="kpi-etq"><?= e($label) ?></div></a></div>
    <?php endforeach; ?>
</div>

<div class="row g-4 mb-4">
    <div class="col-lg-8">
        <div class="card border-0 shadow-sm h-100">
            <div class="card-header bg-white fw-semibold"><i class="bi bi-graph-up me-1"></i>Tickets creados y cerrados por día (30 días)</div>
            <div class="card-body">
                <?= \App\Core\Grafica::lineas($panel['serie']['dias'], [
                    ['Creados', \App\Core\Grafica::SERIE_1, $panel['serie']['creados']],
                    ['Cerrados', \App\Core\Grafica::SERIE_2, $panel['serie']['cerrados']],
                ], 'Tickets creados y cerrados por día en los últimos 30 días') ?>
            </div>
        </div>
    </div>
    <div class="col-lg-4">
        <div class="card border-0 shadow-sm mb-4">
            <div class="card-header bg-white fw-semibold"><i class="bi bi-stopwatch me-1"></i>Tiempos de atención de los abiertos</div>
            <div class="card-body">
                <?= \App\Core\Grafica::segmentada([
                    ['En tiempo', $panel['sla']['en_tiempo'], \App\Core\Grafica::BUENO, 'bi-clock'],
                    ['Por vencer', $panel['sla']['por_vencer'], \App\Core\Grafica::ALERTA, 'bi-hourglass-split'],
                    ['Vencidos', $panel['sla']['vencido'], \App\Core\Grafica::CRITICO, 'bi-alarm'],
                ]) ?>
            </div>
        </div>
        <div class="card border-0 shadow-sm">
            <div class="card-header bg-white fw-semibold"><i class="bi bi-tags me-1"></i>Abiertos por tipo</div>
            <div class="card-body">
                <?= \App\Core\Grafica::barras(array_map(
                    static fn (string $t, int $n): array => [$tipos[$t] ?? $t, $n, url('/ti/tickets?vista=abiertos&tipo=' . rawurlencode($t))],
                    array_keys($panel['por_tipo']), array_values($panel['por_tipo'])
                ), 'tickets') ?>
            </div>
        </div>
    </div>
</div>

<div class="row g-4 mb-4">
    <div class="col-lg-6">
        <div class="card border-0 shadow-sm h-100">
            <div class="card-header bg-white fw-semibold"><i class="bi bi-buildings me-1"></i>Tickets abiertos por instalación</div>
            <div class="card-body">
                <?= \App\Core\Grafica::barras(array_map(
                    static fn (array $i): array => [$i['nombre'], $i['abiertos'], url('/ti/tickets?vista=abiertos&inst=' . (int) $i['id'])],
                    $panel['por_instalacion']
                ), 'tickets abiertos') ?>
            </div>
        </div>
    </div>
    <div class="col-lg-6">
        <div class="card border-0 shadow-sm h-100">
            <div class="card-header bg-white fw-semibold"><i class="bi bi-geo-alt me-1"></i>Tickets abiertos por edificio o área</div>
            <div class="card-body">
                <?= \App\Core\Grafica::barras(array_map(
                    static fn (array $l): array => [$l['ubicacion'] . ' · ' . $l['instalacion'], $l['abiertos'],
                        url('/ti/tickets?vista=abiertos&inst=' . (int) $l['instalacion_id'] . ($l['ubicacion_id'] !== null ? '&ubic=' . (int) $l['ubicacion_id'] : ''))],
                    $panel['por_lugar']
                ), 'tickets abiertos') ?>
            </div>
        </div>
    </div>
</div>

<div class="row g-4 mb-4">
    <div class="col-12 order-2">
        <div class="card border-0 shadow-sm h-100">
            <div class="card-header bg-white fw-semibold"><i class="bi bi-pc-display me-1"></i>Equipos por tipo</div>
            <div class="card-body">
                <?= \App\Core\Grafica::barras(array_map(
                    static fn (string $t, int $n): array => [$t === 'SIN TIPO' ? 'Sin tipo' : tipo_equipo($t), $n],
                    array_keys($panel['equipos_tipo']), array_values($panel['equipos_tipo'])
                ), 'equipos') ?>
            </div>
        </div>
    </div>
    <div class="col-12 order-1">
        <div class="card border-0 shadow-sm h-100">
            <div class="card-header bg-white fw-semibold"><i class="bi bi-table me-1"></i><?= $esCoordinador ? 'Por zona e instalación' : 'Por instalación' ?></div>
            <div class="table-responsive">
                <table class="table table-sm align-middle mb-0 small">
                    <thead class="table-light"><tr>
                        <th>Instalación</th><th class="text-end">Abiertos</th><th class="text-end">Sin asignar</th><th class="text-end">Fuera de tiempo</th>
                        <th class="text-end">Cerrados 7 d</th><th class="text-end">Prom. (h)</th><th class="text-end">Equipos</th><th class="text-end">Técnicos</th><th>Supervisor</th>
                    </tr></thead>
                    <tbody>
                    <?php $zonaActual = false; foreach ($panel['por_instalacion'] as $i): ?>
                        <?php if ($esCoordinador && $zonaActual !== $i['zona']): $zonaActual = $i['zona']; ?>
                            <tr class="table-light"><td colspan="9" class="fw-semibold"><i class="bi bi-map me-1"></i><?= e($i['zona'] ?? 'Sin zona') ?></td></tr>
                        <?php endif; ?>
                        <tr>
                            <td><a href="<?= e(url('/ti/tickets?vista=abiertos&inst=' . (int) $i['id'])) ?>"><?= e($i['nombre']) ?></a></td>
                            <td class="text-end"><?= e($i['abiertos']) ?></td>
                            <td class="text-end <?= $i['sin_asignar'] > 0 ? 'text-danger-emphasis fw-semibold' : '' ?>"><?= e($i['sin_asignar']) ?></td>
                            <td class="text-end"><?= $i['vencidos'] > 0 ? '<span class="sla-badge sla-vencido"><i class="bi bi-alarm"></i> ' . e($i['vencidos']) . '</span>' : '0' ?></td>
                            <td class="text-end"><?= e($i['cerrados_7d']) ?></td>
                            <td class="text-end"><?= e($i['horas'] ?? '—') ?></td>
                            <td class="text-end"><a href="<?= e(url('/ti/equipos?inst=' . (int) $i['id'])) ?>"><?= e(number_format($i['equipos'])) ?></a></td>
                            <td class="text-end"><?= e($i['tecnicos']) ?></td>
                            <td><?= $i['supervisores'] ? e($i['supervisores']) : '<span class="text-danger-emphasis"><i class="bi bi-exclamation-triangle me-1"></i>Sin supervisor</span>' ?></td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>
</div>

<div class="row g-4">
    <div class="col-lg-6">
        <div class="card border-0 shadow-sm h-100">
            <div class="card-header bg-white d-flex flex-wrap justify-content-between align-items-center gap-2">
                <span class="fw-semibold"><i class="bi bi-shield-lock me-1"></i>Contraseña de admin local</span>
                <span class="small d-flex gap-2">
                    <?php foreach ($luz as $k => [$color, $icon, $label]): ?>
                        <span class="text-<?= e($color) ?>-emphasis"><i class="bi <?= e($icon) ?>"></i> <?= e($conteo[$k]) ?> <?= e(mb_strtolower($label)) ?></span>
                    <?php endforeach; ?>
                </span>
            </div>
            <?php if ($semaforo === []): ?>
                <div class="card-body small text-body-secondary">Asigna agentes a tus instalaciones para ver su estado.</div>
            <?php else: ?>
                <ul class="list-group list-group-flush">
                    <?php foreach ($semaforo as $s): [$color, $icon, $label] = $luz[$s['color']]; ?>
                        <li class="list-group-item d-flex justify-content-between align-items-center gap-2">
                            <span>
                                <i class="bi <?= e($icon) ?> text-<?= e($color) ?> me-1" aria-hidden="true"></i>
                                <?= e($s['nombre']) ?> <?= $s['prestado'] ? '<span class="badge text-bg-info">Prestado</span>' : '' ?>
                            </span>
                            <span class="small text-end">
                                <span class="badge text-bg-<?= e($color) ?>"><?= e($label) ?></span>
                                <span class="text-body-secondary d-block">
                                    <?php if ($s['dias'] === null): ?>Sin fecha registrada
                                    <?php elseif ($s['dias'] < 0): ?>Venció hace <?= e(abs($s['dias'])) ?> días
                                    <?php else: ?>Vence <?= e(fecha_mx($s['vence'], false)) ?> (<?= e($s['dias']) ?> días)<?php endif; ?>
                                </span>
                            </span>
                        </li>
                    <?php endforeach; ?>
                </ul>
            <?php endif; ?>
        </div>
    </div>
    <div class="col-lg-6">
        <div class="card border-0 shadow-sm h-100">
            <div class="card-header bg-white d-flex justify-content-between align-items-center">
                <span class="fw-semibold"><i class="bi bi-list-task me-1"></i>Tareas abiertas</span>
                <a class="small" href="<?= e(url('/tareas')) ?>">Ver todas</a>
            </div>
            <?php if ($tareas === []): ?>
                <div class="card-body small text-body-secondary">No hay tareas pendientes.</div>
            <?php else: ?>
                <div class="list-group list-group-flush">
                    <?php foreach ($tareas as $t): ?>
                        <a class="list-group-item list-group-item-action" href="<?= e(url('/tareas/' . (int) $t['id'])) ?>">
                            <div class="d-flex justify-content-between gap-2">
                                <span class="text-truncate"><span class="small font-monospace text-body-secondary"><?= e($t['folio']) ?></span> <?= e($t['titulo']) ?></span>
                                <span class="small text-nowrap">
                                    <?= e($t['entregadas']) ?>/<?= e($t['total']) ?>
                                    <?php if ((int) $t['por_revisar'] > 0): ?><span class="badge text-bg-info ms-1"><?= e($t['por_revisar']) ?> por revisar</span><?php endif; ?>
                                </span>
                            </div>
                            <div class="small text-body-secondary">Entrega <?= e(fecha_mx($t['entrega_en'])) ?></div>
                        </a>
                    <?php endforeach; ?>
                </div>
            <?php endif; ?>
        </div>
    </div>
</div>
<?php endif; ?>
