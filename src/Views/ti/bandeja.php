<?php
/**
 * @var list<array<string, mixed>> $tickets
 * @var array<string, mixed> $filtros
 * @var array<string, int> $resumen
 * @var list<array<string, mixed>> $estados
 * @var list<array<string, mixed>> $departamentos
 * @var int $page
 * @var int $pages
 * @var int $total
 */
$vistas = [
    'abiertos'      => ['Abiertos', $resumen['abiertos'] ?? 0, 'bi-inbox'],
    'mios'          => ['Asignados a mí', $resumen['mios'] ?? 0, 'bi-person-check'],
    'sin_asignar'   => ['Sin asignar', $resumen['sin_asignar'] ?? 0, 'bi-person-dash'],
    'discrepancias' => ['Discrepancias', $resumen['discrepancias'] ?? 0, 'bi-geo-alt'],
    'cerrados'      => ['Cerrados', null, 'bi-archive'],
    'todos'         => ['Todos', null, 'bi-list'],
];
?>
<div class="d-flex flex-wrap justify-content-between align-items-center gap-2 mb-3">
    <h1 class="h4 mb-0"><i class="bi bi-kanban me-1"></i>Bandeja de TI</h1>
    <?php if (($resumen['urgentes'] ?? 0) > 0): ?>
        <span class="badge text-bg-danger fs-6"><i class="bi bi-exclamation-triangle me-1"></i><?= e($resumen['urgentes']) ?> urgente(s) abiertos</span>
    <?php endif; ?>
</div>

<ul class="nav nav-pills flex-nowrap overflow-auto mb-3 pb-1">
    <?php foreach ($vistas as $key => [$label, $count, $icon]): ?>
        <li class="nav-item">
            <a class="nav-link text-nowrap<?= $filtros['vista'] === $key ? ' active' : '' ?>"
               href="<?= e(query_url('/ti/tickets', ['vista' => $key, 'p' => null])) ?>">
                <i class="bi <?= e($icon) ?> me-1"></i><?= e($label) ?>
                <?php if ($count !== null): ?><span class="badge rounded-pill <?= $filtros['vista'] === $key ? 'text-bg-light' : 'text-bg-secondary' ?> ms-1"><?= e($count) ?></span><?php endif; ?>
            </a>
        </li>
    <?php endforeach; ?>
</ul>

<form method="get" action="<?= e(url('/ti/tickets')) ?>" class="card border-0 shadow-sm mb-3">
    <input type="hidden" name="vista" value="<?= e($filtros['vista']) ?>">
    <div class="card-body row g-2 align-items-end">
        <div class="col-12 col-lg-3">
            <label class="form-label small mb-1" for="q">Buscar</label>
            <input type="search" class="form-control form-control-sm" id="q" name="q" value="<?= e($filtros['q']) ?>"
                   placeholder="Folio, título, ficha, nombre, serie, hostname">
        </div>
        <div class="col-6 col-md-3 col-lg-2">
            <label class="form-label small mb-1" for="estado">Estado</label>
            <select class="form-select form-select-sm" id="estado" name="estado">
                <option value="0">Todos</option>
                <?php foreach ($estados as $es): ?>
                    <option value="<?= e($es['id']) ?>" <?= $es['id'] === $filtros['estado'] ? 'selected' : '' ?>><?= e($es['nombre']) ?></option>
                <?php endforeach; ?>
            </select>
        </div>
        <div class="col-6 col-md-3 col-lg-2">
            <label class="form-label small mb-1" for="depto">Departamento</label>
            <select class="form-select form-select-sm" id="depto" name="depto">
                <option value="0">Todos</option>
                <?php foreach ($departamentos as $d): ?>
                    <option value="<?= e($d['id']) ?>" <?= $d['id'] === $filtros['depto'] ? 'selected' : '' ?>><?= e($d['nombre']) ?></option>
                <?php endforeach; ?>
            </select>
        </div>
        <div class="col-6 col-md-3 col-lg-2">
            <label class="form-label small mb-1" for="tipo">Tipo</label>
            <select class="form-select form-select-sm" id="tipo" name="tipo">
                <option value="">Todos</option>
                <?php foreach (\App\Services\Catalog::TIPOS as $k => $label): ?>
                    <option value="<?= e($k) ?>" <?= $k === $filtros['tipo'] ? 'selected' : '' ?>><?= e(tipo_label($k)) ?></option>
                <?php endforeach; ?>
            </select>
        </div>
        <div class="col-6 col-md-3 col-lg-2">
            <label class="form-label small mb-1" for="prioridad">Prioridad</label>
            <select class="form-select form-select-sm" id="prioridad" name="prioridad">
                <option value="">Todas</option>
                <?php foreach (\App\Services\Catalog::PRIORIDADES as $k => $label): ?>
                    <option value="<?= e($k) ?>" <?= $k === $filtros['prioridad'] ? 'selected' : '' ?>><?= e($label) ?></option>
                <?php endforeach; ?>
            </select>
        </div>
        <div class="col-12 col-lg-1 d-grid">
            <button class="btn btn-sm btn-primary" type="submit"><i class="bi bi-funnel me-1"></i>Filtrar</button>
        </div>
    </div>
