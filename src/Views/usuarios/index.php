<?php
/**
 * @var list<array<string, mixed>> $usuarios
 * @var array<string, string> $roles
 * @var string $q
 * @var string $rol
 * @var string $estado
 * @var int $page
 * @var int $pages
 * @var int $total
 * @var string $mailDriver
 * @var array<string, mixed> $authUser
 */
$estados = ['registrados' => 'Cuentas activas', 'sin_activar' => 'Sin activar', 'inactivos' => 'Desactivados', 'todos' => 'Todos'];
$volver = http_build_query(array_filter(['q' => $q, 'rol' => $rol, 'estado' => $estado, 'p' => $page > 1 ? $page : null]));
$driverLabel = ['off' => 'Desactivado', 'mail' => 'mail() del hosting', 'smtp' => 'SMTP'][$mailDriver] ?? $mailDriver;
?>
<div class="d-flex flex-wrap justify-content-between align-items-center gap-2 mb-3">
    <h1 class="h4 mb-0"><i class="bi bi-people me-1"></i>Usuarios</h1>
    <form method="post" action="<?= e(url('/ti/usuarios/correo-prueba')) ?>" class="d-flex align-items-center gap-2 m-0">
        <?= csrf_field() ?>
        <span class="small text-body-secondary">Correo: <strong><?= e($driverLabel) ?></strong></span>
        <button type="submit" class="btn btn-sm btn-outline-secondary" <?= $mailDriver === 'off' ? 'disabled' : '' ?>>
            <i class="bi bi-envelope-check me-1"></i>Enviarme un correo de prueba
        </button>
    </form>
</div>

<form method="get" action="<?= e(url('/ti/usuarios')) ?>" class="card border-0 shadow-sm mb-3" role="search">
    <div class="card-body row g-2 align-items-end">
        <div class="col-md-5">
            <label class="form-label small mb-1" for="q">Buscar</label>
            <input type="search" class="form-control" id="q" name="q" value="<?= e($q) ?>" placeholder="Ficha, nombre o correo">
        </div>
        <div class="col-6 col-md-3">
            <label class="form-label small mb-1" for="estado">Cuenta</label>
            <select class="form-select" id="estado" name="estado">
                <?php foreach ($estados as $k => $label): ?><option value="<?= e($k) ?>" <?= $estado === $k ? 'selected' : '' ?>><?= e($label) ?></option><?php endforeach; ?>
            </select>
        </div>
        <div class="col-6 col-md-2">
            <label class="form-label small mb-1" for="rol">Rol</label>
            <select class="form-select" id="rol" name="rol">
                <option value="">Todos</option>
                <?php foreach ($roles as $k => $label): ?><option value="<?= e($k) ?>" <?= $rol === $k ? 'selected' : '' ?>><?= e($label) ?></option><?php endforeach; ?>
            </select>
        </div>
        <div class="col-md-2 d-grid">
            <button class="btn btn-primary" type="submit"><i class="bi bi-search me-1"></i>Buscar</button>
        </div>
    </div>
</form>

<div class="alert alert-info small d-flex gap-2">
    <i class="bi bi-info-circle flex-shrink-0"></i>
    <div>Los trabajadores llegan con la carga del GECA y activan su cuenta con su ficha. Para dar acceso a un técnico, búscalo y cámbiale el rol a <strong>Agente TI</strong> (su cuenta debe estar activada).</div>
</div>

<?php if ($usuarios === []): ?>
    <div class="card border-0 shadow-sm"><div class="card-body text-center py-5 text-body-secondary">No se encontraron usuarios.</div></div>
<?php else: ?>
    <div class="card border-0 shadow-sm">
        <div class="table-responsive">
            <table class="table align-middle mb-0">
                <thead class="table-light">
                <tr><th>Trabajador</th><th>Contacto</th><th class="text-end">Equipos</th><th>Rol y estado</th><th></th></tr>
                </thead>
                <tbody>
                <?php foreach ($usuarios as $u): $self = (int) $u['id'] === (int) $authUser['id']; ?>
                    <tr class="<?= (int) $u['activo'] === 0 ? 'opacity-50' : '' ?>">
                        <td>
                            <div class="fw-semibold"><?= e($u['nombre']) ?> <?= $self ? '<span class="badge text-bg-light border">Tú</span>' : '' ?></div>
                            <div class="small text-body-secondary font-monospace"><?= e($u['ficha']) ?></div>
                        </td>
                        <td class="small">
                            <?php if ((int) $u['registrado'] === 1): ?>
                                <?= e($u['email'] ?? '—') ?><div class="text-body-secondary"><?= e($u['telefono_ext'] ?? '') ?></div>
                            <?php else: ?>
                                <span class="text-body-secondary">Cuenta sin activar</span>
                            <?php endif; ?>
                        </td>
                        <td class="text-end"><?= e($u['equipos']) ?></td>
                        <td>
                            <form method="post" action="<?= e(url('/ti/usuarios/' . (int) $u['id'])) ?>" class="d-flex flex-wrap gap-2 align-items-center m-0">
                                <?= csrf_field() ?>
                                <input type="hidden" name="volver" value="<?= e($volver) ?>">
                                <select name="rol" class="form-select form-select-sm w-auto" aria-label="Rol de <?= e($u['nombre']) ?>" <?= $self ? 'disabled' : '' ?>>
                                    <?php foreach ($roles as $k => $label): ?><option value="<?= e($k) ?>" <?= $u['rol'] === $k ? 'selected' : '' ?>><?= e($label) ?></option><?php endforeach; ?>
                                </select>
                                <select name="activo" class="form-select form-select-sm w-auto" aria-label="Estado de <?= e($u['nombre']) ?>" <?= $self ? 'disabled' : '' ?>>
                                    <option value="1" <?= (int) $u['activo'] === 1 ? 'selected' : '' ?>>Activo</option>
                                    <option value="0" <?= (int) $u['activo'] === 0 ? 'selected' : '' ?>>Desactivado</option>
                                </select>
                                <?php if (!$self): ?><button type="submit" class="btn btn-sm btn-outline-primary">Guardar</button><?php endif; ?>
                            </form>
                        </td>
                        <td class="text-end">
                            <?php if (!$self && (int) $u['registrado'] === 1): ?>
                                <form method="post" action="<?= e(url('/ti/usuarios/' . (int) $u['id'] . '/restablecer')) ?>" class="m-0"
                                      data-confirm="¿Generar una contraseña temporal para <?= e($u['nombre']) ?>? La actual dejará de funcionar.">
                                    <?= csrf_field() ?>
                                    <input type="hidden" name="volver" value="<?= e($volver) ?>">
                                    <button type="submit" class="btn btn-sm btn-link text-nowrap"><i class="bi bi-key me-1"></i>Restablecer contraseña</button>
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
                <?php for ($i = max(1, $page - 5); $i <= min($pages, $page + 5); $i++): ?>
                    <li class="page-item<?= $i === $page ? ' active' : '' ?>"><a class="page-link" href="<?= e(query_url('/ti/usuarios', ['p' => $i])) ?>"><?= e($i) ?></a></li>
                <?php endfor; ?>
            </ul>
        </nav>
    <?php endif; ?>
    <p class="small text-body-secondary text-center mt-2"><?= e(number_format($total)) ?> usuario(s)</p>
<?php endif; ?>
