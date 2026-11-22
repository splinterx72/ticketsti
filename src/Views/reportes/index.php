<?php
/**
 * @var string $desde
 * @var string $hasta
 * @var array<string, mixed> $kpis
 * @var list<array{etiqueta: string, total: int}> $porTipo
 * @var list<array{etiqueta: string, total: int}> $porDepto
 * @var list<array{etiqueta: string, total: int}> $porComponente
 * @var list<array{etiqueta: string, total: int}> $porPrioridad
 * @var list<array<string, mixed>> $estadoActual
 * @var list<array<string, mixed>> $agentes
 * @var list<array<string, mixed>> $topEquipos
 * @var array<string, int> $garantias
 */
$duracion = static function (?float $min): string {
    if ($min === null) {
        return '—';
    }
    if ($min < 60) {
        return round($min) . ' min';
    }
    if ($min < 1440) {
        return number_format($min / 60, 1) . ' h';
    }
    return number_format($min / 1440, 1) . ' días';
};

/**
 * Barras horizontales de una sola serie: el título nombra la medida, cada fila
 * lleva su etiqueta y su valor en texto (funciona también como tabla).
 *
 * @param list<array{etiqueta: string, total: int}> $rows
 */
$barras = static function (string $titulo, array $rows) : string {
    $max = max(1, ...array_map(static fn (array $r): int => (int) $r['total'], $rows ?: [['total' => 0]]));
    $sum = array_sum(array_map(static fn (array $r): int => (int) $r['total'], $rows));
    $html = '<div class="card border-0 shadow-sm h-100"><div class="card-header bg-white fw-semibold">' . e($titulo) . '</div><div class="card-body">';
    if ($rows === [] || $sum === 0) {
        return $html . '<p class="small text-body-secondary mb-0">Sin datos en el periodo.</p></div></div>';
    }
    $html .= '<table class="table table-sm table-borderless align-middle mb-0 bar-table"><tbody>';
    foreach ($rows as $r) {
        $pct = $r['total'] / $max * 100;
        $share = $sum > 0 ? round($r['total'] / $sum * 100) : 0;
        $tip = e($r['etiqueta'] . ': ' . $r['total'] . ' (' . $share . '%)');
        $html .= '<tr title="' . $tip . '"><th scope="row" class="fw-normal small">' . e($r['etiqueta']) . '</th>'
            . '<td class="bar-cell"><div class="bar-track"><div class="bar-fill" style="width:' . number_format($pct, 2, '.', '') . '%"></div></div></td>'
            . '<td class="text-end small fw-semibold text-nowrap">' . e(number_format($r['total'])) . ' <span class="text-body-secondary fw-normal">' . e($share) . '%</span></td></tr>';
    }
    return $html . '</tbody></table></div></div>';
};
$rangos = [
    '7 días'  => [date('Y-m-d', strtotime('-6 days')), date('Y-m-d')],
    '30 días' => [date('Y-m-d', strtotime('-29 days')), date('Y-m-d')],
    'Este mes' => [date('Y-m-01'), date('Y-m-d')],
    'Este año' => [date('Y-01-01'), date('Y-m-d')],
];
?>
<div class="d-flex flex-wrap justify-content-between align-items-center gap-2 mb-3">
    <h1 class="h4 mb-0"><i class="bi bi-bar-chart me-1"></i>Reportes</h1>
    <a href="<?= e(url('/ti/reportes/exportar') . '?desde=' . $desde . '&hasta=' . $hasta) ?>" class="btn btn-outline-primary">
        <i class="bi bi-filetype-csv me-1"></i>Exportar tickets (CSV)
    </a>
</div>

<form method="get" action="<?= e(url('/ti/reportes')) ?>" class="card border-0 shadow-sm mb-4">
    <div class="card-body d-flex flex-wrap align-items-end gap-2">
        <div>
            <label for="desde" class="form-label small mb-1">Desde</label>
            <input type="date" class="form-control form-control-sm" id="desde" name="desde" value="<?= e($desde) ?>" max="<?= e(date('Y-m-d')) ?>">
        </div>
        <div>
            <label for="hasta" class="form-label small mb-1">Hasta</label>
            <input type="date" class="form-control form-control-sm" id="hasta" name="hasta" value="<?= e($hasta) ?>" max="<?= e(date('Y-m-d')) ?>">
        </div>
        <button type="submit" class="btn btn-sm btn-primary">Aplicar</button>
        <div class="btn-group btn-group-sm ms-md-auto flex-wrap" role="group" aria-label="Periodos rápidos">
            <?php foreach ($rangos as $label => [$d, $h]): ?>
                <a class="btn btn-outline-secondary<?= $d === $desde && $h === $hasta ? ' active' : '' ?>" href="<?= e(url('/ti/reportes') . '?desde=' . $d . '&hasta=' . $h) ?>"><?= e($label) ?></a>
            <?php endforeach; ?>
        </div>
    </div>
</form>

<div class="row g-3 mb-4">
    <?php
    $tiles = [
        ['Tickets creados', number_format($kpis['creados']), 'en el periodo', 'bi-plus-circle', 'primary'],
        ['Tickets cerrados', number_format($kpis['cerrados']), 'en el periodo', 'bi-check2-circle', 'success'],
        ['Tiempo medio de solución', $duracion($kpis['min_resolucion']), 'de los cerrados', 'bi-stopwatch', 'info'],
        ['Abiertos ahora', number_format($kpis['abiertos']), 'en toda la bandeja', 'bi-inbox', 'warning'],
        ['Con discrepancia', number_format($kpis['discrepancias']), 'de ubicación GECA', 'bi-geo-alt', 'danger'],
    ];
    foreach ($tiles as [$label, $value, $hint, $icon, $color]): ?>
        <div class="col-6 col-md-4 col-xl">
            <div class="card h-100 border-0 shadow-sm">
                <div class="card-body">
                    <div class="small text-body-secondary"><i class="bi <?= e($icon) ?> text-<?= e($color) ?> me-1"></i><?= e($label) ?></div>
                    <div class="fs-3 fw-semibold"><?= e($value) ?></div>
                    <div class="small text-body-secondary"><?= e($hint) ?></div>
                </div>
            </div>
        </div>
    <?php endforeach; ?>
