<?php
/**
 * @var array<string, mixed> $cuenta
 * @var list<array{id: int, etiqueta: string}> $ubicaciones
 * @var int $minPassword
 * @var array<string, string> $old
 */
$sel = (int) ($old['ubicacion_habitual_id'] ?? $cuenta['ubicacion_habitual_id'] ?? 0);
?>
<h1 class="h4 mb-3"><i class="bi bi-person-gear me-1"></i>Mi cuenta</h1>

<div class="row g-4">
    <div class="col-lg-7">
        <div class="card border-0 shadow-sm mb-4">
            <div class="card-header bg-white fw-semibold">Datos del GECA</div>
            <dl class="card-body small mb-0 detail-list">
                <dt>Nombre</dt><dd><?= e($cuenta['nombre']) ?></dd>
                <dt>Ficha</dt><dd class="font-monospace"><?= e($cuenta['ficha']) ?></dd>
                <dt>Jefe inmediato</dt><dd><?= e($cuenta['jefe_nombre'] ?? '—') ?></dd>
            </dl>
            <div class="card-footer bg-white small text-body-secondary">Estos datos se actualizan con la carga semanal del GECA.</div>
        </div>

        <form method="post" action="<?= e(url('/cuenta')) ?>" class="card border-0 shadow-sm" novalidate>
            <div class="card-header bg-white fw-semibold">Contacto y ubicación</div>
            <div class="card-body">
                <?= csrf_field() ?>
                <div class="row g-3">
                    <div class="col-sm-7">
                        <label for="email" class="form-label fw-semibold">Correo electrónico</label>
                        <input type="email" class="form-control" id="email" name="email" maxlength="150" required
                               value="<?= e($old['email'] ?? $cuenta['email'] ?? '') ?>" autocomplete="email">
                    </div>
                    <div class="col-sm-5">
                        <label for="telefono_ext" class="form-label fw-semibold">Teléfono / ext.</label>
                        <input type="text" class="form-control" id="telefono_ext" name="telefono_ext" maxlength="30" required inputmode="tel"
                               value="<?= e($old['telefono_ext'] ?? $cuenta['telefono_ext'] ?? '') ?>">
                    </div>
                    <div class="col-12">
                        <label for="ubicacion_habitual_id" class="form-label fw-semibold">Ubicación habitual</label>
                        <input type="search" class="form-control form-control-sm mb-1" placeholder="Buscar inmueble o gerencia…"
                               data-filter-select="#ubicacion_habitual_id" aria-label="Buscar ubicación">
                        <select class="form-select" id="ubicacion_habitual_id" name="ubicacion_habitual_id" size="5" required>
                            <?php foreach ($ubicaciones as $u): ?>
                                <option value="<?= e($u['id']) ?>" <?= $u['id'] === $sel ? 'selected' : '' ?>><?= e($u['etiqueta']) ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                </div>
            </div>
            <div class="card-footer bg-white text-end">
                <button type="submit" class="btn btn-primary"><i class="bi bi-check2 me-1"></i>Guardar</button>
            </div>
        </form>
    </div>

    <div class="col-lg-5">
        <form method="post" action="<?= e(url('/cuenta/password')) ?>" class="card border-0 shadow-sm" id="password" novalidate autocomplete="off">
            <div class="card-header bg-white fw-semibold"><i class="bi bi-key me-1"></i>Cambiar contraseña</div>
            <div class="card-body">
                <?= csrf_field() ?>
                <div class="mb-3">
                    <label for="password_actual" class="form-label fw-semibold">Contraseña actual</label>
                    <input type="password" class="form-control" id="password_actual" name="password_actual" autocomplete="current-password" required>
                </div>
                <div class="mb-2">
                    <label for="password_nueva" class="form-label fw-semibold">Nueva contraseña</label>
                    <div class="input-group">
                        <input type="password" class="form-control" id="password_nueva" name="password" minlength="<?= e($minPassword) ?>" maxlength="128"
                               autocomplete="new-password" required data-strength="#strength" data-min="<?= e($minPassword) ?>">
                        <button class="btn btn-outline-secondary toggle-password" type="button" data-target="password_nueva" aria-label="Mostrar contraseña"><i class="bi bi-eye"></i></button>
                    </div>
                    <div class="progress mt-2" style="height: 5px;" role="progressbar" aria-label="Fortaleza de la contraseña">
                        <div class="progress-bar" id="strength" style="width: 0%"></div>
                    </div>
                    <div class="form-text" id="strength-text">Mínimo <?= e($minPassword) ?> caracteres combinando mayúsculas, minúsculas, números o símbolos.</div>
                </div>
                <div class="mb-1">
                    <label for="password_confirm" class="form-label fw-semibold">Confirmar nueva contraseña</label>
                    <input type="password" class="form-control" id="password_confirm" name="password_confirm" maxlength="128" autocomplete="new-password" required>
                </div>
            </div>
            <div class="card-footer bg-white text-end">
                <button type="submit" class="btn btn-primary">Actualizar contraseña</button>
            </div>
        </form>
    </div>
</div>
