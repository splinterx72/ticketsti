<?php
/**
 * @var list<array{group: string, label: string, status: string, detail: string}> $items
 * @var bool $canContinue
 * @var bool $keyReady
 * @var bool $keyOk
 * @var string $rootPath
 */
$groups = [];
$totals = ['ok' => 0, 'warn' => 0, 'fail' => 0];
foreach ($items as $item) {
    $groups[$item['group']][] = $item;
    $totals[$item['status']]++;
}
$icons = [
    'ok'   => ['bi-check-circle-fill', 'text-success', 'Correcto'],
    'warn' => ['bi-exclamation-triangle-fill', 'text-warning', 'Advertencia'],
    'fail' => ['bi-x-circle-fill', 'text-danger', 'Obligatorio'],
];
?>
<div class="d-flex flex-wrap justify-content-between align-items-start gap-3 mb-4">
    <div>
        <h1 class="h4 mb-1">Requisitos del servidor</h1>
        <p class="text-body-secondary mb-0">Verificamos que tu hosting tenga todo lo necesario antes de instalar.</p>
    </div>
    <div class="d-flex gap-2 small">
        <span class="badge rounded-pill text-bg-success"><?= e($totals['ok']) ?> correctos</span>
        <?php if ($totals['warn'] > 0): ?><span class="badge rounded-pill text-bg-warning"><?= e($totals['warn']) ?> advertencias</span><?php endif; ?>
        <?php if ($totals['fail'] > 0): ?><span class="badge rounded-pill text-bg-danger"><?= e($totals['fail']) ?> pendientes</span><?php endif; ?>
    </div>
</div>

<div class="row g-4">
    <div class="col-lg-7">
        <div class="accordion requirements" id="reqAccordion">
            <?php $i = 0; foreach ($groups as $group => $rows):
                $i++;
                $worst = 'ok';
                foreach ($rows as $r) {
                    if ($r['status'] === 'fail') { $worst = 'fail'; break; }
                    if ($r['status'] === 'warn') { $worst = 'warn'; }
                }
                $open = $worst !== 'ok';
                ?>
                <div class="accordion-item">
                    <h2 class="accordion-header">
                        <button class="accordion-button <?= $open ? '' : 'collapsed' ?>" type="button" data-bs-toggle="collapse"
                                data-bs-target="#req<?= e($i) ?>" aria-expanded="<?= $open ? 'true' : 'false' ?>">
                            <i class="bi <?= e($icons[$worst][0]) ?> <?= e($icons[$worst][1]) ?> me-2"></i>
                            <span class="fw-semibold"><?= e($group) ?></span>
                            <span class="ms-auto me-2 small text-body-secondary"><?= e(count($rows)) ?></span>
                        </button>
                    </h2>
                    <div id="req<?= e($i) ?>" class="accordion-collapse collapse <?= $open ? 'show' : '' ?>">
                        <ul class="list-group list-group-flush">
                            <?php foreach ($rows as $r): ?>
                                <li class="list-group-item d-flex gap-3 align-items-start">
                                    <i class="bi <?= e($icons[$r['status']][0]) ?> <?= e($icons[$r['status']][1]) ?> mt-1"
                                       title="<?= e($icons[$r['status']][2]) ?>"></i>
                                    <div>
                                        <div class="fw-medium"><?= e($r['label']) ?></div>
                                        <div class="small text-body-secondary"><?= e($r['detail']) ?></div>
                                    </div>
                                </li>
                            <?php endforeach; ?>
                        </ul>
                    </div>
                </div>
            <?php endforeach; ?>
        </div>
        <a href="<?= e(url('/instalacion')) ?>" class="btn btn-link btn-sm px-0 mt-2"><i class="bi bi-arrow-clockwise me-1"></i>Volver a verificar</a>
    </div>

    <div class="col-lg-5">
        <div class="card border-0 bg-body-tertiary h-100">
            <div class="card-body p-4">
                <h2 class="h6 fw-semibold"><i class="bi bi-key me-1"></i>Clave de instalación</h2>

                <?php if ($keyOk): ?>
                    <div class="alert alert-success small mb-4"><i class="bi bi-shield-check me-1"></i>Clave verificada. Solo tú puedes continuar esta instalación.</div>
                    <?php if ($canContinue): ?>
                        <a href="<?= e(url('/instalacion/base-datos')) ?>" class="btn btn-primary btn-lg w-100">
                            Continuar <i class="bi bi-arrow-right ms-1"></i>
                        </a>
                    <?php else: ?>
                        <div class="alert alert-danger small mb-0">Corrige los requisitos marcados en rojo y pulsa “Volver a verificar”.</div>
                    <?php endif; ?>
                <?php elseif (!$keyReady): ?>
                    <div class="alert alert-danger small mb-0">
                        No se pudo generar la clave porque la carpeta <code>storage/</code> no tiene permisos de escritura.
                        Asígnale permisos <strong>755</strong> desde el Administrador de archivos y recarga esta página.
                    </div>
                <?php else: ?>
                    <p class="small text-body-secondary">
                        Para evitar que otra persona instale el sistema antes que tú, generamos una clave privada
                        que solo puede leer quien tiene acceso al cPanel.
                    </p>
                    <ol class="small ps-3 mb-3">
                        <li>Abre <strong>cPanel › Administrador de archivos</strong>.</li>
                        <li>Entra a <code class="text-break"><?= e($rootPath) ?>/storage/</code></li>
                        <li>Abre <code>install.key</code> (clic derecho › <em>View</em>) y copia la clave.</li>
                    </ol>
                    <form method="post" action="<?= e(url('/instalacion/clave')) ?>" autocomplete="off">
                        <?= csrf_field() ?>
                        <label for="clave" class="form-label small fw-semibold">Clave</label>
                        <input type="text" class="form-control form-control-lg font-monospace text-center text-uppercase install-key"
                               id="clave" name="clave" placeholder="XXXX-XXXX-XXXX" maxlength="20" required
                               spellcheck="false" autocapitalize="characters" autofocus>
                        <button type="submit" class="btn btn-primary w-100 mt-3">
                            <i class="bi bi-unlock me-1"></i>Verificar clave
                        </button>
                    </form>
                <?php endif; ?>
            </div>
        </div>
    </div>
</div>
