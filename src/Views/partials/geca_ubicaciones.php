<?php
/**
 * Equipos de una instalación según SOLO el GECA (inicio del supervisor).
 *
 * @var list<array{id: int, nombre: string}> $instalaciones supervisadas
 * @var int $sel instalación elegida
 * @var array{total: int, inmuebles: array<string, int>, tipos: array<string, int>,
 *            filas: list<array{inmueble: string, referencia: string, total: int, tipos: array<string, int>}>,
 *            con_equivalencia: bool} $datos
 */
use App\Core\Grafica;

$nombre = array_column($instalaciones, 'nombre', 'id')[$sel] ?? '';
$tipoEtq = static fn (string $t): string => $t === 'SIN TIPO' ? 'Sin tipo' : tipo_equipo($t);
$variosInmuebles = count($datos['inmuebles']) > 1;
$sinRef = array_sum(array_map(static fn (array $f): int => $f['referencia'] === 'Sin referencia' ? $f['total'] : 0, $datos['filas']));

// Referencias: las 12 con más equipos y el resto agrupado.
$refs = $datos['filas'];
usort($refs, static fn (array $a, array $b): int => $b['total'] <=> $a['total']);
$top = array_slice($refs, 0, 12);
$resto = array_slice($refs, 12);
$barrasRef = array_map(static fn (array $f): array => [$f['referencia'] . ($variosInmuebles ? ' · ' . $f['inmueble'] : ''), $f['total']], $top);
if ($resto !== []) {
    $barrasRef[] = ['Otras ' . count($resto) . ' referencias', array_sum(array_column($resto, 'total'))];
}
// Tabla: hasta 6 tipos como columnas; el resto en "Otros".
$colTipos = array_map('strval', array_slice(array_keys($datos['tipos']), 0, 6));
?>
<div class="d-flex flex-wrap justify-content-between align-items-end gap-2 mb-3" id="geca-ubicaciones">
    <div>
        <h2 class="h6 fw-semibold text-body-secondary text-uppercase small mb-1">Equipos según el GECA<?= count($instalaciones) === 1 ? ' · ' . e($nombre) : '' ?></h2>
        <div class="small text-body-secondary">Solo datos del GECA: inmueble y referencia de ubicación tal como vienen en la carga semanal (equipos vigentes; monitores casados incluidos).</div>
    </div>
    <div class="d-flex gap-2 align-items-center">
        <?php if (count($instalaciones) > 1): ?>
            <form method="get" action="<?= e(url('/')) ?>" class="m-0">
                <select name="geca" class="form-select form-select-sm" aria-label="Instalación" data-autoenviar>
                    <?php foreach ($instalaciones as $i): ?><option value="<?= e($i['id']) ?>" <?= (int) $i['id'] === $sel ? 'selected' : '' ?>><?= e($i['nombre']) ?></option><?php endforeach; ?>
                </select>
                <noscript><button class="btn btn-sm btn-primary">Ver</button></noscript>
            </form>
        <?php endif; ?>
        <?php if ($datos['total'] > 0): ?>
            <a class="btn btn-sm btn-outline-primary text-nowrap" href="<?= e(url('/supervisor/geca-ubicaciones?inst=' . $sel)) ?>"><i class="bi bi-download me-1"></i>CSV</a>
        <?php endif; ?>
    </div>
</div>

<?php if (!$datos['con_equivalencia']): ?>
    <div class="alert alert-info small mb-4">
        <i class="bi bi-info-circle me-1"></i><?= e($nombre) ?> aún no tiene inmuebles del GECA relacionados. Relaciónalos en
        <a href="<?= e(url('/supervisor/ubicaciones?instalacion=' . $sel . '#inmuebles')) ?>">Supervisión › Ubicaciones</a> para ver aquí sus equipos.
    </div>
<?php elseif ($datos['total'] === 0): ?>
    <div class="alert alert-light border small mb-4">Los inmuebles relacionados no tienen equipos vigentes en el GECA.</div>
