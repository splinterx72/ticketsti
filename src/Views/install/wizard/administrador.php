<?php
/**
 * @var array{ficha: string, nombre: string, email: string} $admin
 * @var bool $hasPassword
 */
?>
<h1 class="h4 mb-1">Cuenta de administrador</h1>
<p class="text-body-secondary">Será el primer usuario con rol <strong>Admin TI</strong>. Si tu ficha ya viene en el GECA, se conservarán sus equipos asignados.</p>

<form method="post" action="<?= e(url('/instalacion/administrador')) ?>" novalidate autocomplete="off">
    <?= csrf_field() ?>
    <div class="row g-3">
        <div class="col-sm-4">
            <label for="ficha" class="form-label fw-semibold">Ficha</label>
            <input type="text" class="form-control text-uppercase font-monospace" id="ficha" name="ficha" value="<?= e($admin['ficha']) ?>"
                   maxlength="20" pattern="[A-Za-z0-9\-]{1,20}" required spellcheck="false">
        </div>
        <div class="col-sm-8">
            <label for="nombre" class="form-label fw-semibold">Nombre completo</label>
            <input type="text" class="form-control" id="nombre" name="nombre" value="<?= e($admin['nombre']) ?>" maxlength="150" required autocomplete="name">
        </div>
        <div class="col-12">
            <label for="email" class="form-label fw-semibold">Correo electrónico</label>
            <input type="email" class="form-control" id="email" name="email" value="<?= e($admin['email']) ?>" maxlength="150" required autocomplete="email">
            <div class="form-text">También podrás iniciar sesión con él.</div>
        </div>
        <div class="col-md-6">
            <label for="password" class="form-label fw-semibold">Contraseña</label>
            <div class="input-group">
                <input type="password" class="form-control" id="password" name="password" minlength="12" maxlength="128"
                       <?= $hasPassword ? 'placeholder="(sin cambios)"' : 'required' ?> autocomplete="new-password" data-strength="#strength" data-min="12">
                <button class="btn btn-outline-secondary toggle-password" type="button" data-target="password" aria-label="Mostrar contraseña">
                    <i class="bi bi-eye"></i>
                </button>
            </div>
            <div class="progress mt-2" style="height: 6px;" role="progressbar" aria-label="Fortaleza de la contraseña">
                <div class="progress-bar" id="strength" style="width: 0%"></div>
            </div>
            <div class="form-text" id="strength-text">Mínimo 12 caracteres combinando mayúsculas, minúsculas, números o símbolos.</div>
        </div>
        <div class="col-md-6">
            <label for="password_confirm" class="form-label fw-semibold">Confirmar contraseña</label>
            <input type="password" class="form-control" id="password_confirm" name="password_confirm" maxlength="128"
                   <?= $hasPassword ? 'placeholder="(sin cambios)"' : 'required' ?> autocomplete="new-password">
            <?php if ($hasPassword): ?>
                <div class="form-text">Deja ambos campos vacíos para conservar la contraseña capturada.</div>
            <?php endif; ?>
        </div>
    </div>

    <div class="d-flex justify-content-between align-items-center mt-4">
        <a href="<?= e(url('/instalacion/sitio')) ?>" class="btn btn-link px-0"><i class="bi bi-arrow-left me-1"></i>Atrás</a>
        <button type="submit" class="btn btn-primary btn-lg">Continuar <i class="bi bi-arrow-right ms-1"></i></button>
    </div>
</form>
