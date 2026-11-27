<?php
/**
 * @var list<array<string, mixed>> $versiones
 * @var array<string, array<string, mixed>> $vigentes
 */
$anio = (int) date('Y');
?>
<div class="d-flex justify-content-between align-items-center mb-3">
    <h1 class="h4 mb-0"><i class="bi bi-file-earmark-text me-1"></i>Formatos</h1>
</div>
<div class="alert alert-info small">
    <i class="bi bi-info-circle me-1"></i>
    Cada año sube los logos y ajusta los textos según las políticas vigentes. Si cambias una versión que ya tiene documentos firmados,
    el sistema crea una versión nueva: lo firmado se conserva tal cual.
</div>
<?php foreach ($vigentes as $clave => $v): ?>
    <div class="card border-0 shadow-sm mb-4">
        <div class="card-header bg-white d-flex flex-wrap justify-content-between align-items-center gap-2">
            <span class="fw-semibold"><?= e($v['titulo']) ?></span>
            <span class="small">En uso: <strong><?= (int) $v['anio'] ?> · versión <?= (int) $v['version'] ?></strong></span>
        </div>
        <div class="card-body">
            <?php if ((int) $v['anio'] < $anio): ?>
                <div class="msg msg-advertencia mb-3"><span class="msg-ico"><i class="bi bi-calendar-event"></i></span><div class="msg-cuerpo"><div class="msg-texto">Aún no hay versión <?= $anio ?>; se está usando la de <?= (int) $v['anio'] ?>. Prepárala para cambiar los logos del año.</div></div></div>
            <?php endif; ?>
            <div class="d-flex flex-wrap gap-2">
                <a class="btn btn-primary btn-sm" href="<?= e(url('/ti/formatos/' . (int) $v['id'] . '/editar')) ?>"><i class="bi bi-pencil me-1"></i>Editar logos y textos</a>
                <?php foreach ([$anio, $anio + 1] as $a): if ((int) $v['anio'] >= $a) { continue; } ?>
                    <form method="post" action="<?= e(url('/ti/formatos/anio')) ?>" class="m-0">
                        <?= csrf_field() ?>
                        <input type="hidden" name="clave" value="<?= e($clave) ?>"><input type="hidden" name="anio" value="<?= $a ?>">
                        <button class="btn btn-outline-primary btn-sm" type="submit"><i class="bi bi-calendar-plus me-1"></i>Preparar versión <?= $a ?></button>
                    </form>
                <?php endforeach; ?>
            </div>
        </div>
        <div class="table-responsive">
            <table class="table table-sm align-middle mb-0">
                <thead class="table-light"><tr><th>Año</th><th>Versión</th><th>Logos</th><th class="text-end">Documentos</th><th></th></tr></thead>
                <tbody>
                <?php foreach (array_filter($versiones, static fn (array $x): bool => $x['clave'] === $clave) as $x): ?>
                    <tr>
                        <td><?= (int) $x['anio'] ?></td>
                        <td><?= (int) $x['version'] ?><?= (int) $x['id'] === (int) $v['id'] ? ' <span class="badge text-bg-success">en uso</span>' : '' ?></td>
                        <td class="small"><?= count(array_filter([$x['logo_izq'], $x['logo_der'], $x['logo_pie']])) ?> de 3</td>
                        <td class="text-end"><?= (int) $x['usados'] ?></td>
                        <td class="text-end"><a class="btn btn-link btn-sm" href="<?= e(url('/ti/formatos/' . (int) $x['id'] . '/editar')) ?>">Abrir</a></td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </div>
<?php endforeach; ?>
<p class="small text-body-secondary">¿Quieres verificar un documento impreso? <a href="<?= e(url('/formatos/verificar')) ?>">Verificar formato</a></p>
