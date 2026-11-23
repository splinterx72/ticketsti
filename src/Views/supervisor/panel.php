<?php
/**
 * @var list<array{id: int, nombre: string}> $instalaciones
 * @var list<array{id: int, nombre: string, email: ?string, prestado: bool}> $agentes
 */
?>
<div class="d-flex flex-wrap justify-content-between align-items-center gap-2 mb-3">
    <h1 class="h4 mb-0"><i class="bi bi-person-badge me-1"></i>Supervisión</h1>
    <div class="d-flex gap-2">
        <a class="btn btn-outline-primary btn-sm" href="<?= e(url('/supervisor/ubicaciones')) ?>"><i class="bi bi-geo-alt me-1"></i>Ubicaciones</a>
        <a class="btn btn-outline-primary btn-sm" href="<?= e(url('/supervisor/agentes')) ?>"><i class="bi bi-people me-1"></i>Agentes</a>
    </div>
</div>

<?php if ($instalaciones === []): ?>
    <div class="alert alert-info">Aún no tienes instalaciones a tu cargo. El Admin TI te las asigna en Gestión › Instalaciones.</div>
<?php else: ?>
    <div class="row g-4">
        <div class="col-lg-5">
            <div class="card border-0 shadow-sm h-100">
                <div class="card-header bg-white fw-semibold">Mis instalaciones</div>
                <ul class="list-group list-group-flush">
                    <?php foreach ($instalaciones as $i): ?>
                        <li class="list-group-item d-flex justify-content-between">
                            <span><?= e($i['nombre']) ?></span>
                            <a class="small" href="<?= e(url('/supervisor/ubicaciones?instalacion=' . $i['id'])) ?>">Ubicaciones</a>
                        </li>
                    <?php endforeach; ?>
                </ul>
            </div>
        </div>
        <div class="col-lg-7">
            <div class="card border-0 shadow-sm h-100">
                <div class="card-header bg-white fw-semibold">Mis agentes (<?= e(count($agentes)) ?>)</div>
                <?php if ($agentes === []): ?>
                    <div class="card-body small text-body-secondary">Asigna agentes desde <a href="<?= e(url('/supervisor/agentes')) ?>">Agentes</a>.</div>
                <?php else: ?>
                    <ul class="list-group list-group-flush">
                        <?php foreach ($agentes as $a): ?>
                            <li class="list-group-item"><?= e($a['nombre']) ?> <?= $a['prestado'] ? '<span class="badge text-bg-info">Prestado a mí</span>' : '' ?></li>
                        <?php endforeach; ?>
                    </ul>
                <?php endif; ?>
            </div>
        </div>
    </div>
<?php endif; ?>
