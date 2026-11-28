<?php
/**
 * @var list<array<string, mixed>> $equipos
 * @var list<array<string, mixed>> $catalogo
 * @var bool $hayCatalogo
 * @var array<string, mixed> $habitual   ubicación habitual física del usuario
 * @var array<int, ?int> $instalacionGeca  equipo_id => instalación equivalente de su inmueble GECA
 * @var array<string, string> $tipos
 * @var array<string, string> $prioridades
 * @var array<string, string> $old
 * @var list<array{id: int, nombre: string, ficha: string, ver_todos: bool, hasta: ?string}> $jefes  quienes me autorizaron
 * @var array{id: int, nombre: string, ficha: string}|null $para  a nombre de quién (null = yo)
 * @var bool $supervisa  supervisor/coordinador/admin: puede levantar por trabajadores de sus instalaciones
 * @var list<array{id: int, nombre: string, descripcion: ?string}> $categorias
 * @var array<string, string> $tiposTi  tipos que solo TI usa en sus propios tickets (varios equipos)
 * @var list<array<string, mixed>> $misTareas  asignaciones abiertas del agente (para sumar el ticket a su avance)
 */
$tipoSel = $old['tipo_ticket'] ?? 'incidencia';
$catSel = (int) ($old['categoria_id'] ?? 0);
$hasOld = $old !== [];
// Regla 3.C: se preselecciona el equipo principal (el primero, ordenado por es_principal DESC).
$equipoSel = $hasOld ? (int) ($old['equipo_id'] ?? 0) : (int) ($equipos[0]['id'] ?? 0);
$componenteSel = $old['componente_afectado'] ?? ($equipoSel > 0 ? 'equipo' : 'general');
// Ubicación física sugerida: la capturada, la real del equipo elegido o la habitual del usuario.
if ($hasOld) {
    $selFisica = [
        'instalacion_id' => $old['reportada_instalacion_id'] ?? 0, 'ubicacion_fisica_id' => $old['reportada_ubicacion_fisica_id'] ?? 0,
        'nivel_id' => $old['reportada_nivel_id'] ?? 0, 'ala' => $old['reportada_ala'] ?? '', 'referencia' => $old['reportada_referencia'] ?? '',
    ];
} else {
    $selFisica = $habitual;
    foreach ($equipos as $eq) {
        if ((int) $eq['id'] === $equipoSel && $eq['real_ubicacion_fisica_id'] !== null) {
            $selFisica = ['ubicacion_fisica_id' => $eq['real_ubicacion_fisica_id'], 'nivel_id' => $eq['real_nivel_id'], 'ala' => $eq['real_ala'], 'referencia' => ''];
        }
    }
}
?>
<div class="d-flex justify-content-between align-items-center mb-3">
    <h1 class="h4 mb-0"><i class="bi bi-plus-circle me-1"></i>Nuevo ticket</h1>
    <a href="<?= e(url('/tickets')) ?>" class="btn btn-link"><i class="bi bi-arrow-left me-1"></i>Mis tickets</a>
</div>

<?php if ($supervisa): ?>
    <form method="get" action="<?= e(url('/tickets/nuevo')) ?>" class="card border-0 shadow-sm mb-4 para-quien<?= $para !== null ? ' is-delegado' : '' ?>">
        <div class="card-body d-flex flex-wrap align-items-end gap-2">
            <div class="flex-grow-1" style="max-width: 22rem;">
                <label class="form-label fw-semibold mb-1" for="ficha"><i class="bi bi-person-plus me-1"></i>¿Para quién es el ticket?</label>
                <input class="form-control font-monospace" id="ficha" name="ficha" maxlength="20" placeholder="Ficha del trabajador (vacío = para mí)" value="<?= e($para['ficha'] ?? '') ?>">
            </div>
            <?php if ($jefes !== []): ?>
                <select class="form-select w-auto" name="para" aria-label="O a nombre de quien te autorizó" data-autoenviar>
                    <option value="0">… o de quien me autorizó</option>
                    <?php foreach ($jefes as $j): ?><option value="<?= (int) $j['id'] ?>"><?= e($j['nombre']) ?> (<?= e($j['ficha']) ?>)</option><?php endforeach; ?>
                </select>
            <?php endif; ?>
            <button class="btn btn-outline-primary" type="submit">Usar</button>
            <?php if ($para !== null): ?><a class="btn btn-link" href="<?= e(url('/tickets/nuevo')) ?>">Para mí</a><?php endif; ?>
            <div class="w-100 small text-body-secondary">
                <i class="bi bi-info-circle me-1"></i><?= $para !== null
                    ? 'Se muestran los equipos y la ubicación de <strong>' . e($para['nombre']) . '</strong>. El ticket quedará a su nombre, indicando que tú lo levantaste, y a los dos les llegarán los avisos.'
                    : 'Como supervisor puedes levantarlo a nombre de cualquier trabajador de tus instalaciones (por su ubicación o sus equipos).' ?>
            </div>
        </div>
    </form>
