<?php
/**
 * @var array<string, mixed> $persona
 * @var string|null $enviadoA
 * @var list<string> $dominios
 * @var bool $dominioOk
 */
$sugerido = $enviadoA ?? ($dominioOk ? (string) $persona['email'] : '');
?>
<div class="card shadow border-0">
    <div class="card-body p-4">
        <div class="text-center mb-3">
            <i class="bi bi-envelope-check display-5 text-primary"></i>
            <h2 class="h5 mt-2 mb-1">Verifica tu correo de la empresa</h2>
            <p class="small text-body-secondary mb-0">Hola <?= e($persona['nombre']) ?>. Para usar el sistema necesitamos confirmar que el correo es tuyo.</p>
        </div>

        <?php if ($enviadoA !== null): ?>
            <div class="alert alert-info small">
                Enviamos un enlace a <strong><?= e($enviadoA) ?></strong>. Ábrelo desde tu buzón; vence en 24 horas.
                Si no lo ves, revisa la carpeta de correo no deseado.
            </div>
        <?php elseif (!$dominioOk): ?>
            <div class="alert alert-warning small">
                Tu cuenta tiene un correo que no es de la empresa. Captura tu correo @<?= e(implode(', @', $dominios)) ?>.
            </div>
        <?php endif; ?>

        <form method="post" action="<?= e(url('/verificar-correo')) ?>" novalidate>
            <?= csrf_field() ?>
            <label for="email" class="form-label fw-semibold">Correo de la empresa</label>
            <input type="email" class="form-control" id="email" name="email" maxlength="150" required
                   value="<?= e($sugerido) ?>" placeholder="nombre@<?= e($dominios[0]) ?>" autocomplete="email">
            <button type="submit" class="btn btn-primary w-100 mt-3">
                <i class="bi bi-send me-1"></i><?= $enviadoA !== null ? 'Reenviar enlace' : 'Enviar enlace' ?>
            </button>
        </form>
    </div>
    <div class="card-footer bg-white small text-center">
        <form method="post" action="<?= e(url('/logout')) ?>" class="d-inline">
            <?= csrf_field() ?>
            <button type="submit" class="btn btn-link btn-sm p-0">Salir</button>
        </form>
    </div>
</div>