</form>

<?php if ($tickets === []): ?>
    <div class="card border-0 shadow-sm">
        <div class="card-body text-center py-5 text-body-secondary">
            <i class="bi bi-check2-circle display-6"></i>
            <p class="mt-3 mb-0">No hay tickets con estos filtros.</p>
        </div>
    </div>
<?php else: ?>
    <div class="card border-0 shadow-sm">
        <div class="table-responsive">
            <table class="table table-hover align-middle mb-0 inbox-table">
                <thead class="table-light">
                <tr>
                    <th>Folio</th><th>Asunto</th><th>Solicitante</th><th>Estado</th><th>Prioridad</th><th>Atiende</th><th>Actualizado</th><th></th>
                </tr>
                </thead>
                <tbody>
                <?php foreach ($tickets as $t): ?>
                    <tr>
                        <td class="font-monospace small"><a href="<?= e(url('/tickets/' . (int) $t['id'])) ?>" class="stretched-link-cell"><?= e($t['folio']) ?></a></td>
                        <td class="subject-cell">
                            <a href="<?= e(url('/tickets/' . (int) $t['id'])) ?>" class="fw-semibold text-body text-decoration-none d-block text-truncate"><?= e($t['titulo']) ?></a>
                            <div class="small text-body-secondary text-truncate">
                                <?= e(tipo_label($t['tipo_ticket'])) ?> · <?= e($t['departamento_nombre']) ?>
                                <?php if ($t['serie'] !== null): ?> · <span class="font-monospace"><?= e($t['serie']) ?></span><?php endif; ?>
                                <?php if ((int) $t['discrepancia_ubicacion'] === 1): ?> · <span class="text-warning-emphasis"><i class="bi bi-geo-alt"></i> discrepancia</span><?php endif; ?>
                            </div>
                        </td>
                        <td class="small"><?= e($t['solicitante_nombre']) ?><div class="text-body-secondary font-monospace"><?= e($t['solicitante_ficha']) ?></div></td>
                        <td><?= estado_badge($t['estado_nombre'], $t['estado_color']) ?></td>
                        <td><?= prioridad_badge($t['prioridad']) ?></td>
                        <td class="small"><?= e($t['agente_nombre'] ?? '—') ?></td>
                        <td class="small text-body-secondary" title="<?= e(fecha_mx($t['actualizado_en'])) ?>"><?= e(hace($t['actualizado_en'])) ?></td>
                        <td class="text-end">
                            <?php if ($t['agente_asignado_id'] === null && (int) $t['es_cierre'] === 0): ?>
                                <form method="post" action="<?= e(url('/tickets/' . (int) $t['id'] . '/tomar')) ?>" class="m-0">
                                    <?= csrf_field() ?>
                                    <input type="hidden" name="volver" value="bandeja">
                                    <button class="btn btn-sm btn-outline-primary text-nowrap" type="submit">Tomar</button>
                                </form>
                            <?php endif; ?>
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
                    <li class="page-item<?= $i === $page ? ' active' : '' ?>">
                        <a class="page-link" href="<?= e(query_url('/ti/tickets', ['p' => $i])) ?>"><?= e($i) ?></a>
                    </li>
                <?php endfor; ?>
            </ul>
        </nav>
    <?php endif; ?>
    <p class="small text-body-secondary text-center mt-2"><?= e($total) ?> ticket(s)</p>
<?php endif; ?>
