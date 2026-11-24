<?php
/**
 * @var array<string, string> $roles
 * @var array<string, string> $tipos
 * @var array<string, string> $old
 */
$v = static fn (string $k, string $d = ''): string => (string) ($old[$k] ?? $d);
?>
<div class="d-flex justify-content-between align-items-center mb-3">
    <h1 class="h4 mb-0"><i class="bi bi-person-plus me-1"></i>Crear cuenta</h1>
    <a href="<?= e(url('/ti/usuarios')) ?>" class="btn btn-link"><i class="bi bi-arrow-left me-1"></i>Usuarios</a>
</div>

<form method="post" action="<?= e(url('/ti/usuarios/nueva')) ?>" class="card border-0 shadow-sm mx-auto" style="max-width: 44rem;" novalidate>
    <div class="card-body p-4">
        <?= csrf_field() ?>
        <div class="alert alert-info small">
            <i class="bi bi-info-circle me-1"></i>Los trabajadores llegan con el GECA y activan su propia cuenta. Aquí solo se crean
            <strong>cuentas de administración</strong> (p. ej. <code>ADMIN-TI</code>, sin contraseña de admin local) y
            <strong>cuentas de prueba</strong> (una por rol; no aparecen en reportes). Nacen activas, con el correo verificado.
        </div>
        <fieldset class="mb-3">
            <legend class="form-label fw-semibold fs-6">Tipo de cuenta</legend>
            <?php foreach ($tipos as $k => $label): ?>
                <div class="form-check form-check-inline">
                    <input class="form-check-input" type="radio" name="tipo_cuenta" id="tipo_<?= e($k) ?>" value="<?= e($k) ?>" <?= $v('tipo_cuenta', 'prueba') === $k ? 'checked' : '' ?>>
                    <label class="form-check-label" for="tipo_<?= e($k) ?>"><?= e($label) ?></label>
                </div>
            <?php endforeach; ?>
        </fieldset>
        <div class="row g-3">
            <div class="col-sm-5">
                <label for="ficha" class="form-label fw-semibold">Ficha</label>
                <input type="text" class="form-control text-uppercase font-monospace" id="ficha" name="ficha" maxlength="20" required
                       pattern="[A-Za-z0-9-]{3,20}" value="<?= e($v('ficha')) ?>" placeholder="AGENTE-TI">
                <div class="form-text">Letras, números y guion. No debe existir en el GECA.</div>
            </div>
            <div class="col-sm-7">
                <label for="nombre" class="form-label fw-semibold">Nombre</label>
                <input type="text" class="form-control" id="nombre" name="nombre" maxlength="150" required value="<?= e($v('nombre')) ?>" placeholder="Agente de pruebas">
            </div>
            <div class="col-sm-7">
                <label for="email" class="form-label fw-semibold">Correo</label>
                <input type="email" class="form-control" id="email" name="email" maxlength="150" required value="<?= e($v('email')) ?>" autocomplete="off">
                <div class="form-text">Con él también se entra. Debe ser distinto en cada cuenta.</div>
            </div>
            <div class="col-sm-5">
                <label for="rol" class="form-label fw-semibold">Rol</label>
                <select class="form-select" id="rol" name="rol" required>
                    <?php foreach ($roles as $k => $label): ?>
                        <option value="<?= e($k) ?>" <?= $v('rol', 'agente_ti') === $k ? 'selected' : '' ?>><?= e($label) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="col-sm-6">
                <label for="password" class="form-label fw-semibold">Contraseña</label>
                <input type="password" class="form-control" id="password" name="password" required autocomplete="new-password">
                <div class="form-text">Mínimo 10 caracteres (12 para Admin TI); sin la ficha, el nombre ni el correo.</div>
            </div>
            <div class="col-sm-6">
                <label for="password_confirm" class="form-label fw-semibold">Confirmar contraseña</label>
                <input type="password" class="form-control" id="password_confirm" name="password_confirm" required autocomplete="new-password">
            </div>
        </div>
    </div>
    <div class="card-footer bg-white text-end">
        <button type="submit" class="btn btn-primary"><i class="bi bi-check2 me-1"></i>Crear cuenta</button>
    </div>
</form>
