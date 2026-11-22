<?php
/** @var array<string, string> $old */
?>
<div class="card shadow border-0">
    <div class="card-body p-4">
        <h2 class="h5 mb-1">Instalación inicial</h2>
        <p class="small text-body-secondary">
            Crea la cuenta del primer <strong>Administrador TI</strong>. Esta página se desactiva
            automáticamente en cuanto exista un administrador.
        </p>
        <form method="post" action="<?= e(url('/instalar')) ?>" novalidate>
            <?= csrf_field() ?>
            <div class="row g-3">
                <div class="col-sm-5">
                    <label for="ficha" class="form-label">Ficha</label>
                    <input type="text" class="form-control" id="ficha" name="ficha" maxlength="20"
                           value="<?= e($old['ficha'] ?? '') ?>" required>
                </div>
                <div class="col-sm-7">
                    <label for="nombre" class="form-label">Nombre completo</label>
                    <input type="text" class="form-control" id="nombre" name="nombre" maxlength="150"
                           value="<?= e($old['nombre'] ?? '') ?>" required>
                </div>
                <div class="col-12">
                    <label for="email" class="form-label">Correo electrónico</label>
                    <input type="email" class="form-control" id="email" name="email" maxlength="150"
                           value="<?= e($old['email'] ?? '') ?>" autocomplete="email" required>
                </div>
                <div class="col-sm-6">
                    <label for="password" class="form-label">Contraseña</label>
                    <input type="password" class="form-control" id="password" name="password" minlength="10"
                           autocomplete="new-password" required>
                    <div class="form-text">Mínimo 10 caracteres.</div>
                </div>
                <div class="col-sm-6">
                    <label for="password_confirm" class="form-label">Confirmar contraseña</label>
                    <input type="password" class="form-control" id="password_confirm" name="password_confirm"
                           minlength="10" autocomplete="new-password" required>
                </div>
            </div>
            <button type="submit" class="btn btn-primary w-100 mt-4">
                <i class="bi bi-shield-check me-1"></i>Crear administrador
            </button>
        </form>
    </div>
</div>
