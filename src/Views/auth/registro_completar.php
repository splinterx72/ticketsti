<?php
/**
 * @var array<string, mixed> $pendiente
 * @var list<array<string, mixed>> $catalogo
 * @var bool $hayCatalogo
 * @var array<string, mixed> $sugerida
 * @var list<string> $dominios
 * @var int $minPassword
 * @var array<string, string> $old
 */
$sel = $old !== [] ? [
    'instalacion_id' => $old['habitual_instalacion_id'] ?? 0, 'ubicacion_fisica_id' => $old['habitual_ubicacion_fisica_id'] ?? 0,
    'nivel_id' => $old['habitual_nivel_id'] ?? 0, 'ala' => $old['habitual_ala'] ?? '', 'referencia' => $old['habitual_referencia'] ?? '',
] : $sugerida;
?>
<div class="card shadow border-0">
    <div class="card-body p-4">
        <span class="badge rounded-pill text-bg-primary mb-2">Paso 2 de 2</span>
        <h2 class="h5 mb-3">Confirma tus datos</h2>

        <div class="p-3 rounded bg-body-tertiary border mb-3">
            <div class="small text-body-secondary">Ficha <?= e($pendiente['ficha']) ?></div>
            <div class="fw-semibold fs-5"><?= e($pendiente['nombre']) ?></div>
            <div class="small"><span class="text-body-secondary">Jefe inmediato:</span> <?= e($pendiente['jefe_nombre'] ?? 'No registrado en GECA') ?></div>
        </div>

        <form method="post" action="<?= e(url('/registro/completar')) ?>" novalidate autocomplete="off">
            <?= csrf_field() ?>
            <div class="form-check mb-3">
                <input class="form-check-input" type="checkbox" id="confirmo" name="confirmo" value="1" required>
                <label class="form-check-label small" for="confirmo">
                    Soy <strong><?= e($pendiente['nombre']) ?></strong> y los datos mostrados son correctos.
                </label>
            </div>

            <div class="row g-3">
                <div class="col-sm-7">
                    <label for="email" class="form-label fw-semibold">Correo de la empresa</label>
                    <input type="email" class="form-control" id="email" name="email" value="<?= e($old['email'] ?? '') ?>"
                           maxlength="150" autocomplete="email" required placeholder="nombre@<?= e($dominios[0]) ?>">
                    <div class="form-text">Te enviaremos un enlace para confirmarlo.</div>
                </div>
                <div class="col-sm-5">
                    <label for="telefono_ext" class="form-label fw-semibold">Teléfono / ext.</label>
                    <input type="text" class="form-control" id="telefono_ext" name="telefono_ext" value="<?= e($old['telefono_ext'] ?? '') ?>"
                           maxlength="30" inputmode="tel" required>
                </div>
                <div class="col-12">
                    <span class="form-label fw-semibold d-block">¿Dónde estás actualmente?</span>
                    <?= partial('ubicacion_picker', [
                        'catalogo' => $catalogo, 'prefijo' => 'habitual', 'sel' => $sel, 'requerido' => $hayCatalogo,
                        'refPlaceholder' => 'Ej. cubículo 14, junto a recepción',
                    ]) ?>
                </div>
                <div class="col-sm-6">
                    <label for="password" class="form-label fw-semibold">Contraseña</label>
                    <div class="input-group">
                        <input type="password" class="form-control" id="password" name="password" minlength="<?= e($minPassword) ?>"
                               maxlength="128" autocomplete="new-password" required data-strength="#strength" data-min="<?= e($minPassword) ?>">
                        <button class="btn btn-outline-secondary toggle-password" type="button" data-target="password" aria-label="Mostrar contraseña">
                            <i class="bi bi-eye"></i>
                        </button>
                    </div>
                    <div class="progress mt-2" style="height: 5px;" role="progressbar" aria-label="Fortaleza de la contraseña">
                        <div class="progress-bar" id="strength" style="width: 0%"></div>
                    </div>
                </div>
                <div class="col-sm-6">
                    <label for="password_confirm" class="form-label fw-semibold">Confirmar</label>
                    <input type="password" class="form-control" id="password_confirm" name="password_confirm" maxlength="128"
                           autocomplete="new-password" required>
                </div>
                <div class="col-12">
                    <div class="form-text" id="strength-text">
                        Mínimo <?= e($minPassword) ?> caracteres combinando mayúsculas, minúsculas, números o símbolos.
                    </div>
                </div>
            </div>

            <button type="submit" class="btn btn-primary w-100 mt-4">
                <i class="bi bi-envelope-check me-1"></i>Continuar y verificar mi correo
            </button>
        </form>
    </div>
    <div class="card-footer bg-white small text-center">
        ¿No eres tú? <a href="<?= e(url('/registro')) ?>">Captura otra ficha</a> o contacta a Soporte TI.
    </div>
</div>
