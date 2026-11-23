<?php
/**
 * @var int|null $dias
 * @var string|null $vence
 * @var string $semaforo
 * @var bool $bloqueado
 * @var string|null $cambiado
 */
$color = ['verde' => 'success', 'amarillo' => 'warning', 'rojo' => 'danger'][$semaforo];
$estado = match (true) {
    $dias === null => 'No has registrado la fecha de tu último cambio.',
    $dias < 0      => 'Tu contraseña venció hace ' . abs($dias) . ' día' . (abs($dias) === 1 ? '' : 's') . '.',
    $dias === 0    => 'Tu contraseña vence HOY.',
    default        => "Tu contraseña vence en {$dias} día" . ($dias === 1 ? '' : 's') . ' (' . fecha_mx($vence, false) . ').',
};
?>
<div class="card shadow border-0 <?= $bloqueado ? '' : 'mx-auto' ?>" style="<?= $bloqueado ? '' : 'max-width: 36rem;' ?>">
    <div class="card-body p-4">
        <div class="text-center mb-3">
            <i class="bi <?= $bloqueado ? 'bi-shield-lock' : 'bi-shield-check' ?> display-5 text-<?= e($color) ?>"></i>
            <h2 class="h5 mt-2 mb-1"><?= $bloqueado ? 'Acceso bloqueado' : 'Contraseña de admin local' ?></h2>
            <p class="small mb-0 text-<?= e($color) ?>-emphasis fw-semibold"><?= e($estado) ?></p>
        </div>

        <?php if ($bloqueado): ?>
            <div class="alert alert-danger small">
                Para proteger tu acceso, el sistema se bloquea cuando faltan <strong>5 días o menos</strong> para que venza tu contraseña de
                admin local. <strong>Cámbiala ahora</strong> y registra aquí la fecha del cambio para continuar. Si se vence, reactivarla
                puede tardar meses.
            </div>
        <?php else: ?>
            <p class="small text-body-secondary">
                Vence cada 3 meses. Te avisaremos desde 10 días antes; a 5 días se bloquea tu acceso hasta registrar el cambio.
                <?php if ($cambiado !== null): ?>Último cambio registrado: <strong><?= e(fecha_mx($cambiado, false)) ?></strong>.<?php endif; ?>
            </p>
        <?php endif; ?>

        <form method="post" action="<?= e(url('/admin-local')) ?>" novalidate>
            <?= csrf_field() ?>
            <label for="fecha" class="form-label fw-semibold">¿Cuándo la cambiaste?</label>
            <input type="date" class="form-control" id="fecha" name="fecha" required value="<?= e(date('Y-m-d')) ?>" max="<?= e(date('Y-m-d')) ?>">
            <div class="form-check mt-3">
                <input class="form-check-input" type="checkbox" id="confirmo" name="confirmo" value="1" required>
                <label class="form-check-label small" for="confirmo">Confirmo que ya cambié mi contraseña de admin local.</label>
            </div>
            <p class="small text-body-secondary mt-2 mb-0"><i class="bi bi-info-circle me-1"></i>Solo se guarda la fecha; nunca la contraseña.</p>
            <button type="submit" class="btn btn-primary w-100 mt-3"><i class="bi bi-check2-circle me-1"></i>Registrar el cambio</button>
        </form>
    </div>
    <?php if ($bloqueado): ?>
        <div class="card-footer bg-white text-center">
            <form method="post" action="<?= e(url('/logout')) ?>" class="d-inline"><?= csrf_field() ?><button type="submit" class="btn btn-link btn-sm p-0">Cerrar sesión</button></form>
        </div>
    <?php endif; ?>
</div>
