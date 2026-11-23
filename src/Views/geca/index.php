<?php
/**
 * @var list<array<string, mixed>> $cargas
 * @var int $maxUploadMb
 * @var bool $xlsxSoportado
 * @var list<string> $headers
 */
$accept = $xlsxSoportado ? '.xlsx,.csv' : '.csv';
?>
<div class="d-flex flex-wrap justify-content-between align-items-center gap-2 mb-4">
    <h1 class="h4 mb-0"><i class="bi bi-cloud-arrow-up me-1"></i>Carga semanal del GECA</h1>
    <a href="<?= e(url('/geca/plantilla')) ?>" class="btn btn-outline-secondary btn-sm">
        <i class="bi bi-filetype-csv me-1"></i>Descargar plantilla
    </a>
</div>

<div class="row g-4">
    <div class="col-lg-5">
        <div class="card border-0 shadow-sm">
            <div class="card-body">
                <form method="post" action="<?= e(url('/geca/cargar')) ?>" enctype="multipart/form-data" id="gecaForm">
                    <?= csrf_field() ?>
                    <div class="mb-3">
                        <label for="archivo" class="form-label fw-semibold">Archivo GECA</label>
                        <input class="form-control" type="file" id="archivo" name="archivo" accept="<?= e($accept) ?>" required
                               data-max-bytes="<?= e($maxUploadMb * 1048576) ?>">
                        <div class="form-text">
                            Formatos: <?= $xlsxSoportado ? '<strong>.xlsx</strong> o ' : '' ?><strong>.csv</strong>
                            (coma o punto y coma; UTF-8 o ANSI de Excel). Máximo <?= e($maxUploadMb) ?> MB.
                        </div>
                        <?php if (!$xlsxSoportado): ?>
                            <div class="alert alert-warning small mt-2 mb-0">
                                El servidor no tiene las extensiones <code>zip</code>/<code>xmlreader</code>; solo se aceptan CSV.
                            </div>
                        <?php endif; ?>
                    </div>

                    <div class="alert alert-info small">
                        <i class="bi bi-info-circle me-1"></i>
                        La carga usa <strong>UPSERT</strong> por <code>ficha</code> y <code>serie</code>. No se borra nada:
                        se conservan cuentas, contraseñas, ubicaciones reales, equipo principal, garantías y tickets.
                        Los equipos que no vengan en el archivo quedan como <em>no vigentes</em>.
                    </div>

                    <button type="submit" class="btn btn-primary w-100" id="gecaSubmit">
                        <span class="spinner-border spinner-border-sm me-1 d-none" role="status" aria-hidden="true"></span>
                        <span class="label"><i class="bi bi-upload me-1"></i>Procesar archivo</span>
                    </button>
                    <p class="small text-body-secondary mt-2 mb-0 d-none" id="gecaWait">
                        Procesando… no cierres ni recargues la página. Un GECA grande puede tardar un par de minutos.
                    </p>
                </form>
            </div>
        </div>

        <div class="card border-0 shadow-sm mt-4">
            <div class="card-header bg-white fw-semibold small">Reporte “Equipos entregados con estatus”</div>
            <div class="card-body small">
                <p class="mb-2">Sube el reporte tal como lo descarga el GECA (una fila por componente). Columnas <strong>obligatorias</strong> en negritas:</p>
                <ol class="geca-headers mb-0">
                    <?php foreach ($headers as $h): ?>
                        <li><code class="<?= in_array($h, \App\Services\GecaSyncService::REQUIRED, true) ? 'fw-bold' : 'text-body-secondary' ?>"><?= e($h) ?></code></li>
                    <?php endforeach; ?>
                </ol>
                <p class="text-body-secondary mt-2 mb-0">
                    Los monitores se asignan a la computadora de su folio (o a la única laptop de la ficha); los resguardos en
                    <em>BAJA</em> quedan sin trabajador. Se aceptan mayúsculas, acentos y espacios en los encabezados.
                </p>
            </div>
        </div>
    </div>

    <div class="col-lg-7">
        <div class="card border-0 shadow-sm">
            <div class="card-header bg-white fw-semibold"><i class="bi bi-clock-history me-1"></i>Historial de cargas</div>
            <?php if ($cargas === []): ?>
                <div class="card-body text-body-secondary">Todavía no hay cargas registradas.</div>
            <?php else: ?>
                <div class="table-responsive">
                    <table class="table table-sm table-hover align-middle mb-0">
                        <thead class="table-light">
                        <tr>
                            <th>#</th><th>Fecha</th><th>Archivo</th>
                            <th class="text-end">Total</th><th class="text-end">Nuevos</th>
                            <th class="text-end">Actualiz.</th><th class="text-end">No vig.</th>
                        </tr>
                        </thead>
                        <tbody>
                        <?php foreach ($cargas as $c): ?>
                            <tr>
                                <td><a href="<?= e(url('/geca/cargas/' . (int) $c['id'])) ?>"><?= e($c['id']) ?></a></td>
                                <td class="text-nowrap"><?= e(fecha_mx($c['fecha_carga'])) ?></td>
                                <td class="text-truncate" style="max-width: 12rem;" title="<?= e($c['nombre_archivo']) ?>">
                                    <?= e($c['nombre_archivo']) ?>
                                    <div class="small text-body-secondary"><?= e($c['agente'] ?? '—') ?></div>
                                </td>
                                <td class="text-end"><?= e(number_format((int) $c['total_registros'])) ?></td>
                                <td class="text-end text-success"><?= e(number_format((int) $c['nuevos_insertados'])) ?></td>
                                <td class="text-end"><?= e(number_format((int) $c['actualizados'])) ?></td>
                                <td class="text-end text-secondary"><?= e(number_format((int) $c['marcados_no_vigentes'])) ?></td>
                            </tr>
                        <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            <?php endif; ?>
        </div>
    </div>
</div>
