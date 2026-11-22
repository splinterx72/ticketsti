<?php
/**
 * @var array{host: string, port: string, database: string, username: string} $db
 * @var bool $hasPassword
 * @var array<string, mixed>|null $report
 * @var string|null $prefix
 */
?>
<div class="row g-4">
    <div class="col-lg-7">
        <h1 class="h4 mb-1">Base de datos MySQL</h1>
        <p class="text-body-secondary">Captura los datos de la base que creaste en cPanel. Probaremos la conexión y los permisos antes de seguir.</p>

        <form method="post" action="<?= e(url('/instalacion/base-datos')) ?>" class="needs-loading" autocomplete="off" novalidate>
            <?= csrf_field() ?>
            <div class="row g-3">
                <div class="col-8">
                    <label for="host" class="form-label fw-semibold">Servidor (host)</label>
                    <input type="text" class="form-control" id="host" name="host" value="<?= e($db['host']) ?>" required>
                </div>
                <div class="col-4">
                    <label for="port" class="form-label fw-semibold">Puerto</label>
                    <input type="number" class="form-control" id="port" name="port" value="<?= e($db['port']) ?>" min="1" max="65535" required>
                </div>
                <div class="col-12">
                    <label for="database" class="form-label fw-semibold">Nombre de la base de datos</label>
                    <input type="text" class="form-control font-monospace" id="database" name="database" value="<?= e($db['database']) ?>"
                           placeholder="<?= e(($prefix ?? 'usuario') . '_mesaayuda') ?>" maxlength="64" required spellcheck="false">
                </div>
                <div class="col-sm-6">
                    <label for="username" class="form-label fw-semibold">Usuario de MySQL</label>
                    <input type="text" class="form-control font-monospace" id="username" name="username" value="<?= e($db['username']) ?>"
                           placeholder="<?= e(($prefix ?? 'usuario') . '_tickets') ?>" maxlength="80" required spellcheck="false" autocomplete="off">
                </div>
                <div class="col-sm-6">
                    <label for="password" class="form-label fw-semibold">Contraseña</label>
                    <div class="input-group">
                        <input type="password" class="form-control" id="password" name="password"
                               <?= $hasPassword ? 'placeholder="(sin cambios)"' : 'required' ?> autocomplete="new-password">
                        <button class="btn btn-outline-secondary toggle-password" type="button" data-target="password" aria-label="Mostrar contraseña">
                            <i class="bi bi-eye"></i>
                        </button>
                    </div>
                    <?php if ($hasPassword): ?>
                        <input type="hidden" name="keep_password" value="1">
                        <div class="form-text">Déjala vacía para conservar la que ya probaste.</div>
                    <?php endif; ?>
                </div>
            </div>

            <div class="d-flex justify-content-between align-items-center mt-4">
                <a href="<?= e(url('/instalacion')) ?>" class="btn btn-link px-0"><i class="bi bi-arrow-left me-1"></i>Atrás</a>
                <button type="submit" class="btn btn-primary btn-lg">
                    <span class="spinner-border spinner-border-sm me-1 d-none" aria-hidden="true"></span>
                    Probar conexión y continuar <i class="bi bi-arrow-right ms-1"></i>
                </button>
            </div>
        </form>

        <?php if ($report !== null): ?>
            <div class="alert alert-success small mt-4 mb-0">
                <i class="bi bi-check-circle me-1"></i>Última prueba correcta: <?= e($report['server']) ?> <?= e($report['version']) ?>.
                <?= $report['mode'] === 'reuse' ? 'Se detectaron las tablas de una instalación previa; se conservarán.' : 'La base está vacía y lista.' ?>
            </div>
        <?php endif; ?>
    </div>

    <div class="col-lg-5">
        <div class="card border-0 bg-body-tertiary">
            <div class="card-body p-4 small">
                <h2 class="h6 fw-semibold"><i class="bi bi-lightbulb me-1"></i>¿Aún no creas la base?</h2>
                <p class="text-body-secondary">En cPanel no se puede crear desde PHP. Toma 2 minutos:</p>
                <ol class="ps-3 mb-3">
                    <li class="mb-1">cPanel › <strong>MySQL® Databases</strong>.</li>
                    <li class="mb-1"><em>Create New Database</em>: escribe <code>mesaayuda</code>.
                        Quedará como <code><?= e(($prefix ?? 'usuario') . '_mesaayuda') ?></code>.</li>
                    <li class="mb-1"><em>Add New User</em>: escribe <code>tickets</code> y usa el generador de contraseñas.</li>
                    <li class="mb-1"><em>Add User To Database</em>: elige ambos y marca <strong>ALL PRIVILEGES</strong>.</li>
                </ol>
                <p class="mb-0 text-body-secondary">
                    <i class="bi bi-info-circle me-1"></i>El servidor casi siempre es <code>localhost</code> y el puerto <code>3306</code>.
                    <?php if ($prefix !== null): ?>Tu prefijo de cPanel parece ser <code><?= e($prefix) ?>_</code>.<?php endif; ?>
                </p>
            </div>
        </div>
    </div>
</div>
