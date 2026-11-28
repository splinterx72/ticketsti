<?php
/**
 * @var bool $disponible
 * @var list<array{id: int, nombre: string, descripcion: ?string, orden: int, activo: int}> $categorias
 */
?>
<div class="d-flex justify-content-between align-items-center mb-3">
    <h1 class="h4 mb-0"><i class="bi bi-tags me-1"></i>Categorías de la acción</h1>
</div>
<?php if (!$disponible): ?>
    <div class="alert alert-warning small">Falta importar la migración <code>2026-12-03_categorias_tipos_ti.sql</code>. Mientras tanto, los tickets se crean sin categoría.</div>
<?php else: ?>
<p class="small text-body-secondary">Quien levanta un ticket elige la categoría y ve su descripción como ayuda. TI puede corregirla desde el ticket. Una categoría inactiva ya no se ofrece, pero los tickets que la tienen la conservan.</p>
<div class="card border-0 shadow-sm mb-4">
    <ul class="list-group list-group-flush">
        <?php foreach ($categorias as $c): ?>
            <li class="list-group-item <?= $c['activo'] === 1 ? '' : 'bg-body-tertiary' ?>">
                <form method="post" action="<?= e(url('/ti/categorias/' . $c['id'])) ?>" class="row g-2 align-items-end m-0">
                    <?= csrf_field() ?>
                    <div class="col-2 col-md-1">
                        <label class="form-label small mb-1" for="ord<?= e($c['id']) ?>">Orden</label>
                        <input type="number" class="form-control form-control-sm" id="ord<?= e($c['id']) ?>" name="orden" value="<?= e($c['orden']) ?>" min="0" max="999">
                    </div>
                    <div class="col-10 col-md-4">
                        <label class="form-label small mb-1" for="nom<?= e($c['id']) ?>">Categoría</label>
                        <input class="form-control form-control-sm" id="nom<?= e($c['id']) ?>" name="nombre" maxlength="120" required value="<?= e($c['nombre']) ?>">
                    </div>
                    <div class="col-md-5">
                        <label class="form-label small mb-1" for="des<?= e($c['id']) ?>">Qué abarca</label>
                        <textarea class="form-control form-control-sm" id="des<?= e($c['id']) ?>" name="descripcion" rows="2" maxlength="500"><?= e($c['descripcion'] ?? '') ?></textarea>
                    </div>
                    <div class="col-md-2 d-flex flex-column gap-1">
                        <div class="form-check form-switch small">
                            <input class="form-check-input" type="checkbox" role="switch" id="act<?= e($c['id']) ?>" name="activo" value="1" <?= $c['activo'] === 1 ? 'checked' : '' ?>>
                            <label class="form-check-label" for="act<?= e($c['id']) ?>">Activa</label>
                        </div>
                        <button class="btn btn-sm btn-outline-primary" type="submit">Guardar</button>
                    </div>
                </form>
            </li>
        <?php endforeach; ?>
    </ul>
</div>
<form method="post" action="<?= e(url('/ti/categorias')) ?>" class="card border-0 shadow-sm">
    <div class="card-header bg-white fw-semibold"><i class="bi bi-plus-lg me-1"></i>Nueva categoría</div>
    <div class="card-body row g-2 align-items-end">
        <?= csrf_field() ?>
        <div class="col-2 col-md-1"><label class="form-label small mb-1" for="ordN">Orden</label><input type="number" class="form-control form-control-sm" id="ordN" name="orden" value="<?= e(count($categorias) + 1) ?>" min="0" max="999"></div>
        <div class="col-10 col-md-4"><label class="form-label small mb-1" for="nomN">Categoría</label><input class="form-control form-control-sm" id="nomN" name="nombre" maxlength="120" required></div>
        <div class="col-md-5"><label class="form-label small mb-1" for="desN">Qué abarca</label><textarea class="form-control form-control-sm" id="desN" name="descripcion" rows="2" maxlength="500"></textarea></div>
        <div class="col-md-2 d-grid"><button class="btn btn-sm btn-primary" type="submit">Crear</button></div>
    </div>
</form>
<?php endif; ?>
