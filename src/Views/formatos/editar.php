<?php
/**
 * @var array<string, mixed> $p
 * @var int $usados
 * @var array{f: array<string, mixed>, doc: array<string, mixed>} $muestra
 */
$c = $p['contenido'];
$l = static fn (string $k): string => implode("\n", (array) ($c[$k] ?? []));
$area = static function (string $name, string $label, string $valor, int $filas = 3, string $ayuda = ''): string {
    return '<div class="mb-3"><label class="form-label small fw-semibold" for="' . e($name) . '">' . e($label) . '</label>'
        . '<textarea class="form-control form-control-sm" id="' . e($name) . '" name="' . e($name) . '" rows="' . $filas . '">' . e($valor) . '</textarea>'
        . ($ayuda !== '' ? '<div class="form-text">' . e($ayuda) . '</div>' : '') . '</div>';
};
$campo = static function (string $name, string $label, string $valor): string {
    return '<div class="mb-3"><label class="form-label small fw-semibold" for="' . e($name) . '">' . e($label) . '</label>'
        . '<input class="form-control form-control-sm" id="' . e($name) . '" name="' . e($name) . '" value="' . e($valor) . '"></div>';
};
?>
<div class="mb-3"><a href="<?= e(url('/ti/formatos')) ?>" class="small"><i class="bi bi-arrow-left"></i> Formatos</a></div>
<div class="d-flex flex-wrap justify-content-between align-items-center gap-2 mb-3">
    <h1 class="h4 mb-0"><?= e($p['titulo']) ?> <span class="badge text-bg-light border"><?= (int) $p['anio'] ?> · v<?= (int) $p['version'] ?></span></h1>
    <?php if ($usados > 0): ?><span class="small text-warning-emphasis"><i class="bi bi-lock me-1"></i><?= $usados ?> documento(s) usan esta versión: al guardar se creará la versión <?= (int) $p['version'] + 1 ?>.</span><?php endif; ?>
