<?php
/**
 * @var array<string, string> $old
 * @var list<array<string, mixed>> $avisos
 */
?>
<?= partial('avisos', ['avisos' => $avisos ?? []]) ?>
<div class="card shadow border-0">
    <div class="card-body p-4">
        <h2 class="h5 mb-3">Iniciar sesión</h2>
        <form method="post" action="<?= e(url('/login')) ?>" novalidate>
            <?= csrf_field() ?>
            <div class="mb-3">
                <label for="identificador" class="form-label">Ficha o correo electrónico</label>
                <input type="text" class="form-control" id="identificador" name="identificador"
                       value="<?= e($old['identificador'] ?? '') ?>" autocomplete="username" required autofocus>
            </div>
            <div class="mb-3">
                <label for="password" class="form-label">Contraseña</label>
                <input type="password" class="form-control" id="password" name="password"
                       autocomplete="current-password" required>
            </div>
            <button type="submit" class="btn btn-primary w-100">
                <i class="bi bi-box-arrow-in-right me-1"></i>Entrar
            </button>
        </form>
    </div>
    <div class="card-footer bg-white small text-body-secondary text-center">
        ¿Primera vez? <a href="<?= e(url('/registro')) ?>" class="fw-semibold">Activa tu cuenta con tu ficha</a>
    </div>
</div>