<?php else: ?>
    <div class="row g-3 mb-3">
        <?php foreach ([
            [number_format($datos['total']), 'Equipos en el GECA', 'bi-pc-display'],
            [number_format(count($datos['inmuebles'])), 'Inmuebles', 'bi-buildings'],
            [number_format(count($datos['filas'])), 'Referencias de ubicación', 'bi-signpost-split'],
            [number_format($sinRef), 'Equipos sin referencia', 'bi-question-circle'],
        ] as [$valor, $etq, $ico]): ?>
            <div class="col-6 col-lg-3">
                <div class="card h-100 border-0 shadow-sm stat-card"><div class="card-body">
                    <i class="bi <?= e($ico) ?> text-primary fs-4"></i>
                    <div class="fs-3 fw-semibold mt-1"><?= e($valor) ?></div>
                    <div class="small text-body-secondary"><?= e($etq) ?></div>
                </div></div>
            </div>
        <?php endforeach; ?>
    </div>
    <div class="row g-3 mb-3">
        <div class="col-lg-6">
            <div class="card border-0 shadow-sm h-100">
                <div class="card-header bg-white fw-semibold"><i class="bi bi-buildings me-1"></i>Por inmueble</div>
                <div class="card-body"><?= Grafica::barras(array_map(static fn (int|string $k, int $n): array => [(string) $k, $n], array_keys($datos['inmuebles']), array_values($datos['inmuebles'])), 'equipos') ?></div>
            </div>
        </div>
        <div class="col-lg-6">
            <div class="card border-0 shadow-sm h-100">
                <div class="card-header bg-white fw-semibold"><i class="bi bi-pc-display me-1"></i>Por tipo de equipo</div>
                <div class="card-body"><?= Grafica::barras(array_map(static fn (int|string $t, int $n): array => [$tipoEtq((string) $t), $n], array_keys($datos['tipos']), array_values($datos['tipos'])), 'equipos', Grafica::SERIE_2) ?></div>
            </div>
        </div>
    </div>
    <div class="row g-3 mb-3">
        <div class="col-12">
            <div class="card border-0 shadow-sm h-100">
                <div class="card-header bg-white fw-semibold"><i class="bi bi-signpost-split me-1"></i>Por referencia de ubicación <span class="fw-normal text-body-secondary small">(las 12 con más equipos)</span></div>
                <div class="card-body"><?= Grafica::barras($barrasRef, 'equipos') ?></div>
            </div>
        </div>
    </div>
    <div class="card border-0 shadow-sm mb-4">
        <div class="card-header bg-white fw-semibold"><i class="bi bi-table me-1"></i>Detalle: inmueble › referencia › tipo</div>
        <div class="table-responsive" style="max-height: 26rem;">
            <table class="table table-sm table-hover align-middle small mb-0">
                <thead class="table-light" style="position: sticky; top: 0;">
                    <tr>
                        <th>Inmueble</th><th>Referencia (GECA)</th>
                        <?php foreach ($colTipos as $t): ?><th class="text-end"><?= e($tipoEtq($t)) ?></th><?php endforeach; ?>
                        <?php if (count($datos['tipos']) > count($colTipos)): ?><th class="text-end">Otros</th><?php endif; ?>
                        <th class="text-end">Total</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($datos['filas'] as $f): $otros = $f['total'] - array_sum(array_map(static fn (string $t): int => $f['tipos'][$t] ?? 0, $colTipos)); ?>
                        <tr>
                            <td><?= e($f['inmueble']) ?></td>
                            <td class="<?= $f['referencia'] === 'Sin referencia' ? 'text-body-secondary fst-italic' : '' ?>"><?= e($f['referencia']) ?></td>
                            <?php foreach ($colTipos as $t): ?><td class="text-end"><?= e(number_format($f['tipos'][$t] ?? 0)) ?></td><?php endforeach; ?>
                            <?php if (count($datos['tipos']) > count($colTipos)): ?><td class="text-end"><?= e(number_format($otros)) ?></td><?php endif; ?>
                            <td class="text-end fw-semibold"><?= e(number_format($f['total'])) ?></td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </div>
<?php endif; ?>