<?php elseif ($jefes !== []): ?>
    <form method="get" action="<?= e(url('/tickets/nuevo')) ?>" class="card border-0 shadow-sm mb-4 para-quien<?= $para !== null ? ' is-delegado' : '' ?>">
        <div class="card-body d-flex flex-wrap align-items-center gap-3">
            <label class="fw-semibold mb-0" for="para"><i class="bi bi-people me-1"></i>¿Para quién es el ticket?</label>
            <select class="form-select w-auto flex-grow-1" id="para" name="para" data-autoenviar style="max-width: 28rem;">
                <option value="0">Para mí</option>
                <?php foreach ($jefes as $j): ?>
                    <option value="<?= (int) $j['id'] ?>" <?= $para !== null && $para['id'] === $j['id'] ? 'selected' : '' ?>>A nombre de <?= e($j['nombre']) ?> (<?= e($j['ficha']) ?>)</option>
                <?php endforeach; ?>
            </select>
            <noscript><button class="btn btn-outline-primary btn-sm" type="submit">Cambiar</button></noscript>
            <?php if ($para !== null): ?>
                <div class="w-100 small text-body-secondary"><i class="bi bi-info-circle me-1"></i>Se muestran los equipos y la ubicación de <strong><?= e($para['nombre']) ?></strong>. El ticket quedará a su nombre, indicando que tú lo levantaste, y a los dos les llegarán los avisos.</div>
            <?php endif; ?>
        </div>
    </form>
<?php endif; ?>

