<?php
/**
 * @var list<array<string, mixed>> $equipos
 * @var string $q
 * @var string $filtro
 * @var int $page
 * @var int $pages
 * @var int $total
 */
$filtros = [
    'vigentes'     => 'Vigentes en GECA',
    'discrepancia' => 'Ubicación real ≠ GECA',
    'sin_garantia' => 'Sin garantía activa',
    'no_vigentes'  => 'No vigentes',
    'todos'        => 'Todos',
];
?>
<div class="d-flex flex-wrap justify-content-between align-items-center gap-2 mb-3">
    <h1 class="h4 mb-0"><i class="bi bi-pc-display me-1"></i>Inventario</h1>
    <span class="small text-body-secondary"><?= e(number_format($total)) ?> equipo(s)</span>
</div>

<form method="get" action="<?= e(url('/ti/equipos')) ?>" class="card border-0 shadow-sm mb-3" role="search">
    <div class="card-body row g-2 align-items-end">
        <div class="col-md-7">
            <label class="form-label small mb-1" for="q">Buscar</label>
            <input type="search" class="form-control" id="q" name="q" value="<?= e($q) ?>" placeholder="Serie (CPU o monitor), ficha, nombre, folio de resguardo, inmueble">
        </div>
        <div class="col-8 col-md-3">
            <label class="form-label small mb-1" for="filtro">Mostrar</label>
            <select class="form-select" id="filtro" name="filtro">
                <?php foreach ($filtros as $k => $label): ?>
                    <option value="<?= e($k) ?>" <?= $filtro === $k ? 'selected' : '' ?>><?= e($label) ?></option>
                <?php endforeach; ?>
            </select>
        </div>
        <div class="col-4 col-md-2 d-grid">
            <button class="btn btn-primary" type="submit"><i class="bi bi-search me-1"></i>Buscar</button>
        </div>
    </div>
</form>

<?php if ($equipos === []): ?>
    <div class="card border-0 shadow-sm"><div class="card-body text-center py-5 text-body-secondary">No se encontraron equipos.</div></div>
<?php else: ?>
    <div class="card border-0 shadow-sm">
        <div class="table-responsive">
            <table class="table table-hover align-middle mb-0">
                <thead class="table-light">
                <tr><th>Serie</th><th>Equipo</th><th>Usuario</th><th>Inmueble (GECA)</th><th class="text-center">Garantía</th><th class="text-end">Tickets</th></tr>
                </thead>
                <tbody>
                <?php foreach ($equipos as $eq):
                    $discrepancia = $eq['ubicacion_real_id'] !== null && $eq['ubicacion_geca_id'] !== null && (int) $eq['ubicacion_real_id'] !== (int) $eq['ubicacion_geca_id'];
                    ?>
                    <tr>
                        <td>
                            <a href="<?= e(url('/ti/equipos/' . (int) $eq['id'])) ?>" class="font-monospace fw-semibold"><?= e($eq['serie']) ?></a>
                            <?php if ((int) $eq['vigente_en_geca'] === 0): ?><span class="badge text-bg-secondary ms-1">No vigente</span><?php endif; ?>
                        </td>
                        <td class="small"><?= e(trim(($eq['marca'] ?? '') . ' ' . ($eq['modelo'] ?? '')) ?: '—') ?>
                            <?php $mon = array_filter([$eq['serie_monitor1'], $eq['serie_monitor2']]); if ($mon !== []): ?>
                                <div class="text-body-secondary"><i class="bi bi-display me-1"></i><?= e(implode(', ', $mon)) ?></div>
                            <?php endif; ?>
                        </td>
                        <td class="small"><?= e($eq['usuario_nombre'] ?? '—') ?><div class="text-body-secondary font-monospace"><?= e($eq['ficha'] ?? '') ?></div></td>
                        <td class="small"><?= e($eq['inmueble_geca'] ?? '—') ?>
                            <?php if ($discrepancia): ?><div class="text-warning-emphasis"><i class="bi bi-geo-alt me-1"></i>Ubicación real distinta</div><?php endif; ?>
                        </td>
                        <td class="text-center">
                            <?php if ((int) $eq['garantias_activas'] > 0): ?>
                                <i class="bi bi-shield-check text-success" title="Con garantía activa"></i><span class="visually-hidden">Sí</span>
                            <?php else: ?>
                                <span class="text-body-tertiary" title="Sin garantía activa">—</span>
                            <?php endif; ?>
                        </td>
                        <td class="text-end"><?= e($eq['tickets_total']) ?></td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </div>
    <?php if ($pages > 1): ?>
        <nav class="mt-3" aria-label="Paginación">
            <ul class="pagination pagination-sm justify-content-center flex-wrap">
                <?php for ($i = max(1, $page - 5); $i <= min($pages, $page + 5); $i++): ?>
                    <li class="page-item<?= $i === $page ? ' active' : '' ?>"><a class="page-link" href="<?= e(query_url('/ti/equipos', ['p' => $i])) ?>"><?= e($i) ?></a></li>
                <?php endfor; ?>
            </ul>
        </nav>
    <?php endif; ?>
<?php endif; ?>
