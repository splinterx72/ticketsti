<?php
/**
 * Personas autorizadas para levantar tickets a nombre de un jefe.
 *
 * @var list<array<string, mixed>> $delegaciones
 * @var bool $puede        puede autorizar/quitar
 * @var string $accion     ruta POST para autorizar
 * @var string $volver     ruta a la que regresar tras quitar
 * @var string $quien      "tu nombre" o el nombre del jefe
 * @var array<string, string> $old
 */
$old = $old ?? [];
?>
<div class="card border-0 shadow-sm mb-4" id="delegaciones">
    <div class="card-header bg-white fw-semibold"><i class="bi bi-person-check me-1"></i>Quién puede levantar tickets <?= $quien === 'ti' ? 'a tu nombre' : 'a nombre de ' . e($quien) ?></div>
    <?php if ($delegaciones === []): ?>
        <div class="card-body small text-body-secondary pb-0">Nadie por ahora. Útil para tu secretaria o asistente cuando no tengas tiempo.</div>
    <?php else: ?>
        <ul class="list-group list-group-flush">
            <?php foreach ($delegaciones as $d): ?>
                <li class="list-group-item d-flex flex-wrap justify-content-between align-items-center gap-2<?= (int) $d['vigente'] === 1 ? '' : ' opacity-50' ?>">
                    <div class="small">
                        <div class="fw-semibold fs-6"><?= e($d['delegado_nombre']) ?> <span class="font-monospace text-body-secondary small"><?= e($d['delegado_ficha']) ?></span></div>
                        <span class="text-body-secondary">
                            <?= (int) $d['ver_todos'] === 1 ? 'Ve y comenta todos los tickets' : 'Solo los tickets que levante' ?>
                            · <?= $d['hasta'] ? ((int) $d['vigente'] === 1 ? 'hasta ' : 'venció el ') . e(fecha_mx((string) $d['hasta'], false)) : 'sin fecha de fin' ?>
                        </span>
                    </div>
                    <?php if ($puede): ?>
                        <form method="post" action="<?= e(url('/delegaciones/' . (int) $d['id'] . '/quitar')) ?>" class="m-0"
                              data-confirm="¿Quitar la autorización de <?= e($d['delegado_nombre']) ?>?">
                            <?= csrf_field() ?>
                            <input type="hidden" name="volver" value="<?= e($volver) ?>">
                            <button class="btn btn-sm btn-link text-danger" type="submit">Quitar</button>
                        </form>
                    <?php endif; ?>
                </li>
            <?php endforeach; ?>
        </ul>
    <?php endif; ?>
    <?php if ($puede): ?>
        <form method="post" action="<?= e(url($accion)) ?>" class="card-body row g-2 align-items-end">
            <?= csrf_field() ?>
            <div class="col-sm-4">
                <label class="form-label small fw-semibold" for="del_ficha">Ficha de la persona</label>
                <input class="form-control form-control-sm font-monospace" id="del_ficha" name="ficha" maxlength="20" required value="<?= e($old['ficha'] ?? '') ?>" placeholder="Ej. 123456">
            </div>
            <div class="col-sm-4">
                <label class="form-label small fw-semibold" for="del_hasta">Hasta <span class="fw-normal text-body-secondary">(opcional)</span></label>
                <input type="date" class="form-control form-control-sm" id="del_hasta" name="hasta" min="<?= e(date('Y-m-d')) ?>" value="<?= e($old['hasta'] ?? '') ?>">
            </div>
            <div class="col-sm-4">
                <div class="form-check mb-1">
                    <input class="form-check-input" type="checkbox" id="del_todos" name="ver_todos" value="1" checked>
                    <label class="form-check-label small" for="del_todos">Ve y comenta todos los tickets</label>
                </div>
                <button class="btn btn-sm btn-primary w-100" type="submit"><i class="bi bi-person-plus me-1"></i>Autorizar</button>
            </div>
            <div class="col-12 form-text">Debe tener su cuenta activada. No podrá ver llaves de BitLocker ni otros datos personales.</div>
        </form>
    <?php endif; ?>
</div>
