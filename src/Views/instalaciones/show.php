<?php
/**
 * @var array<string, mixed> $instalacion
 * @var list<array<string, mixed>> $supervisores
 * @var list<array<string, mixed>> $agentes
 * @var list<array<string, mixed>> $ubicaciones
 * @var list<array<string, mixed>> $candidatos
 */
$i = $instalacion;
$alas = \App\Services\UbicacionFisicaService::ALAS;
$vigencia = static function (array $a): string {
    if ($a['desde'] === null && $a['hasta'] === null) {
        return 'Permanente';
    }
    return ($a['desde'] ? 'Desde ' . fecha_mx($a['desde'], false) : '') . ($a['hasta'] ? ' hasta ' . fecha_mx($a['hasta'], false) : '');
};
?>
<div class="d-flex flex-wrap justify-content-between align-items-start gap-2 mb-3">
    <div>
        <div class="small text-body-secondary">Instalación</div>
        <h1 class="h4 mb-0"><?= e($i['nombre']) ?></h1>
    </div>
    <a href="<?= e(url('/ti/instalaciones')) ?>" class="btn btn-link"><i class="bi bi-arrow-left me-1"></i>Instalaciones</a>
</div>

<div class="row g-4">
    <div class="col-lg-6">
        <div class="card border-0 shadow-sm mb-4">
            <div class="card-header bg-white fw-semibold"><i class="bi bi-person-badge me-1"></i>Supervisores</div>
            <?php if ($supervisores === []): ?>
                <div class="card-body small text-warning-emphasis"><i class="bi bi-exclamation-triangle me-1"></i>Sin supervisor: los avisos de tickets nuevos solo irán a la mesa de ayuda institucional.</div>
            <?php else: ?>
                <ul class="list-group list-group-flush">
                    <?php foreach ($supervisores as $s): ?>
                        <li class="list-group-item d-flex justify-content-between align-items-center gap-2">
                            <div>
                                <div class="fw-semibold"><?= e($s['nombre']) ?> <span class="badge <?= $s['tipo'] === 'titular' ? 'text-bg-primary' : 'text-bg-info' ?>"><?= e($s['tipo'] === 'titular' ? 'Titular' : 'Suplencia') ?></span></div>
                                <div class="small text-body-secondary"><?= e($vigencia($s)) ?></div>
                            </div>
                            <form method="post" action="<?= e(url('/ti/instalaciones/asignacion/' . (int) $s['id'] . '/quitar')) ?>" class="m-0" data-confirm="¿Terminar la asignación de <?= e($s['nombre']) ?>?">
                                <?= csrf_field() ?>
                                <button class="btn btn-sm btn-link text-danger" type="submit">Quitar</button>
                            </form>
                        </li>
                    <?php endforeach; ?>
                </ul>
            <?php endif; ?>
            <form method="post" action="<?= e(url('/ti/instalaciones/' . (int) $i['id'] . '/supervisores')) ?>" class="card-footer bg-white">
                <?= csrf_field() ?>
                <div class="row g-2">
                    <div class="col-12">
                        <select name="supervisor_id" class="form-select form-select-sm" required aria-label="Supervisor">
                            <option value="">Selecciona un usuario con rol Supervisor…</option>
                            <?php foreach ($candidatos as $c): ?><option value="<?= e($c['id']) ?>"><?= e($c['nombre']) ?> (<?= e($c['ficha']) ?>)</option><?php endforeach; ?>
                        </select>
                        <?php if ($candidatos === []): ?><div class="form-text">Primero cambia el rol de la persona a Supervisor en Gestión › Usuarios.</div><?php endif; ?>
                    </div>
                    <div class="col-4">
                        <select name="tipo" class="form-select form-select-sm" aria-label="Tipo"><option value="titular">Titular</option><option value="suplencia">Suplencia</option></select>
                    </div>
                    <div class="col-4"><input type="date" name="desde" class="form-control form-control-sm" aria-label="Desde"></div>
                    <div class="col-4"><input type="date" name="hasta" class="form-control form-control-sm" aria-label="Hasta"></div>
                    <div class="col-12 d-flex justify-content-between align-items-center">
                        <span class="form-text m-0">La suplencia requiere fecha “hasta”.</span>
                        <button class="btn btn-sm btn-primary" type="submit">Asignar</button>
                    </div>
                </div>
            </form>
        </div>

        <div class="card border-0 shadow-sm mb-4">
            <div class="card-header bg-white fw-semibold"><i class="bi bi-people me-1"></i>Agentes</div>
            <?php if ($agentes === []): ?>
                <div class="card-body small text-body-secondary">Los supervisores asignan a sus agentes desde Supervisión › Agentes.</div>
            <?php else: ?>
                <ul class="list-group list-group-flush small">
                    <?php foreach ($agentes as $a): ?>
                        <li class="list-group-item d-flex justify-content-between">
                            <span><?= e($a['nombre']) ?> <?= $a['tipo'] === 'prestamo' ? '<span class="badge text-bg-info">Préstamo</span>' : '' ?></span>
                            <span class="text-body-secondary"><?= e($vigencia($a)) ?></span>
                        </li>
                    <?php endforeach; ?>
                </ul>
            <?php endif; ?>
        </div>
    </div>

    <div class="col-lg-6">
        <div class="card border-0 shadow-sm mb-4">
            <div class="card-header bg-white fw-semibold">Datos</div>
            <form method="post" action="<?= e(url('/ti/instalaciones/' . (int) $i['id'])) ?>" class="card-body row g-2">
                <?= csrf_field() ?>
                <div class="col-sm-8"><input type="text" name="nombre" class="form-control" value="<?= e($i['nombre']) ?>" maxlength="150" required aria-label="Nombre"></div>
                <div class="col-sm-4">
                    <select name="activo" class="form-select" aria-label="Estado">
                        <option value="1" <?= (int) $i['activo'] === 1 ? 'selected' : '' ?>>Activa</option>
                        <option value="0" <?= (int) $i['activo'] === 0 ? 'selected' : '' ?>>Inactiva</option>
                    </select>
                </div>
                <div class="col-12 text-end"><button class="btn btn-sm btn-primary" type="submit">Guardar</button></div>
            </form>
        </div>

        <div class="card border-0 shadow-sm">
            <div class="card-header bg-white d-flex justify-content-between align-items-center">
                <span class="fw-semibold"><i class="bi bi-geo-alt me-1"></i>Ubicaciones (<?= e(count($ubicaciones)) ?>)</span>
                <a class="btn btn-sm btn-outline-primary" href="<?= e(url('/supervisor/ubicaciones?instalacion=' . (int) $i['id'])) ?>">Administrar</a>
            </div>
            <?php if ($ubicaciones === []): ?>
                <div class="card-body small text-body-secondary">El supervisor da de alta edificios, naves, talleres y áreas.</div>
            <?php else: ?>
                <ul class="list-group list-group-flush small">
                    <?php foreach ($ubicaciones as $u): ?>
                        <li class="list-group-item">
                            <span class="fw-semibold"><?= e($u['nombre']) ?></span>
                            <span class="text-body-secondary">· <?= e($u['tipo'] ?? 'Sin tipo') ?> · Niveles: <?= e($u['niveles'] !== [] ? implode(', ', $u['niveles']) : 'Único') ?> · Alas: <?= e($u['alas'] !== [] ? implode(', ', array_map(static fn (string $a): string => $alas[$a], $u['alas'])) : 'N/A') ?></span>
                        </li>
                    <?php endforeach; ?>
                </ul>
            <?php endif; ?>
        </div>
    </div>
</div>
