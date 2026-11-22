<?php
/**
 * @var list<array<string, mixed>> $garantias
 * @var array<string, int> $resumen
 * @var string $vista
 * @var string $q
 * @var int $page
 * @var int $pages
 * @var int $total
 */
use App\Services\GarantiaService;

$tabs = [
    'vigentes'       => ['Vigentes', 'vigentes', 'bi-shield-check'],
    'por_vencer'     => ['Por vencer (' . GarantiaService::DIAS_POR_VENCER . ' días)', 'por_vencer', 'bi-hourglass-split'],
    'en_reclamacion' => ['En reclamación', 'en_reclamacion', 'bi-tools'],
    'vencidas'       => ['Vencidas', 'vencidas', 'bi-shield-x'],
    'todas'          => ['Todas', 'todas', 'bi-list'],
];
?>
<div class="d-flex flex-wrap justify-content-between align-items-center gap-2 mb-3">
    <h1 class="h4 mb-0"><i class="bi bi-shield-check me-1"></i>Garantías</h1>
    <a href="<?= e(url('/ti/garantias/nueva')) ?>" class="btn btn-primary"><i class="bi bi-plus-lg me-1"></i>Registrar garantía</a>
</div>

<ul class="nav nav-pills flex-nowrap overflow-auto mb-3 pb-1">
    <?php foreach ($tabs as $key => [$label, $countKey, $icon]): ?>
        <li class="nav-item">
            <a class="nav-link text-nowrap<?= $vista === $key ? ' active' : '' ?>" href="<?= e(query_url('/ti/garantias', ['vista' => $key, 'p' => null])) ?>">
                <i class="bi <?= e($icon) ?> me-1"></i><?= e($label) ?>
                <span class="badge rounded-pill <?= $vista === $key ? 'text-bg-light' : 'text-bg-secondary' ?> ms-1"><?= e($resumen[$countKey] ?? 0) ?></span>
            </a>
        </li>
    <?php endforeach; ?>
</ul>

<form method="get" action="<?= e(url('/ti/garantias')) ?>" class="mb-3" role="search">
    <input type="hidden" name="vista" value="<?= e($vista) ?>">
    <div class="input-group">
        <input type="search" class="form-control" name="q" value="<?= e($q) ?>" placeholder="Serie de equipo o monitor, proveedor, póliza, usuario" aria-label="Buscar garantías">
        <button class="btn btn-primary" type="submit"><i class="bi bi-search me-1"></i>Buscar</button>
    </div>
</form>

<?php if ($garantias === []): ?>
    <div class="card border-0 shadow-sm">
        <div class="card-body text-center py-5 text-body-secondary">
            <i class="bi bi-shield display-6"></i>
            <p class="mt-3 mb-0">No hay garantías en esta vista.</p>
        </div>
    </div>
<?php else: ?>
    <div class="card border-0 shadow-sm">
        <div class="table-responsive">
            <table class="table table-hover align-middle mb-0">
                <thead class="table-light">
                <tr><th>Equipo</th><th>Cubre</th><th>Proveedor / póliza</th><th>Vigencia</th><th>Estado</th><th></th></tr>
                </thead>
                <tbody>
                <?php foreach ($garantias as $g):
                    $dias = GarantiaService::diasRestantes($g['fecha_vencimiento']);
                    $serieCubierta = match ($g['elemento_cubierto']) {
                        'monitor_1' => $g['serie_monitor1'],
                        'monitor_2' => $g['serie_monitor2'],
                        default     => $g['serie'],
                    };
                    ?>
                    <tr>
                        <td>
                            <a href="<?= e(url('/ti/equipos/' . (int) $g['equipo_id'])) ?>" class="font-monospace fw-semibold"><?= e($g['serie']) ?></a>
                            <div class="small text-body-secondary"><?= e($g['usuario_nombre'] ?? 'Sin usuario') ?></div>
                        </td>
                        <td class="small">
                            <?= e(GarantiaService::ELEMENTOS[$g['elemento_cubierto']] ?? $g['elemento_cubierto']) ?>
                            <?php if ($g['elemento_cubierto'] !== 'equipo' && $serieCubierta): ?><div class="font-monospace text-body-secondary"><?= e($serieCubierta) ?></div><?php endif; ?>
                        </td>
                        <td class="small"><?= e($g['proveedor']) ?><div class="text-body-secondary"><?= e($g['numero_poliza_contrato'] ?? '—') ?></div></td>
                        <td class="small">
                            <?= e(fecha_mx($g['fecha_inicio'], false)) ?> – <?= e(fecha_mx($g['fecha_vencimiento'], false)) ?>
                            <div class="<?= $dias < 0 ? 'text-body-secondary' : ($dias <= GarantiaService::DIAS_POR_VENCER ? 'text-warning-emphasis fw-semibold' : 'text-body-secondary') ?>">
                                <?= $dias < 0 ? 'Venció hace ' . e(abs($dias)) . ' días' : ($dias === 0 ? 'Vence hoy' : 'Quedan ' . e($dias) . ' días') ?>
                            </div>
                        </td>
                        <td><?= garantia_badge($g['estado']) ?></td>
                        <td class="text-end">
                            <a href="<?= e(url('/ti/garantias/' . (int) $g['id'] . '/editar')) ?>" class="btn btn-sm btn-outline-secondary"><i class="bi bi-pencil"></i><span class="visually-hidden">Editar</span></a>
                        </td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </div>
    <?php if ($pages > 1): ?>
        <nav class="mt-3" aria-label="Paginación">
            <ul class="pagination pagination-sm justify-content-center flex-wrap">
                <?php for ($i = 1; $i <= $pages; $i++): ?>
                    <li class="page-item<?= $i === $page ? ' active' : '' ?>"><a class="page-link" href="<?= e(query_url('/ti/garantias', ['p' => $i])) ?>"><?= e($i) ?></a></li>
                <?php endfor; ?>
            </ul>
        </nav>
    <?php endif; ?>
    <p class="small text-body-secondary text-center mt-2"><?= e($total) ?> garantía(s)</p>
<?php endif; ?>