</div>

<div class="row g-4 mb-4">
    <div class="col-md-6 col-xl-3"><?= $barras('Por tipo', $porTipo) ?></div>
    <div class="col-md-6 col-xl-3"><?= $barras('Por departamento', $porDepto) ?></div>
    <div class="col-md-6 col-xl-3"><?= $barras('Por componente', $porComponente) ?></div>
    <div class="col-md-6 col-xl-3"><?= $barras('Por prioridad', $porPrioridad) ?></div>
</div>

<div class="row g-4 mb-4">
    <div class="col-lg-8">
        <div class="card border-0 shadow-sm h-100">
            <div class="card-header bg-white fw-semibold"><i class="bi bi-people me-1"></i>Tablero de agentes</div>
            <div class="table-responsive">
                <table class="table table-hover align-middle mb-0">
                    <thead class="table-light">
                    <tr>
                        <th>Agente</th>
                        <th class="text-end">Abiertos asignados</th>
                        <th class="text-end">Cerrados</th>
                        <th class="text-end">Tiempo medio</th>
                        <th class="text-end">Respuestas</th>
                    </tr>
                    </thead>
                    <tbody>
                    <?php foreach ($agentes as $a): ?>
                        <tr>
                            <td><?= e($a['nombre']) ?></td>
                            <td class="text-end"><?= e((int) $a['abiertos']) ?></td>
                            <td class="text-end"><?= e((int) $a['cerrados']) ?></td>
                            <td class="text-end"><?= e($duracion($a['min_resolucion'] !== null ? (float) $a['min_resolucion'] : null)) ?></td>
                            <td class="text-end"><?= e((int) $a['respuestas']) ?></td>
                        </tr>
                    <?php endforeach; ?>
                    <?php if ($agentes === []): ?>
                        <tr><td colspan="5" class="text-body-secondary small">No hay agentes activos.</td></tr>
                    <?php endif; ?>
                    </tbody>
                </table>
            </div>
            <div class="card-footer bg-white small text-body-secondary">“Cerrados”, “tiempo medio” y “respuestas” corresponden al periodo; “abiertos” es la carga actual.</div>
        </div>
    </div>
    <div class="col-lg-4">
        <div class="card border-0 shadow-sm h-100">
            <div class="card-header bg-white fw-semibold">Abiertos por estado (ahora)</div>
            <ul class="list-group list-group-flush">
                <?php foreach ($estadoActual as $es): ?>
                    <li class="list-group-item d-flex justify-content-between align-items-center">
                        <?= estado_badge((string) $es['etiqueta'], (string) $es['color_hex']) ?>
                        <span class="fw-semibold"><?= e((int) $es['total']) ?></span>
                    </li>
                <?php endforeach; ?>
            </ul>
        </div>
    </div>
</div>

<div class="row g-4">
    <div class="col-lg-7">
        <div class="card border-0 shadow-sm h-100">
            <div class="card-header bg-white fw-semibold"><i class="bi bi-exclamation-diamond me-1"></i>Equipos con fallas recurrentes</div>
            <?php if ($topEquipos === []): ?>
                <div class="card-body small text-body-secondary">Ningún equipo tuvo más de un ticket en el periodo.</div>
            <?php else: ?>
                <div class="list-group list-group-flush">
                    <?php foreach ($topEquipos as $eq): ?>
                        <a href="<?= e(url('/ti/equipos/' . (int) $eq['id'])) ?>" class="list-group-item list-group-item-action d-flex justify-content-between">
                            <span><span class="font-monospace fw-semibold"><?= e($eq['serie']) ?></span>
                                <span class="small text-body-secondary"><?= e(trim(($eq['marca'] ?? '') . ' ' . ($eq['modelo'] ?? ''))) ?></span></span>
                            <span class="fw-semibold"><?= e((int) $eq['total']) ?> tickets</span>
                        </a>
                    <?php endforeach; ?>
                </div>
            <?php endif; ?>
        </div>
    </div>
    <div class="col-lg-5">
        <div class="card border-0 shadow-sm h-100">
            <div class="card-header bg-white fw-semibold"><i class="bi bi-shield-check me-1"></i>Garantías</div>
            <ul class="list-group list-group-flush">
                <?php foreach ([
                    ['Vigentes', 'vigentes', 'vigentes'],
                    ['Por vencer en ' . \App\Services\GarantiaService::DIAS_POR_VENCER . ' días', 'por_vencer', 'por_vencer'],
                    ['En reclamación', 'en_reclamacion', 'en_reclamacion'],
                    ['Vencidas', 'vencidas', 'vencidas'],
                ] as [$label, $key, $vista]): ?>
                    <a class="list-group-item list-group-item-action d-flex justify-content-between" href="<?= e(url('/ti/garantias') . '?vista=' . $vista) ?>">
                        <span><?= e($label) ?></span><span class="fw-semibold"><?= e($garantias[$key] ?? 0) ?></span>
                    </a>
                <?php endforeach; ?>
            </ul>
        </div>
    </div>
</div>
