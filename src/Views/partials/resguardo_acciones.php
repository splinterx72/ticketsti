<?php
/**
 * Acciones de un equipo en resguardo según su estado.
 *
 * @var array<string, mixed> $item fila de equipos_resguardo (con asignado_ficha/asignado_nombre)
 * @var string $volver lista | disponibles | recepcion
 */
$id = (int) $item['id'];
$boton = static function (string $estado, string $texto, string $clase, string $confirm = '') use ($id, $volver): string {
    return '<form method="post" action="' . e(url('/resguardo/equipo/' . $id . '/estado')) . '" class="d-inline m-0"'
        . ($confirm !== '' ? ' data-confirm="' . e($confirm) . '"' : '') . '>' . csrf_field()
        . '<input type="hidden" name="estado" value="' . e($estado) . '"><input type="hidden" name="volver" value="' . e($volver) . '">'
        . '<button class="btn btn-sm ' . e($clase) . '" type="submit">' . e($texto) . '</button></form>';
};
?>
<div class="d-flex flex-wrap gap-1 align-items-center">
    <?php if ($item['estado'] === 'en_resguardo'): ?>
        <?= $boton('disponible', 'Disponible', 'btn-success') ?>
        <?= $boton('dictamen', 'Para dictamen', 'btn-outline-secondary') ?>
        <?= $boton('baja_definitiva', 'Baja definitiva', 'btn-outline-dark', '¿Marcar como baja definitiva? Ya no podrá asignarse.') ?>
    <?php elseif ($item['estado'] === 'dictamen'): ?>
        <?= $boton('disponible', 'Disponible', 'btn-success') ?>
        <?= $boton('baja_definitiva', 'Baja definitiva', 'btn-outline-dark', '¿Marcar como baja definitiva? Ya no podrá asignarse.') ?>
    <?php elseif ($item['estado'] === 'disponible'): ?>
        <form method="post" action="<?= e(url('/resguardo/equipo/' . $id . '/asignar')) ?>" class="d-flex flex-wrap gap-1 m-0">
            <?= csrf_field() ?>
            <input type="hidden" name="volver" value="<?= e($volver) ?>">
            <input class="form-control form-control-sm font-monospace" name="ficha" maxlength="20" required placeholder="Ficha" aria-label="Ficha de a quién se asigna" style="width: 7.5rem;">
            <input class="form-control form-control-sm" name="nota" maxlength="500" placeholder="Nota (opcional)" aria-label="Nota de la asignación" style="width: 11rem;">
            <button class="btn btn-sm btn-primary" type="submit"><i class="bi bi-person-check me-1"></i>Asignar</button>
        </form>
        <?= $boton('en_resguardo', 'Regresar a resguardo', 'btn-link btn-sm') ?>
    <?php elseif ($item['estado'] === 'asignado'): ?>
        <span class="small">A <strong><?= e(($item['asignado_ficha'] ?? '') . ' ' . ($item['asignado_nombre'] ?? '')) ?></strong> ·
            <?= $item['confirmado_geca_en'] ? '<span class="text-success"><i class="bi bi-check-circle-fill me-1"></i>Confirmado en GECA</span>' : '<span class="text-warning-emphasis"><i class="bi bi-hourglass-split me-1"></i>Pendiente en GECA</span>' ?></span>
    <?php endif; ?>
</div>
