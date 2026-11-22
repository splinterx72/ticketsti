<?php
/**
 * @var array<string, mixed> $done
 * @var bool $installed
 */
$needsManualConfig = !$done['config_written'] && !$installed;
?>
<div class="text-center mb-4">
    <?php if ($needsManualConfig): ?>
        <div class="display-4 text-warning mb-2"><i class="bi bi-exclamation-circle"></i></div>
        <h1 class="h3">Falta un último paso</h1>
        <p class="text-body-secondary mb-0">La base de datos y tu cuenta ya están listas, pero el servidor no permitió crear el archivo de configuración.</p>
    <?php else: ?>
        <div class="display-4 text-success mb-2 installer-success"><i class="bi bi-check-circle"></i></div>
        <h1 class="h3">¡Instalación completada!</h1>
        <p class="text-body-secondary mb-0"><?= e($done['app_name']) ?> está listo para usarse.</p>
    <?php endif; ?>
</div>

<div class="row justify-content-center">
    <div class="col-lg-9">
        <ul class="list-group mb-4">
            <?php foreach ($done['log'] as $entry): ?>
                <li class="list-group-item d-flex gap-2 small">
                    <i class="bi <?= $entry['status'] === 'ok' ? 'bi-check-circle-fill text-success' : 'bi-exclamation-triangle-fill text-warning' ?> mt-1"></i>
                    <span><?= e($entry['message']) ?></span>
                </li>
            <?php endforeach; ?>
        </ul>

        <?php if ($needsManualConfig): ?>
            <div class="card border-warning mb-4">
                <div class="card-body">
                    <h2 class="h6 fw-semibold">Sube el archivo de configuración</h2>
                    <ol class="small mb-3">
                        <li>Descarga <code>config.php</code> con el botón de abajo.</li>
                        <li>En cPanel › Administrador de archivos, entra a la carpeta <code>config/</code> del sistema.</li>
                        <li>Pulsa <em>Cargar</em> y sube el archivo (debe llamarse exactamente <code>config.php</code>).</li>
                        <li>Vuelve aquí y pulsa <em>Ya lo subí</em>.</li>
                    </ol>
                    <div class="d-flex flex-wrap gap-2">
                        <form method="post" action="<?= e(url('/instalacion/config')) ?>">
                            <?= csrf_field() ?>
                            <button type="submit" class="btn btn-warning"><i class="bi bi-download me-1"></i>Descargar config.php</button>
                        </form>
                        <a href="<?= e(url('/instalacion/finalizado')) ?>" class="btn btn-outline-secondary"><i class="bi bi-arrow-clockwise me-1"></i>Ya lo subí</a>
                    </div>
                    <p class="small text-body-secondary mt-3 mb-0">El archivo contiene la contraseña de la base de datos: no lo envíes por correo ni lo compartas.</p>
                </div>
            </div>
        <?php else: ?>
            <div class="card bg-body-tertiary border-0 mb-4">
                <div class="card-body small">
                    <h2 class="h6 fw-semibold mb-3"><i class="bi bi-list-check me-1"></i>Siguientes pasos recomendados</h2>
                    <ol class="mb-0">
                        <li class="mb-1">Inicia sesión con tu ficha <code><?= e($done['ficha']) ?></code> o con <code><?= e($done['email']) ?></code>.</li>
                        <li class="mb-1">Sube el primer archivo semanal en <strong>Carga GECA</strong>.</li>
                        <li class="mb-1">Si aún no tienes SSL, activa AutoSSL en cPanel y vuelve a entrar por <code>https://</code>.</li>
                        <li>Programa en cPanel › <strong>Copias de seguridad</strong> respaldos periódicos de la base de datos.</li>
                    </ol>
                </div>
            </div>
            <div class="text-center">
                <a href="<?= e(url('/')) ?>" class="btn btn-primary btn-lg px-5">Entrar al sistema <i class="bi bi-arrow-right ms-1"></i></a>
            </div>
        <?php endif; ?>
    </div>
</div>
