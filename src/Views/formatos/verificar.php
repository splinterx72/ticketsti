<?php
/**
 * @var string $folio
 * @var string $codigo
 * @var bool $buscado
 * @var array<string, mixed>|null $f
 * @var array<string, array<string, mixed>> $firmas
 */
?>
<h1 class="h4 mb-3"><i class="bi bi-patch-check me-1"></i>Verificar un formato</h1>
<form method="get" action="<?= e(url('/formatos/verificar')) ?>" class="card border-0 shadow-sm mb-4" style="max-width: 40rem;">
    <div class="card-body row g-2 align-items-end">
        <div class="col-sm-6"><label class="form-label small fw-semibold" for="folio">Folio</label><input class="form-control font-monospace" id="folio" name="folio" value="<?= e($folio) ?>" placeholder="FF-2026-000000" required></div>
        <div class="col-sm-4"><label class="form-label small fw-semibold" for="codigo">Código de verificación</label><input class="form-control font-monospace" id="codigo" name="codigo" value="<?= e($codigo) ?>" maxlength="12" required></div>
        <div class="col-sm-2"><button class="btn btn-primary w-100" type="submit">Verificar</button></div>
        <div class="col-12 form-text">Ambos vienen al pie del documento impreso.</div>
    </div>
</form>
<?php if ($buscado): ?>
    <?php if ($f === null): ?>
        <div class="msg msg-error"><span class="msg-ico"><i class="bi bi-x-octagon"></i></span><div class="msg-cuerpo"><div class="msg-titulo">No coincide</div><div class="msg-texto">No existe un formato con ese folio y código. El documento podría no ser auténtico.</div></div></div>
    <?php else: ?>
        <div class="msg msg-exito"><span class="msg-ico"><i class="bi bi-patch-check"></i></span><div class="msg-cuerpo">
            <div class="msg-titulo">Documento auténtico: <?= e($f['folio']) ?></div>
            <div class="msg-texto"><?= e($f['titulo']) ?> · versión <?= e($f['anio']) ?> · emitido el <?= e(fecha_mx((string) $f['creado_en'])) ?> · estado: <strong><?= e(str_replace('_', ' ', (string) $f['estado'])) ?></strong>
                <?php foreach ($firmas as $fi): ?><br>Firmó <?= e($fi['nombre']) ?> (F-<?= e($fi['ficha']) ?>) el <?= e(fecha_mx((string) $fi['firmado_en'])) ?><?php endforeach; ?></div>
        </div></div>
    <?php endif; ?>
<?php endif; ?>
