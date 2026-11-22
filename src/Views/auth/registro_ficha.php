<?php
/** @var array<string, string> $old */
?>
<div class="card shadow border-0">
    <div class="card-body p-4">
        <div class="d-flex align-items-center gap-2 mb-1">
            <span class="badge rounded-pill text-bg-primary">Paso 1 de 2</span>
        </div>
        <h2 class="h5 mb-2">Activa tu cuenta</h2>
        <p class="small text-body-secondary">
            Si tienes equipo de cómputo en resguardo, tu ficha ya está en el sistema.
            Escríbela para comenzar.
        </p>
        <form method="post" action="<?= e(url('/registro')) ?>" novalidate>
            <?= csrf_field() ?>
            <div class="mb-3">
                <label for="ficha" class="form-label fw-semibold">Número de ficha</label>
                <input type="text" class="form-control form-control-lg font-monospace text-uppercase" id="ficha" name="ficha"
                       value="<?= e($old['ficha'] ?? '') ?>" maxlength="20" inputmode="text" autocomplete="off"
                       spellcheck="false" required autofocus>
            </div>
            <button type="submit" class="btn btn-primary w-100">
                Continuar <i class="bi bi-arrow-right ms-1"></i>
            </button>
        </form>
    </div>
    <div class="card-footer bg-white small text-center">
        ¿Ya activaste tu cuenta? <a href="<?= e(url('/login')) ?>">Inicia sesión</a>
    </div>
</div>
