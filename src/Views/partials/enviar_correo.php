<?php
/**
 * Formulario "Enviar correo con plantilla".
 *
 * @var list<array<string, mixed>> $plantillas activas
 * @var int $destinatarioId
 * @var string $destinatario nombre y correo para mostrar
 * @var int|null $ticketId
 */
$ticketId = $ticketId ?? null;
$uid = 'correo' . ($ticketId ?? $destinatarioId);
?>
<details class="card border-0 shadow-sm mb-4 envio-correo">
    <summary class="card-header bg-white fw-semibold"><i class="bi bi-envelope-paper me-1"></i>Enviar correo con plantilla</summary>
    <form method="post" action="<?= e(url('/ti/plantillas/enviar')) ?>" class="card-body">
        <?= csrf_field() ?>
        <input type="hidden" name="destinatario_id" value="<?= (int) $destinatarioId ?>">
        <?php if ($ticketId !== null): ?><input type="hidden" name="ticket_id" value="<?= (int) $ticketId ?>"><?php endif; ?>
        <div class="small text-body-secondary mb-2">Para: <?= e($destinatario) ?></div>
        <div class="mb-2">
            <label class="form-label small fw-semibold" for="<?= e($uid) ?>_pl">Plantilla</label>
            <select class="form-select form-select-sm" id="<?= e($uid) ?>_pl" name="plantilla_id" data-plantilla-destino="<?= e($uid) ?>">
                <option value="">— Escribir sin plantilla —</option>
                <?php foreach ($plantillas as $p): ?>
                    <option value="<?= (int) $p['id'] ?>" data-asunto="<?= e($p['asunto']) ?>" data-cuerpo="<?= e($p['cuerpo']) ?>"><?= e($p['nombre']) ?></option>
                <?php endforeach; ?>
            </select>
        </div>
        <div class="mb-2">
            <label class="form-label small fw-semibold" for="<?= e($uid) ?>_asunto">Asunto</label>
            <input class="form-control form-control-sm" id="<?= e($uid) ?>_asunto" name="asunto" maxlength="180" required>
        </div>
        <div class="mb-2">
            <label class="form-label small fw-semibold" for="<?= e($uid) ?>_cuerpo">Mensaje</label>
            <textarea class="form-control form-control-sm" id="<?= e($uid) ?>_cuerpo" name="cuerpo" rows="6" maxlength="5000" required></textarea>
            <div class="form-text">Las variables (<code>{nombre}</code>, <code>{folio}</code>…) se reemplazan al enviar.<?= $ticketId !== null ? ' Quedará constancia en el seguimiento del ticket.' : '' ?></div>
        </div>
        <div class="text-end"><button class="btn btn-sm btn-primary" type="submit"><i class="bi bi-send me-1"></i>Enviar</button></div>
    </form>
</details>
