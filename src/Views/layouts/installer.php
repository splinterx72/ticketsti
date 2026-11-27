<?php
/**
 * Layout del asistente de instalación.
 *
 * @var string $content
 * @var string $appName
 * @var string|null $title
 * @var int $step     paso actual (6 = finalizado)
 * @var int|null $maxStep  paso más avanzado disponible
 * @var list<array{type: string, message: string}> $flash
 */
$steps = [
    1 => ['Requisitos', 'bi-clipboard-check', '/instalacion'],
    2 => ['Base de datos', 'bi-database', '/instalacion/base-datos'],
    3 => ['Sitio', 'bi-globe2', '/instalacion/sitio'],
    4 => ['Administrador', 'bi-person-badge', '/instalacion/administrador'],
    5 => ['Instalar', 'bi-rocket-takeoff', '/instalacion/confirmar'],
];
$maxStep = $maxStep ?? $step;
?>
<!doctype html>
<html lang="es-MX">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="robots" content="noindex, nofollow">
    <title><?= e(($title ?? 'Instalación') . ' · Instalación de ' . $appName) ?></title>
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css"
          integrity="sha384-QWTKZyjpPEjISv5WaRU9OFeRpok6YctnYmDr5pNlyT2bRjXh0JMhjY6hW+ALEwIH" crossorigin="anonymous">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css">
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link rel="stylesheet" href="https://fonts.googleapis.com/css2?family=Roboto+Condensed:wght@500;700&family=Roboto:wght@400;500;700&display=swap">
    <link rel="stylesheet" href="<?= e(asset('css/app.css')) ?>">
    <link rel="stylesheet" href="<?= e(asset('css/theme.css')) ?>">
    <link rel="stylesheet" href="<?= e(asset('css/installer.css')) ?>">
</head>
<body class="installer-bg">
<div class="container py-4 py-lg-5">
    <div class="row justify-content-center">
        <div class="col-12 col-xl-10">

            <header class="d-flex align-items-center gap-3 mb-4 text-white">
                <div class="installer-logo"><i class="bi bi-headset"></i></div>
                <div>
                    <div class="small text-uppercase opacity-75 fw-semibold letter-wide">Asistente de instalación</div>
                    <div class="h4 mb-0 fw-semibold"><?= e($appName) ?></div>
                </div>
            </header>

            <div class="card border-0 shadow-lg installer-card">
                <?php if ($step <= 5): ?>
                    <nav class="installer-steps" aria-label="Pasos de la instalación">
                        <ol class="list-unstyled d-flex mb-0">
                            <?php foreach ($steps as $n => [$label, $icon, $path]):
                                $state = $n < $step ? 'done' : ($n === $step ? 'current' : 'todo');
                                $clickable = $n !== $step && $n <= $maxStep;
                                ?>
                                <li class="installer-step is-<?= e($state) ?>" <?= $n === $step ? 'aria-current="step"' : '' ?>>
                                    <span class="installer-step-dot">
                                        <?php if ($state === 'done'): ?><i class="bi bi-check-lg"></i><?php else: ?><?= e($n) ?><?php endif; ?>
                                    </span>
                                    <span class="installer-step-label d-none d-md-block"><?= e($label) ?></span>
                                    <?php if ($clickable): ?>
                                        <a href="<?= e(url($path)) ?>" class="stretched-link"><span class="visually-hidden">Ir al paso <?= e($n) ?>: <?= e($label) ?></span></a>
                                    <?php endif; ?>
                                </li>
                            <?php endforeach; ?>
                        </ol>
                    </nav>
                <?php endif; ?>

                <div class="card-body p-3 p-sm-4 p-lg-5">
                    <?= partial('flash', ['flash' => $flash ?? []]) ?>

                    <?= $content ?>
                </div>
            </div>

            <p class="text-center text-white-50 small mt-4 mb-0">
                PHP <?= e(PHP_VERSION) ?> · Hosting compartido cPanel
            </p>
        </div>
    </div>
</div>
<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"
        integrity="sha384-YvpcrYf0tY3lHB60NNkmXc5s9fDVZLESaAA55NDzOxhy9GkcIdslK1eN7N6jIeHz" crossorigin="anonymous"></script>
<script src="<?= e(asset('js/app.js')) ?>"></script>
<script src="<?= e(asset('js/installer.js')) ?>"></script>
</body>
</html>
