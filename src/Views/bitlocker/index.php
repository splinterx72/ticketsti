<?php
/**
 * @var bool $esTi
 * @var string $q
 * @var list<array<string, mixed>>|null $resultados  null = sin búsqueda
 * @var list<array<string, mixed>> $misEquipos
 */
$equipo = static fn (array $e): string => trim(tipo_equipo($e['tipo_componente'] ?? null) . ' · ' . trim(($e['marca'] ?? '') . ' ' . ($e['modelo'] ?? '')), ' ·');
?>
<div class="d-flex justify-content-between align-items-center mb-3">
    <h1 class="h4 mb-0"><i class="bi bi-key me-1"></i>BitLocker</h1>
</div>

<?php if ($esTi): ?>
    <form method="get" action="<?= e(url('/bitlocker')) ?>" class="card border-0 shadow-sm mb-4">
        <div class="card-body">
            <label class="form-label fw-semibold" for="q">Buscar equipo</label>
            <div class="input-group">
                <span class="input-group-text"><i class="bi bi-search"></i></span>
                <input class="form-control font-monospace" id="q" name="q" value="<?= e($q) ?>" maxlength="60" autofocus autocomplete="off"
                       placeholder="Identificador (ej. 1A2B3C4D), serie o ficha">
                <button class="btn btn-primary" type="submit">Buscar</button>
            </div>
            <div class="form-text">En la pantalla azul de recuperación el usuario ve "Identificador de clave de recuperación"; basta con que te dicte los primeros 8 caracteres. Solo aparecen equipos de tus instalaciones.</div>
        </div>
    </form>

    <?php if ($resultados !== null): ?>
        <div class="card border-0 shadow-sm mb-4">
            <div class="card-header bg-white fw-semibold">Resultados (<?= count($resultados) ?>)</div>
            <?php if ($resultados === []): ?>
                <div class="card-body small text-body-secondary">No se encontró ningún equipo de tus instalaciones con «<?= e($q) ?>». Revisa el identificador o busca por serie.</div>
            <?php else: ?>
                <div class="list-group list-group-flush">
                    <?php foreach ($resultados as $r): ?>
                        <a class="list-group-item list-group-item-action d-flex flex-wrap justify-content-between gap-2" href="<?= e(url('/equipos/' . (int) $r['id'] . '/bitlocker')) ?>">
                            <span>
                                <span class="font-monospace fw-semibold"><?= e($r['serie']) ?></span>
                                <span class="small text-body-secondary"><?= e($equipo($r)) ?></span>
                                <div class="small"><?= e($r['resguardante'] ?? 'Sin resguardante') ?><?php if ($r['ficha']): ?> <span class="font-monospace text-body-secondary">(<?= e($r['ficha']) ?>)</span><?php endif; ?></div>
                            </span>
                            <span class="small text-end">
                                <?php if ($r['identificadores'] === []): ?>
                                    <span class="text-body-secondary">Sin llaves registradas</span>
                                <?php else: foreach ($r['identificadores'] as $i): ?>
                                    <div class="font-monospace"><?= e($i) ?></div>
                                <?php endforeach; endif; ?>
                            </span>
                        </a>
                    <?php endforeach; ?>
                </div>
            <?php endif; ?>
        </div>
    <?php endif; ?>
<?php endif; ?>

<div class="card border-0 shadow-sm">
    <div class="card-header bg-white fw-semibold">Mis equipos</div>
    <?php if ($misEquipos === []): ?>
        <div class="card-body small text-body-secondary">No tienes equipos en resguardo en el GECA.</div>
    <?php else: ?>
        <div class="list-group list-group-flush">
            <?php foreach ($misEquipos as $m): ?>
                <a class="list-group-item list-group-item-action d-flex justify-content-between align-items-center gap-2" href="<?= e(url('/equipos/' . (int) $m['id'] . '/bitlocker')) ?>">
                    <span><span class="font-monospace fw-semibold"><?= e($m['serie']) ?></span> <span class="small text-body-secondary"><?= e($equipo($m)) ?></span></span>
                    <span class="small <?= (int) $m['registros'] > 0 ? 'text-success' : 'text-body-secondary' ?>">
                        <i class="bi <?= (int) $m['registros'] > 0 ? 'bi-check-circle-fill' : 'bi-plus-circle' ?>"></i>
                        <?= (int) $m['registros'] > 0 ? (int) $m['registros'] . ' registrada(s)' : 'Registrar' ?>
                    </span>
                </a>
            <?php endforeach; ?>
        </div>
    <?php endif; ?>
</div>