</div>
<div class="row g-4">
    <div class="col-xl-5">
        <form method="post" action="<?= e(url('/ti/formatos/' . (int) $p['id'])) ?>" enctype="multipart/form-data" class="card border-0 shadow-sm">
            <?= csrf_field() ?>
            <div class="card-header bg-white fw-semibold">Logos del año</div>
            <div class="card-body">
                <?php foreach (['izq' => 'Encabezado izquierdo (p. ej. Gobierno de México | PEMEX)', 'der' => 'Encabezado derecho (emblema del año)', 'pie' => 'Pie de página (leyenda del año)'] as $pos => $label): ?>
                    <div class="mb-3">
                        <label class="form-label small fw-semibold" for="logo_<?= $pos ?>"><?= e($label) ?></label>
                        <input type="file" class="form-control form-control-sm" id="logo_<?= $pos ?>" name="logo_<?= $pos ?>" accept=".png,.jpg,.jpeg,.webp">
                        <div class="form-text"><?= $p['logo_' . $pos] ? 'Ya hay uno cargado; sube otro solo para reemplazarlo.' : 'Sin logo.' ?> PNG con fondo transparente o blanco; ideal 1200 px de ancho.</div>
                    </div>
                <?php endforeach; ?>
            </div>
            <div class="card-header bg-white fw-semibold border-top">Textos</div>
            <div class="card-body">
                <?= $campo('titulo', 'Título', (string) $p['titulo']) ?>
                <?= $area('encabezado', 'Dependencia (una línea por renglón)', $l('encabezado'), 2) ?>
                <?= $campo('lugar_omision', 'Lugar si la instalación no tiene ciudad', (string) ($c['lugar_omision'] ?? '')) ?>
                <?= $campo('organismo_omision', 'Dirección/Organismo si el GECA no lo trae', (string) ($c['organismo_omision'] ?? '')) ?>
                <?= $campo('seccion_usuario', 'Encabezado de datos del usuario', (string) ($c['seccion_usuario'] ?? '')) ?>
                <?= $campo('nota_resguardo', 'Nota de resguardo', (string) ($c['nota_resguardo'] ?? '')) ?>
                <?= $area('intro', 'Introducción de políticas (en negritas)', (string) ($c['intro'] ?? ''), 3) ?>
                <?= $area('parrafos', 'Políticas (un párrafo por línea)', $l('parrafos'), 6) ?>
                <?= $campo('subtitulo', 'Subtítulo', (string) ($c['subtitulo'] ?? '')) ?>
                <?= $area('parrafos2', 'Políticas después del subtítulo (un párrafo por línea)', $l('parrafos2'), 4) ?>
                <?= $area('manifiesto', 'Manifiesto del usuario', (string) ($c['manifiesto'] ?? ''), 2) ?>
                <?= $campo('titulo_motivos', 'Encabezado de motivos', (string) ($c['titulo_motivos'] ?? '')) ?>
                <?= $area('motivos', 'Motivos (uno por línea, máximo 8)', $l('motivos'), 4) ?>
                <?= $area('notas', 'Notas al pie (una por línea)', $l('notas'), 4, 'Completa aquí la nota *** si viene cortada.') ?>
                <?= $area('metodos', 'Métodos de formateo (uno por línea)', $l('metodos'), 3) ?>
                <?= $campo('firma_usuario', 'Leyenda bajo la firma del usuario', (string) ($c['firmas']['usuario'] ?? '')) ?>
                <?= $campo('firma_responsable', 'Leyenda bajo la firma del responsable', (string) ($c['firmas']['responsable'] ?? '')) ?>
                <?= $campo('firma_ti', 'Leyenda bajo la firma de TI', (string) ($c['firmas']['ti'] ?? '')) ?>
                <?= $campo('leyenda_firma', 'Renglón sobre los nombres (vacío = sin renglón; antes: "Nombre, ficha y firma")', \App\Services\FormatoService::leyendaFirma($c)) ?>
                <?= $campo('formato_lugar', 'Lugar y fecha (usa {lugar} y {fecha})', (string) ($c['formato_lugar'] ?? '{lugar}, a {fecha}')) ?>
            </div>
            <div class="card-header bg-white fw-semibold border-top">Etiquetas de las tablas</div>
            <div class="card-body row g-2">
                <?php
                $etq = isset($c['etiquetas']) ? \App\Services\FormatoService::etiquetas($c) : \App\Services\FormatoService::ETIQUETAS;
                $nombres = ['usuario' => 'Usuario', 'ficha' => 'Ficha', 'organismo' => 'Dirección/Organización', 'ext' => 'Extensión', 'subdireccion' => 'Subdirección',
                    'gerencia' => 'Gerencia', 'ubicacion' => 'Ubicación', 'remedy' => 'Ticket Remedy', 'tipo' => 'Tipo de equipo', 'marca' => 'Marca',
                    'modelo' => 'Modelo', 'serie' => 'Serie', 'inventario' => 'Inventario', 'motivo' => 'Columna de motivos', 'aplica' => 'Columna "Aplica"'];
                foreach ($nombres as $k => $label): ?>
                    <div class="col-sm-6">
                        <label class="form-label small mb-0 text-body-secondary" for="etiqueta_<?= e($k) ?>"><?= e($label) ?></label>
                        <input class="form-control form-control-sm" id="etiqueta_<?= e($k) ?>" name="etiqueta_<?= e($k) ?>" maxlength="120" value="<?= e($etq[$k]) ?>">
                    </div>
                <?php endforeach; ?>
            </div>
            <div class="card-footer bg-white text-end"><button class="btn btn-primary" type="submit">Guardar</button></div>
        </form>
    </div>
    <div class="col-xl-7">
        <div class="small text-body-secondary mb-2"><i class="bi bi-eye me-1"></i>Vista previa con datos de ejemplo</div>
        <div class="hoja-scroll"><?= partial('formato_hoja', $muestra) ?></div>
    </div>
</div>
