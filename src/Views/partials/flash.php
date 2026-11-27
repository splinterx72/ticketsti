<?php
/**
 * Mensajes del sistema: fondo blanco, borde pastel por tipo, ícono y animación.
 * Éxito se cierra solo a los 6 s (data-autocierre); error y advertencia se quedan.
 *
 * @var list<array{type: string, message: string}> $flash
 */
$tipos = [
    'danger'  => ['error', 'bi-x-circle', 'No se pudo completar'],
    'warning' => ['advertencia', 'bi-exclamation-triangle', 'Atención'],
    'info'    => ['info', 'bi-info-circle', 'Para tu información'],
    'success' => ['exito', 'bi-check-circle', 'Listo'],
];
foreach ($flash ?? [] as $msg):
    [$clase, $icono, $titulo] = $tipos[$msg['type']] ?? $tipos['info'];
    ?>
    <div class="msg msg-<?= e($clase) ?>" role="<?= $clase === 'error' ? 'alert' : 'status' ?>" <?= $clase === 'exito' ? 'data-autocierre="6000"' : '' ?>>
        <span class="msg-ico" aria-hidden="true"><i class="bi <?= e($icono) ?>"></i></span>
        <div class="msg-cuerpo">
            <div class="msg-titulo"><?= e($titulo) ?></div>
            <div class="msg-texto"><?= e($msg['message']) ?></div>
        </div>
        <button type="button" class="msg-cerrar" aria-label="Cerrar">&times;</button>
        <?php if ($clase === 'exito'): ?><span class="msg-barra" aria-hidden="true"></span><?php endif; ?>
    </div>
<?php endforeach; ?>
