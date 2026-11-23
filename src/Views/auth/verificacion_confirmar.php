<?php
/**
 * Confirmación del enlace de verificación (GET no gasta el enlace).
 *
 * @var array{ficha: string, nombre: string, email: string} $enlace
 * @var string $token
 */
?>
<div class="card shadow border-0">
    <div class="card-body p-4">
        <div class="text-center mb-3">
            <i class="bi bi-envelope-check display-5 text-primary"></i>
            <h2 class="h5 mt-2 mb-1">Confirma tu correo</h2>
            <p class="small text-body-secondary mb-0">
                Ficha <strong><?= e($enlace['ficha']) ?></strong> · <?= e($enlace['nombre']) ?><br>
                Correo: <strong><?= e($enlace['email']) ?></strong>
            </p>
        </div>

        <div class="alert alert-warning small">
            Si tú no solicitaste esta cuenta o este cambio de correo, <strong>no continúes</strong>: cierra esta página.
        </div>

        <form method="post" action="<?= e(url('/verificar/' . rawurlencode($token))) ?>" novalidate>
            <?= csrf_field() ?>
            <label for="password" class="form-label fw-semibold">Tu contraseña</label>
            <input type="password" class="form-control" id="password" name="password" required autocomplete="current-password" autofocus>
            <div class="form-text">La que capturaste al registrarte (o la de tu cuenta, si cambias de correo).</div>
            <button type="submit" class="btn btn-primary w-100 mt-3"><i class="bi bi-check2-circle me-1"></i>Confirmar mi correo</button>
        </form>
    </div>
</div>
