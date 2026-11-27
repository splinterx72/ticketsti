<?php
/**
 * @var string $remedy
 * @var list<string> $copias
 * @var string $driver
 * @var list<array<string, mixed>> $instalaciones
 */
?>
<div class="d-flex justify-content-between align-items-center mb-3">
    <h1 class="h4 mb-0"><i class="bi bi-envelope-at me-1"></i>Correo y Remedy</h1>
</div>
<?php if ($driver === 'off'): ?>
    <div class="alert alert-warning small"><i class="bi bi-exclamation-triangle me-1"></i>El envío de correos está apagado (<code>mail.driver = off</code> en <code>config/config.php</code>). Lo que configures aquí aplicará cuando se encienda.</div>
<?php endif; ?>
<div class="row g-4">
    <div class="col-lg-6">
        <form method="post" action="<?= e(url('/ti/correo')) ?>" class="card border-0 shadow-sm">
            <?= csrf_field() ?>
            <div class="card-header bg-white fw-semibold">Destinos de cada ticket nuevo</div>
            <div class="card-body">
                <div class="mb-3">
                    <label class="form-label fw-semibold" for="remedy">Correo de la mesa institucional (Remedy)</label>
                    <input type="email" class="form-control" id="remedy" name="remedy" maxlength="150" value="<?= e($remedy) ?>" placeholder="mesadeayuda@pemex.com">
                    <div class="form-text">Recibe la ficha completa de cada ticket nuevo de las instalaciones que lo tengan encendido. Vacío = no se envía a Remedy.</div>
                </div>
                <div>
                    <label class="form-label fw-semibold" for="copias">Copias fijas <span class="fw-normal text-body-secondary">(opcional)</span></label>
                    <textarea class="form-control" id="copias" name="copias" rows="3" placeholder="Un correo por línea o separados por coma"><?= e(implode("\n", $copias)) ?></textarea>
                    <div class="form-text">Reciben todos los tickets nuevos, sin importar la instalación.</div>
                </div>
            </div>
            <div class="card-footer bg-white text-end"><button class="btn btn-primary" type="submit">Guardar</button></div>
        </form>
    </div>
    <div class="col-lg-6">
        <div class="card border-0 shadow-sm">
            <div class="card-header bg-white fw-semibold">¿Qué instalaciones envían a Remedy?</div>
            <div class="card-body small text-body-secondary pb-0">Lo deciden el supervisor o el coordinador de cada instalación en <em>Supervisión › Agentes</em>. Aquí también puedes cambiarlo.</div>
            <ul class="list-group list-group-flush">
                <?php foreach ($instalaciones as $i): $on = (int) $i['remedy_activo'] === 1; ?>
                    <li class="list-group-item d-flex justify-content-between align-items-center gap-2">
                        <span><?= e($i['nombre']) ?><?php if ($i['zona']): ?> <span class="small text-body-secondary">· <?= e($i['zona']) ?></span><?php endif; ?></span>
                        <form method="post" action="<?= e(url('/supervisor/instalaciones/' . (int) $i['id'] . '/remedy')) ?>" class="m-0 d-flex align-items-center gap-2">
                            <?= csrf_field() ?>
                            <input type="hidden" name="volver" value="correo">
                            <input type="hidden" name="remedy_activo" value="<?= $on ? '0' : '1' ?>">
                            <span class="small <?= $on ? 'text-success' : 'text-body-secondary' ?>"><i class="bi <?= $on ? 'bi-check-circle-fill' : 'bi-slash-circle' ?>"></i> <?= $on ? 'Envía' : 'No envía' ?></span>
                            <button class="btn btn-sm btn-outline-secondary" type="submit"><?= $on ? 'Apagar' : 'Encender' ?></button>
                        </form>
                    </li>
                <?php endforeach; ?>
                <?php if ($instalaciones === []): ?><li class="list-group-item small text-body-secondary">Aún no hay instalaciones.</li><?php endif; ?>
            </ul>
        </div>
    </div>
</div>
