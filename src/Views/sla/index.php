<?php
/** @var array<string, array{horas_respuesta: int, horas_resolucion: int}> $config */
$nombres = \App\Services\SlaService::PRIORIDADES;
?>
<div class="d-flex justify-content-between align-items-center mb-3">
    <h1 class="h4 mb-0"><i class="bi bi-stopwatch me-1"></i>Tiempos de atención (SLA)</h1>
</div>
<div class="alert alert-info small">
    <i class="bi bi-info-circle me-1"></i>
    <strong>Respuesta</strong>: desde que se levanta el ticket hasta la primera acción de TI (tomarlo, asignarlo, cambiar su estado o responder).
    <strong>Resolución</strong>: hasta que se cierra. Se cuentan horas naturales (24×7).
    Al 75 % del plazo el ticket se marca <em>Por vencer</em>; vencido, se avisa por correo: la respuesta al supervisor de la instalación y la
    resolución al coordinador de la zona (si no hay, al Admin TI). Para que el aviso salga a tiempo, programa la Tarea Cron <strong>cada hora</strong>.
</div>
<form method="post" action="<?= e(url('/ti/sla')) ?>" class="card border-0 shadow-sm" style="max-width: 46rem;">
    <?= csrf_field() ?>
    <div class="table-responsive">
        <table class="table align-middle mb-0">
            <thead class="table-light"><tr><th>Prioridad</th><th>Respuesta (horas)</th><th>Resolución (horas)</th></tr></thead>
            <tbody>
            <?php foreach ($nombres as $k => $label): $c = $config[$k] ?? ['horas_respuesta' => 4, 'horas_resolucion' => 24]; ?>
                <tr>
                    <td><?= prioridad_badge($k) ?></td>
                    <td><input type="number" class="form-control" name="sla[<?= e($k) ?>][horas_respuesta]" min="1" max="720" required value="<?= e($c['horas_respuesta']) ?>" aria-label="Horas de respuesta <?= e($label) ?>" style="max-width: 9rem;"></td>
                    <td><input type="number" class="form-control" name="sla[<?= e($k) ?>][horas_resolucion]" min="1" max="2160" required value="<?= e($c['horas_resolucion']) ?>" aria-label="Horas de resolución <?= e($label) ?>" style="max-width: 9rem;"></td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    </div>
    <div class="card-footer bg-white text-end"><button class="btn btn-primary" type="submit"><i class="bi bi-check2 me-1"></i>Guardar</button></div>
</form>
