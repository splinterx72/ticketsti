<?php
/** @var list<array<string, mixed>> $garantias */
$elementos = \App\Services\GarantiaService::ELEMENTOS;
?>
<div class="d-flex justify-content-between align-items-center mb-3">
    <h1 class="h4 mb-0"><i class="bi bi-shield-check me-1"></i>Mis garantías</h1>
</div>
<p class="text-body-secondary small">Historial de las garantías de los equipos que tienes en resguardo. Las registra y actualiza el personal de TI; si algo no coincide, levanta un ticket.</p>
<?php if ($garantias === []): ?>
    <div class="card border-0 shadow-sm"><div class="card-body text-body-secondary">Tus equipos no tienen garantías registradas.</div></div>
<?php else: ?>
    <div class="card border-0 shadow-sm">
        <div class="table-responsive">
            <table class="table align-middle mb-0">
                <thead class="table-light"><tr><th>Equipo</th><th>Cubre</th><th>Proveedor</th><th>Vigencia</th><th>Estado</th></tr></thead>
                <tbody>
                <?php foreach ($garantias as $g): ?>
                    <tr>
                        <td><span class="font-monospace"><?= e($g['serie']) ?></span><div class="small text-body-secondary"><?= e(trim(tipo_equipo($g['tipo_componente'] ?? null) . ' · ' . trim(($g['marca'] ?? '') . ' ' . ($g['modelo'] ?? '')), ' ·')) ?></div></td>
                        <td class="small"><?= e($elementos[$g['elemento_cubierto']] ?? $g['elemento_cubierto']) ?></td>
                        <td class="small"><?= e($g['proveedor']) ?><?php if ($g['numero_poliza_contrato']): ?><div class="text-body-secondary">Póliza <?= e($g['numero_poliza_contrato']) ?></div><?php endif; ?></td>
                        <td class="small text-nowrap"><?= e(fecha_mx($g['fecha_inicio'], false)) ?> – <?= e(fecha_mx($g['fecha_vencimiento'], false)) ?></td>
                        <td><?= garantia_badge((string) $g['estado']) ?></td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </div>
<?php endif; ?>
