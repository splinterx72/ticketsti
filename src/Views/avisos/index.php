<?php
/** @var list<array<string, mixed>> $avisos */
$imp = \App\Services\AvisoService::IMPORTANCIAS;
$ahora = date('Y-m-d H:i:s');
?>
<div class="d-flex flex-wrap justify-content-between align-items-center gap-2 mb-3">
    <h1 class="h4 mb-0"><i class="bi bi-megaphone me-1"></i>Avisos</h1>
    <a href="<?= e(url('/supervisor/avisos/nuevo')) ?>" class="btn btn-primary"><i class="bi bi-plus-lg me-1"></i>Nuevo aviso</a>
</div>

<?php if ($avisos === []): ?>
    <div class="card border-0 shadow-sm"><div class="card-body text-center py-5 text-body-secondary">
        <i class="bi bi-megaphone display-6"></i>
        <p class="mt-3 mb-0">Aún no has publicado avisos. Aparecen en la página de inicio durante el periodo que indiques.</p>
    </div></div>
<?php else: ?>
    <div class="list-group shadow-sm">
        <?php foreach ($avisos as $a):
            [$label, $color, $icon] = $imp[$a['importancia']];
            $estado = $a['termina_en'] <= $ahora ? ['Terminado', 'secondary'] : ($a['inicia_en'] > $ahora ? ['Programado', 'info'] : ['Publicado', 'success']);
            ?>
            <div class="list-group-item py-3">
                <div class="d-flex flex-wrap justify-content-between gap-2">
                    <div class="me-auto">
                        <div class="d-flex flex-wrap gap-1 mb-1">
                            <span class="badge text-bg-<?= e($color) ?>"><i class="bi <?= e($icon) ?> me-1"></i><?= e($label) ?></span>
                            <span class="badge text-bg-<?= e($estado[1]) ?>"><?= e($estado[0]) ?></span>
                        </div>
                        <div class="fw-semibold"><?= e($a['titulo']) ?></div>
                        <div class="small text-body-secondary">
                            <?= e(fecha_mx($a['inicia_en'])) ?> → <?= e(fecha_mx($a['termina_en'])) ?> ·
                            <?= $a['alcance'] === 'todos' ? 'Para todos' : 'Para: ' . e($a['instalaciones'] ?? '') ?> ·
                            <?= e($a['autor'] ?? '—') ?>
                        </div>
                    </div>
                    <div class="d-flex gap-2 align-items-start">
                        <a class="btn btn-sm btn-outline-secondary" href="<?= e(url('/supervisor/avisos/' . (int) $a['id'] . '/editar')) ?>"><i class="bi bi-pencil"></i><span class="visually-hidden">Editar</span></a>
                        <?php if ($a['termina_en'] > $ahora): ?>
                            <form method="post" action="<?= e(url('/supervisor/avisos/' . (int) $a['id'] . '/finalizar')) ?>" class="m-0"
                                  data-confirm="¿Retirar ya este aviso del inicio?">
                                <?= csrf_field() ?>
                                <button class="btn btn-sm btn-outline-danger" type="submit">Retirar</button>
                            </form>
                        <?php endif; ?>
                    </div>
                </div>
            </div>
        <?php endforeach; ?>
    </div>
<?php endif; ?>
