<?php
/**
 * @var array{name: string, base_url: string, timezone: string, force_https: bool} $site
 * @var array<string, string> $timezones
 * @var bool $isHttps
 */
?>
<h1 class="h4 mb-1">Datos del sitio</h1>
<p class="text-body-secondary">Así se identificará el sistema y desde dónde se accederá.</p>

<form method="post" action="<?= e(url('/instalacion/sitio')) ?>" novalidate>
    <?= csrf_field() ?>
    <div class="row g-3">
        <div class="col-md-6">
            <label for="name" class="form-label fw-semibold">Nombre del sistema</label>
            <input type="text" class="form-control" id="name" name="name" value="<?= e($site['name']) ?>" maxlength="80" required>
            <div class="form-text">Aparece en la barra superior y en el título de las páginas.</div>
        </div>
        <div class="col-md-6">
            <label for="timezone" class="form-label fw-semibold">Zona horaria</label>
            <select class="form-select" id="timezone" name="timezone" required>
                <?php foreach ($timezones as $tz => $label): ?>
                    <option value="<?= e($tz) ?>" <?= $tz === $site['timezone'] ? 'selected' : '' ?>><?= e($label) ?></option>
                <?php endforeach; ?>
            </select>
        </div>
        <div class="col-12">
            <label for="base_url" class="form-label fw-semibold">URL del sistema</label>
            <input type="url" class="form-control font-monospace" id="base_url" name="base_url" value="<?= e($site['base_url']) ?>" required spellcheck="false">
            <div class="form-text">Detectada automáticamente. Sin diagonal al final.</div>
        </div>
        <div class="col-12">
            <div class="form-check form-switch p-3 ps-5 rounded border bg-body-tertiary">
                <input class="form-check-input" type="checkbox" role="switch" id="force_https" name="force_https" value="1"
                    <?= $site['force_https'] && $isHttps ? 'checked' : '' ?> <?= $isHttps ? '' : 'disabled' ?>>
                <label class="form-check-label fw-semibold" for="force_https">Forzar conexión segura (HTTPS)</label>
                <div class="small text-body-secondary">
                    <?php if ($isHttps): ?>
                        Redirige automáticamente cualquier acceso por http:// y marca las cookies de sesión como seguras. Recomendado.
                    <?php else: ?>
                        Disponible solo si instalas entrando por <strong>https://</strong>. Activa AutoSSL en cPanel › SSL/TLS Status y recarga esta página con https.
                    <?php endif; ?>
                </div>
            </div>
        </div>
    </div>

    <div class="d-flex justify-content-between align-items-center mt-4">
        <a href="<?= e(url('/instalacion/base-datos')) ?>" class="btn btn-link px-0"><i class="bi bi-arrow-left me-1"></i>Atrás</a>
        <button type="submit" class="btn btn-primary btn-lg">Continuar <i class="bi bi-arrow-right ms-1"></i></button>
    </div>
</form>
