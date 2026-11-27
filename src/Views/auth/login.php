<?php
/**
 * Pantalla de entrada: carrusel de noticias (2/3) y acceso (1/3).
 *
 * @var array<string, string> $old
 * @var list<array<string, mixed>> $avisos  avisos "para todos" vigentes
 * @var string $appName
 * @var string|null $mesaCorreo
 * @var list<array{type: string, message: string}> $flash
 */
$imp = \App\Services\AvisoService::IMPORTANCIAS;
$slides = [];
foreach ($avisos ?? [] as $a) {
    $slides[] = [
        'titulo'  => (string) $a['titulo'],
        'texto'   => (string) $a['mensaje'],
        'imagen'  => !empty($a['imagen']) ? url('/avisos/' . (int) $a['id'] . '/imagen') . '?v=' . substr(md5((string) $a['imagen']), 0, 8) : null,
        'etiqueta'=> $imp[$a['importancia']][0],
        'icono'   => $imp[$a['importancia']][2],
        'tono'    => (string) $a['importancia'],
        'pie'     => 'Publicado el ' . fecha_mx((string) $a['inicia_en'], false) . (!empty($a['autor']) ? ' · ' . $a['autor'] : ''),
    ];
}
// Sin noticias vigentes: el carrusel presenta el sistema.
if ($slides === []) {
    $slides = [
        ['titulo' => 'Tu soporte técnico, en un solo lugar', 'texto' => 'Reporta fallas de tu computadora, monitores o impresoras y sigue la atención de TI paso a paso, sin llamadas ni correos sueltos.',
         'imagen' => null, 'etiqueta' => 'Mesa de Ayuda TI', 'icono' => 'bi-headset', 'tono' => 'marca', 'pie' => 'Atención por instalación, con folio y seguimiento'],
        ['titulo' => 'Tus equipos, como están en el GECA', 'texto' => 'Al levantar un ticket verás los equipos de tu resguardo con sus monitores. Si tu equipo cambió de lugar, el sistema avisa a Control de Resguardos.',
         'imagen' => null, 'etiqueta' => 'Resguardos GECA', 'icono' => 'bi-pc-display', 'tono' => 'geca', 'pie' => 'Inventario actualizado cada semana'],
        ['titulo' => 'Garantías y avisos al día', 'texto' => 'TI identifica si la falla está cubierta por garantía y te mantiene informado por correo de cada cambio en tu ticket.',
         'imagen' => null, 'etiqueta' => 'Seguimiento', 'icono' => 'bi-shield-check', 'tono' => 'garantia', 'pie' => 'Notificaciones a tu correo de la empresa'],
    ];
}
$total = count($slides);
?>
<div class="login-shell">

    <!-- ============ Noticias (2/3) ============ -->
    <section class="news" aria-label="Noticias y avisos" data-news>
        <div class="news-backdrop" aria-hidden="true">
            <div class="news-backdrop-layer is-visible"></div>
            <div class="news-backdrop-layer"></div>
        </div>

        <div class="news-inner">
            <header class="news-head">
                <div class="news-brand">
                    <span class="news-brand-mark"><i class="bi bi-headset"></i></span>
                    <span>
                        <span class="news-brand-name"><?= e($appName) ?></span>
                        <span class="news-brand-sub">Noticias y avisos</span>
                    </span>
                </div>
                <div class="news-counter" aria-live="polite">
                    <span data-news-current>01</span><span class="opacity-50"> / <?= e(str_pad((string) $total, 2, '0', STR_PAD_LEFT)) ?></span>
                </div>
            </header>

            <div id="newsCarousel" class="carousel slide carousel-fade news-frame" data-bs-ride="carousel" data-bs-interval="7000"
                 data-bs-pause="hover" aria-roledescription="carrusel">
                <div class="carousel-inner">
                    <?php foreach ($slides as $i => $s): ?>
                        <article class="carousel-item <?= $i === 0 ? 'active' : '' ?> news-slide news-tono-<?= e($s['tono']) ?>"
                                 aria-roledescription="diapositiva" aria-label="<?= e(($i + 1) . ' de ' . $total) ?>"
                                 data-bg="<?= e($s['imagen'] ?? '') ?>">
                            <?php if ($s['imagen'] !== null): ?>
                                <img class="news-img" src="<?= e($s['imagen']) ?>" alt="" <?= $i === 0 ? 'fetchpriority="high"' : 'loading="lazy"' ?> decoding="async">
                            <?php else: ?>
                                <div class="news-art" aria-hidden="true">
                                    <span class="news-art-ring"></span>
                                    <span class="news-art-ring news-art-ring-2"></span>
                                    <i class="bi <?= e($s['icono']) ?> news-art-icon"></i>
                                </div>
                            <?php endif; ?>
                            <div class="news-caption">
                                <span class="news-chip news-chip-<?= e($s['tono']) ?>"><i class="bi <?= e($s['icono']) ?>"></i><?= e($s['etiqueta']) ?></span>
                                <h2 class="news-title"><?= e($s['titulo']) ?></h2>
                                <p class="news-text"><?= e($s['texto']) ?></p>
                                <p class="news-meta"><?= e($s['pie']) ?></p>
                            </div>
                        </article>
                    <?php endforeach; ?>
                </div>
            </div>

            <?php if ($total > 1): ?>
                <nav class="news-controls" aria-label="Controles del carrusel">
                    <div class="news-dots">
                        <?php foreach ($slides as $i => $s): ?>
                            <button type="button" class="news-dot <?= $i === 0 ? 'active' : '' ?>" data-bs-target="#newsCarousel" data-bs-slide-to="<?= e($i) ?>"
                                    aria-label="<?= e('Ir a: ' . $s['titulo']) ?>" <?= $i === 0 ? 'aria-current="true"' : '' ?>><span></span></button>
                        <?php endforeach; ?>
                    </div>
                    <div class="news-buttons">
                        <button type="button" class="news-btn" data-bs-target="#newsCarousel" data-bs-slide="prev" aria-label="Anterior"><i class="bi bi-chevron-left"></i></button>
                        <button type="button" class="news-btn" data-news-pause aria-label="Pausar" aria-pressed="false"><i class="bi bi-pause-fill"></i></button>
                        <button type="button" class="news-btn" data-bs-target="#newsCarousel" data-bs-slide="next" aria-label="Siguiente"><i class="bi bi-chevron-right"></i></button>
                    </div>
                </nav>
            <?php endif; ?>
        </div>
    </section>

    <!-- ============ Acceso (1/3) ============ -->
    <main class="access">
        <div class="access-inner">
            <div class="access-brand">
                <span class="access-mark"><i class="bi bi-headset"></i></span>
                <div>
                    <div class="access-name"><?= e($appName) ?></div>
                    <div class="access-tag">Soporte técnico y control de resguardos GECA</div>
                </div>
            </div>

            <h1 class="access-title">Bienvenido</h1>
            <p class="access-lead">
                Aquí reportas fallas de tu equipo de cómputo y das seguimiento a la atención de TI.
                Tus equipos aparecen tal como están en tu resguardo del <strong>GECA</strong>.
            </p>

            <?= partial('flash', ['flash' => $flash ?? []]) ?>

            <form method="post" action="<?= e(url('/login')) ?>" class="access-form" novalidate>
                <?= csrf_field() ?>
                <div class="form-floating mb-3">
                    <input type="text" class="form-control" id="identificador" name="identificador" placeholder="Ficha o correo"
                           value="<?= e($old['identificador'] ?? '') ?>" autocomplete="username" required autofocus>
                    <label for="identificador"><i class="bi bi-person-badge me-1"></i>Ficha o correo de la empresa</label>
                </div>
                <div class="form-floating mb-3 access-password">
                    <input type="password" class="form-control" id="password" name="password" placeholder="Contraseña"
                           autocomplete="current-password" required>
                    <label for="password"><i class="bi bi-lock me-1"></i>Contraseña</label>
                    <button type="button" class="access-eye" data-toggle-password="#password" aria-label="Mostrar contraseña"><i class="bi bi-eye"></i></button>
                </div>
                <button type="submit" class="btn btn-primary btn-lg w-100 access-submit">
                    Entrar <i class="bi bi-arrow-right ms-1"></i>
                </button>
            </form>

            <div class="access-first">
                <div>
                    <div class="fw-semibold">¿Es tu primera vez?</div>
                    <div class="small text-body-secondary">Activa tu cuenta con tu número de ficha.</div>
                </div>
                <a href="<?= e(url('/registro')) ?>" class="btn btn-outline-primary">Activar cuenta</a>
            </div>

            <section class="access-steps" aria-labelledby="comoFunciona">
                <h2 id="comoFunciona" class="access-steps-title">¿Cómo funciona?</h2>
                <ol class="list-unstyled mb-0">
                    <li>
                        <span class="access-step-num">1</span>
                        <div><strong>Activa tu cuenta</strong> con tu ficha, tu correo de la empresa, tu extensión y el lugar donde trabajas. Confirmas tu correo con un enlace.</div>
                    </li>
                    <li>
                        <span class="access-step-num">2</span>
                        <div><strong>Levanta tu ticket</strong>: elige tu equipo o monitor, describe la falla e indica dónde estás para que el técnico llegue directo.</div>
                    </li>
                    <li>
                        <span class="access-step-num">3</span>
                        <div><strong>Da seguimiento</strong>: TI de tu instalación lo atiende y te avisa por correo de cada avance hasta cerrarlo.</div>
                    </li>
                </ol>
            </section>

            <footer class="access-foot">
                <i class="bi bi-life-preserver me-1"></i>¿Problemas para entrar?
                <?php if (!empty($mesaCorreo)): ?>
                    Escribe a <a href="mailto:<?= e($mesaCorreo) ?>"><?= e($mesaCorreo) ?></a>.
                <?php else: ?>
                    Acude con el personal de TI de tu instalación.
                <?php endif; ?>
            </footer>
        </div>
    </main>
</div>
