<?php
/**
 * @var array{estado: string, instalacion: int, tipo: string, q: string} $filtros
 * @var list<string> $tipos
 * @var list<array{id: int, nombre: string}> $instalaciones
 * @var string $accion
 * @var bool $conEstado
 */
?>
<form method="get" action="<?= e(url($accion)) ?>" class="card border-0 shadow-sm mb-3">
    <div class="card-body row g-2 align-items-end">
        <?php if ($conEstado): ?>
            <div class="col-sm-6 col-lg-3">
                <label class="form-label small mb-1" for="f_estado">Estado</label>
                <select class="form-select form-select-sm" id="f_estado" name="estado">
                    <option value="abiertos">En TI y pendientes en GECA</option>
                    <?php foreach (\App\Services\ResguardoService::ESTADOS as $k => [$label]): ?><option value="<?= e($k) ?>" <?= $filtros['estado'] === $k ? 'selected' : '' ?>><?= e($label) ?></option><?php endforeach; ?>
                    <option value="todos" <?= $filtros['estado'] === 'todos' ? 'selected' : '' ?>>Todos</option>
                </select>
            </div>
        <?php endif; ?>
        <div class="col-sm-6 col-lg-3">
            <label class="form-label small mb-1" for="f_inst">Instalación</label>
            <select class="form-select form-select-sm" id="f_inst" name="inst">
                <option value="0">Todas</option>
                <?php foreach ($instalaciones as $i): ?><option value="<?= e($i['id']) ?>" <?= $filtros['instalacion'] === (int) $i['id'] ? 'selected' : '' ?>><?= e($i['nombre']) ?></option><?php endforeach; ?>
            </select>
        </div>
        <div class="col-sm-6 col-lg-2">
            <label class="form-label small mb-1" for="f_tipo">Tipo</label>
            <select class="form-select form-select-sm" id="f_tipo" name="tipo">
                <option value="">Todos</option>
                <?php foreach ($tipos as $t): ?><option value="<?= e($t) ?>" <?= $filtros['tipo'] === $t ? 'selected' : '' ?>><?= e(tipo_equipo($t)) ?></option><?php endforeach; ?>
            </select>
        </div>
        <div class="col-sm-6 col-lg-3">
            <label class="form-label small mb-1" for="f_q">Serie, marca, ficha o folio</label>
            <input class="form-control form-control-sm" id="f_q" name="q" maxlength="60" value="<?= e($filtros['q']) ?>">
        </div>
        <div class="col-lg-1 d-grid"><button class="btn btn-sm btn-primary" type="submit">Filtrar</button></div>
    </div>
</form>