<form method="post" action="<?= e(url('/tickets')) ?>" id="ticketForm" class="needs-loading" enctype="multipart/form-data" novalidate>
    <?= csrf_field() ?>
    <input type="hidden" name="para_usuario_id" value="<?= (int) ($para['id'] ?? 0) ?>">
    <div class="row g-4">
        <div class="col-lg-7">
            <div class="card border-0 shadow-sm">
                <div class="card-header bg-white fw-semibold">1. ¿Qué sucede?</div>
                <div class="card-body">
                    <div class="row g-3">
                        <div class="col-sm-7">
                            <label for="tipo_ticket" class="form-label fw-semibold">Tipo</label>
                            <select class="form-select" id="tipo_ticket" name="tipo_ticket" required data-tipos-ti="<?= e(implode(',', array_keys($tiposTi))) ?>">
                                <?php if ($tiposTi !== []): ?><optgroup label="Para trabajadores"><?php endif; ?>
                                <?php foreach ($tipos as $k => $label): ?>
                                    <option value="<?= e($k) ?>" <?= $tipoSel === $k ? 'selected' : '' ?>><?= e($label) ?></option>
                                <?php endforeach; ?>
                                <?php if ($tiposTi !== []): ?>
                                    </optgroup>
                                    <optgroup label="Trabajo de TI (varios equipos)">
                                        <?php foreach ($tiposTi as $k => $label): ?>
                                            <option value="<?= e($k) ?>" <?= $tipoSel === $k ? 'selected' : '' ?>><?= e($label) ?></option>
                                        <?php endforeach; ?>
                                    </optgroup>
                                <?php endif; ?>
                            </select>
                        </div>
                        <div class="col-sm-5">
                            <label for="prioridad" class="form-label fw-semibold">Prioridad</label>
                            <select class="form-select" id="prioridad" name="prioridad" required>
                                <?php foreach ($prioridades as $k => $label): ?>
                                    <option value="<?= e($k) ?>" <?= ($old['prioridad'] ?? 'media') === $k ? 'selected' : '' ?>><?= e($label) ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <?php if ($categorias !== []): ?>
                            <div class="col-12">
                                <label for="categoria_id" class="form-label fw-semibold">Categoría</label>
                                <select class="form-select" id="categoria_id" name="categoria_id" required data-categoria>
                                    <option value="">Elige la categoría…</option>
                                    <?php foreach ($categorias as $c): ?>
                                        <option value="<?= e($c['id']) ?>" data-descripcion="<?= e($c['descripcion'] ?? '') ?>" <?= $catSel === $c['id'] ? 'selected' : '' ?>><?= e($c['nombre']) ?></option>
                                    <?php endforeach; ?>
                                </select>
                                <div class="form-text" data-categoria-descripcion>Abarca: elige una categoría para ver ejemplos.</div>
                            </div>
                        <?php endif; ?>
                        <div class="col-12">
                            <label for="titulo" class="form-label fw-semibold">Título</label>
                            <input type="text" class="form-control" id="titulo" name="titulo" maxlength="180" required
                                   value="<?= e($old['titulo'] ?? '') ?>" placeholder="Ej. La computadora no enciende">
                        </div>
                        <div class="col-12">
                            <label for="descripcion" class="form-label fw-semibold">Descripción</label>
                            <textarea class="form-control" id="descripcion" name="descripcion" rows="6" maxlength="5000" required
                                      placeholder="¿Qué pasa? ¿Desde cuándo? ¿Aparece algún mensaje de error?"><?= e($old['descripcion'] ?? '') ?></textarea>
                        </div>
                        <div class="col-12">
                            <label for="adjuntos" class="form-label fw-semibold">Fotos o capturas <span class="fw-normal text-body-secondary">(opcional)</span></label>
                            <input type="file" class="form-control" id="adjuntos" name="adjuntos[]" multiple accept=".jpg,.jpeg,.png,.webp,.pdf">
                            <div class="form-text">Hasta 5 archivos de 10 MB: una foto de la pantalla o del mensaje de error ayuda mucho al técnico.</div>
                        </div>
                    </div>
                </div>
            </div>
        </div>

        <div class="col-lg-5">
            <?php if ($tiposTi !== []): ?>
                <div class="card border-0 shadow-sm mb-4 d-none" data-solo-ti>
                    <div class="card-header bg-white fw-semibold">2. Equipos del trabajo</div>
                    <div class="card-body">
                        <label for="buscarEquipo" class="form-label fw-semibold">Agregar por serie, ficha o nombre</label>
                        <input type="search" class="form-control" id="buscarEquipo" autocomplete="off" placeholder="Escribe al menos 3 caracteres"
                               data-buscar-equipos="<?= e(url('/ti/equipos/buscar')) ?>">
                        <div class="list-group mt-1" data-resultados></div>
                        <ul class="list-group list-group-flush small mt-2" data-elegidos></ul>
                        <label for="equipos_series" class="form-label fw-semibold mt-3">O pega las series <span class="fw-normal text-body-secondary">(una por línea o separadas por coma)</span></label>
                        <textarea class="form-control font-monospace" id="equipos_series" name="equipos_series" rows="3" spellcheck="false"><?= e($old['equipos_series'] ?? '') ?></textarea>
                        <?php if (($misTareas ?? []) !== []): ?>
                            <label for="tarea_asignacion_id" class="form-label fw-semibold mt-3">Sumar a mi tarea <span class="fw-normal text-body-secondary">(opcional)</span></label>
                            <select class="form-select" id="tarea_asignacion_id" name="tarea_asignacion_id">
                                <option value="0">No sumar a ninguna tarea</option>
                                <?php foreach ($misTareas as $mt): ?>
                                    <option value="<?= e($mt['asignacion_id']) ?>" <?= (int) ($old['tarea_asignacion_id'] ?? 0) === (int) $mt['asignacion_id'] ? 'selected' : '' ?>><?= e($mt['folio'] . ' · ' . $mt['titulo']) ?><?= $mt['meta_unidad'] ? ' (' . e((int) ($mt['lista_total'] ?? 0) > 0
                                        ? \App\Services\TareaService::porcentaje((int) $mt['lista_hecho'], (int) $mt['lista_total'])
                                        : \App\Services\TareaService::porcentaje((int) $mt['hecho'], (int) $mt['meta_cantidad'])) . ' %)' : '' ?></option>
                                <?php endforeach; ?>
                            </select>
                            <div class="form-text">Se agrega a tu hoja de reportes y los equipos cuentan para tu avance.</div>
                        <?php endif; ?>
                        <div class="form-text">El ticket queda asignado a ti. La ubicación es opcional si el trabajo abarca varios edificios.</div>
                    </div>
                </div>
            <?php endif; ?>
            <div class="card border-0 shadow-sm mb-4" data-no-ti>
                <div class="card-header bg-white fw-semibold">2. ¿Qué equipo?</div>
                <div class="card-body">
                    <label for="equipo_id" class="form-label fw-semibold">Equipo</label>
                    <select class="form-select" id="equipo_id" name="equipo_id">
                        <?php foreach ($equipos as $eq): ?>
                            <option value="<?= e($eq['id']) ?>"
                                    data-serie="<?= e($eq['serie']) ?>"
                                    data-m1="<?= e($eq['serie_monitor1'] ?? '') ?>"
                                    data-m2="<?= e($eq['serie_monitor2'] ?? '') ?>"
                                    data-ub="<?= e($eq['real_ubicacion_fisica_id'] ?? '') ?>"
                                    data-niv="<?= e($eq['real_nivel_id'] ?? '') ?>"
                                    data-ala="<?= e($eq['real_ala'] ?? '') ?>"
                                    data-geca-inst="<?= e($instalacionGeca[(int) $eq['id']] ?? '') ?>"
                                    data-geca="<?= e($eq['ubicacion_geca'] ?? '') ?>"
                                    <?= (int) $eq['id'] === $equipoSel ? 'selected' : '' ?>>
                                <?= !empty($eq['tipo_componente']) ? e(tipo_equipo($eq['tipo_componente'])) . ' · ' : '' ?><?= e($eq['serie']) ?> — <?= e(trim(($eq['marca'] ?? '') . ' ' . ($eq['modelo'] ?? ''))) ?>
                                <?= (int) $eq['es_principal'] === 1 ? '★ Principal' : '' ?>
                                <?= (int) $eq['vigente_en_geca'] === 0 ? '(no vigente)' : '' ?>
                            </option>
                        <?php endforeach; ?>
                        <option value="0" <?= $equipoSel === 0 ? 'selected' : '' ?>>Sin equipo / Incidencia general</option>
                    </select>
                    <?php if ($equipos === []): ?>
                        <div class="form-text"><?= $para !== null ? e($para['nombre']) . ' no tiene equipos en resguardo' : 'No tienes equipos en resguardo' ?>; el ticket se registrará como incidencia general.</div>
                    <?php endif; ?>

                    <div class="mt-3 equipo-only">
                        <span class="form-label fw-semibold d-block">Componente con falla</span>
                        <div class="btn-group w-100 flex-wrap" role="group" aria-label="Componente afectado">
                            <?php foreach (['equipo' => 'bi-pc-display', 'monitor_1' => 'bi-display', 'monitor_2' => 'bi-display', 'general' => 'bi-three-dots'] as $comp => $icon): ?>
                                <input type="radio" class="btn-check" name="componente_afectado" id="comp_<?= e($comp) ?>"
                                       value="<?= e($comp) ?>" <?= $componenteSel === $comp ? 'checked' : '' ?>>
                                <label class="btn btn-outline-primary btn-sm" for="comp_<?= e($comp) ?>">
                                    <i class="bi <?= e($icon) ?> me-1"></i><?= e(componente_label($comp)) ?>
                                </label>
                            <?php endforeach; ?>
                        </div>
                        <div class="form-text" id="monitorInfo"></div>
                    </div>

                    <div class="mt-3">
                        <label for="hostname_reportado" class="form-label fw-semibold">Hostname <span class="fw-normal text-body-secondary">(opcional)</span></label>
                        <input type="text" class="form-control font-monospace text-uppercase" id="hostname_reportado" name="hostname_reportado"
                               maxlength="30" value="<?= e($old['hostname_reportado'] ?? '') ?>" placeholder="PMxxx + serie" spellcheck="false" autocomplete="off">
                        <div class="form-text">Lo ves en la etiqueta del equipo o en <em>Este equipo › Propiedades</em>. Inicia con <code>PM</code>.</div>
                    </div>
                </div>
            </div>

            <div class="card border-0 shadow-sm">
                <div class="card-header bg-white fw-semibold">3. ¿Dónde está físicamente?</div>
                <div class="card-body">
                    <?= partial('ubicacion_picker', [
                        'catalogo' => $catalogo, 'prefijo' => 'reportada', 'sel' => $selFisica, 'requerido' => $hayCatalogo,
                        'refPlaceholder' => 'Ej. junto a la sala de juntas 2',
                    ]) ?>
                    <div class="small text-body-secondary mt-2 equipo-only" id="gecaInfo"></div>
                    <div class="alert alert-warning small mt-2 mb-0 d-none" id="discrepanciaAviso">
                        <i class="bi bi-geo-alt me-1"></i>Esta instalación es distinta a la del resguardo GECA. Al enviar, se actualizará la
                        ubicación real del equipo y TI dará seguimiento al cambio de resguardo.
                    </div>
                </div>
            </div>
        </div>
    </div>

    <div class="d-flex justify-content-end mt-4">
        <button type="submit" class="btn btn-primary btn-lg px-4">
            <span class="spinner-border spinner-border-sm me-1 d-none" aria-hidden="true"></span>
            <i class="bi bi-send me-1"></i>Enviar ticket
        </button>
    </div>
</form>
