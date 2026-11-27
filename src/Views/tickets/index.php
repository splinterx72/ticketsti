<?php
/**
 * @var list<array<string, mixed>> $tickets
 * @var string $filtro
 * @var int $page
 * @var int $pages
 * @var int $total
 */
$tabs = ['abiertos' => 'Abiertos', 'cerrados' => 'Cerrados', 'todos' => 'Todos'];
?>
<div class="d-flex flex-wrap justify-content-between align-items-center gap-2 mb-3">
    <h1 class="h4 mb-0"><i class="bi bi-ticket-detailed me-1"></i>Mis tickets</h1>
    <a href="<?= e(url('/tickets/nuevo')) ?>" class="btn btn-primary"><i class="bi bi-plus-lg me-1"></i>Nuevo ticket</a>
</div>

<ul class="nav nav-pills mb-3">
    <?php foreach ($tabs as $key => $label): ?>
        <li class="nav-item">
            <a class="nav-link<?= $filtro === $key ? ' active' : '' ?>" href="<?= e(url('/tickets') . '?estado=' . $key) ?>"><?= e($label) ?></a>
        </li>
    <?php endforeach; ?>
</ul>

<?php if ($tickets === []): ?>
    <div class="card border-0 shadow-sm">
        <div class="card-body text-center py-5">
            <i class="bi bi-inbox display-6 text-body-tertiary"></i>
            <p class="mt-3 mb-1 fw-semibold">
                <?= $filtro === 'abiertos' ? 'No tienes tickets abiertos.' : 'No hay tickets en esta vista.' ?>
            </p>
            <p class="text-body-secondary small">¿Algo no funciona? Levanta un ticket y TI lo atenderá.</p>
            <a href="<?= e(url('/tickets/nuevo')) ?>" class="btn btn-outline-primary btn-sm">Crear ticket</a>
        </div>
    </div>
<?php else: ?>
    <div class="list-group shadow-sm ticket-list">
        <?php foreach ($tickets as $t): ?>
            <a href="<?= e(url('/tickets/' . (int) $t['id'])) ?>" class="list-group-item list-group-item-action py-3">
                <div class="d-flex flex-wrap justify-content-between gap-2">
                    <div class="me-auto">
                        <div class="small text-body-secondary font-monospace"><?= e($t['folio']) ?> · <?= e(tipo_label($t['tipo_ticket'])) ?></div>
                        <div class="fw-semibold"><?= e($t['titulo']) ?></div>
                        <?php if ((int) $t['usuario_solicitante_id'] !== (int) $authUser['id']): ?>
                            <span class="badge rounded-pill text-bg-light border"><i class="bi bi-person-badge me-1"></i>A nombre de <?= e($t['solicitante_nombre']) ?></span>
                        <?php endif; ?>
                        <div class="small text-body-secondary">
                            <?php if ($t['serie'] !== null): ?>
                                <i class="bi bi-pc-display me-1"></i><?= e($t['serie']) ?> · <?= e(componente_label($t['componente_afectado'])) ?> ·
                            <?php endif; ?>
                            <?= $t['agente_nombre'] !== null ? 'Atiende: ' . e($t['agente_nombre']) : 'Sin asignar' ?>
                        </div>
                    </div>
                    <div class="text-end">
                        <div class="d-flex gap-1 justify-content-end flex-wrap">
                            <?= estado_badge($t['estado_nombre'], $t['estado_color']) ?>
                            <?= prioridad_badge($t['prioridad']) ?>
                        </div>
                        <div class="small text-body-secondary mt-1" title="<?= e(fecha_mx($t['actualizado_en'])) ?>">
                            Actualizado <?= e(hace($t['actualizado_en'])) ?>
                        </div>
                    </div>
                </div>
            </a>
        <?php endforeach; ?>
    </div>

    <?php if ($pages > 1): ?>
        <nav class="mt-3" aria-label="Paginación">
            <ul class="pagination pagination-sm justify-content-center">
                <?php for ($i = 1; $i <= $pages; $i++): ?>
                    <li class="page-item<?= $i === $page ? ' active' : '' ?>">
                        <a class="page-link" href="<?= e(query_url('/tickets', ['p' => $i])) ?>"><?= e($i) ?></a>
                    </li>
                <?php endfor; ?>
            </ul>
        </nav>
    <?php endif; ?>
    <p class="small text-body-secondary text-center"><?= e($total) ?> ticket(s)</p>
<?php endif; ?>
