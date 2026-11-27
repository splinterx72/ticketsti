<?php
/**
 * Hoja carta del formato (pantalla e impresión). Todo lo variable viene de
 * la plantilla del año (logos y textos) y de los datos del documento.
 *
 * @var array<string, mixed> $f    formato (find)
 * @var array<string, mixed> $doc  ['d' => datos, 'firmas' => por rol con 'src', 'logos' => izq/der/pie]
 */
$c = $f['contenido'];
$d = $doc['d'];
$firmas = $doc['firmas'];
$logos = $doc['logos'];
$marcados = $f['motivos_idx'];
$motivos = array_values((array) ($c['motivos'] ?? []));
// Dos columnas como el formato oficial: izquierda 0,1 y derecha 2,3.
$mitad = (int) ceil(count($motivos) / 2);
$filas = [];
for ($i = 0; $i < $mitad; $i++) {
    $filas[] = [$i, $i + $mitad < count($motivos) ? $i + $mitad : null];
}
$x = static fn (int $i): string => in_array($i, $marcados, true) ? 'X' : '';
$et = \App\Services\FormatoService::etiquetas($c);
$leyenda = \App\Services\FormatoService::leyendaFirma($c);
?>
<article class="hoja-carta" aria-label="<?= e($f['titulo']) ?>">
    <div class="hoja-cuerpo">
        <header class="hoja-encabezado">
            <div class="hoja-logo-izq"><?php if ($logos['izq']): ?><img src="<?= e($logos['izq']) ?>" alt="Logotipo institucional"><?php endif; ?></div>
            <div class="hoja-dependencia">
                <?php foreach ((array) ($c['encabezado'] ?? []) as $linea): ?><div><?= e($linea) ?></div><?php endforeach; ?>
            </div>
            <div class="hoja-logo-der"><?php if ($logos['der']): ?><img src="<?= e($logos['der']) ?>" alt="Emblema del año"><?php endif; ?></div>
        </header>
        <hr class="hoja-linea">
        <h1 class="hoja-titulo"><?= e($f['titulo']) ?></h1>
        <p class="hoja-lugar"><?= e(\App\Services\FormatoService::lugarYFecha($c, (string) $d['lugar'], (string) $d['fecha'])) ?></p>

        <div class="hoja-seccion"><?= e($c['seccion_usuario'] ?? '') ?></div>
        <table class="hoja-tabla hoja-usuario">
            <tr><td class="lbl"><?= e($et['usuario']) ?></td><td><?= e($d['usuario']) ?></td><td class="lbl"><?= e($et['ficha']) ?></td><td><?= e($d['ficha']) ?></td></tr>
            <tr><td class="lbl"><?= e($et['organismo']) ?></td><td><?= e($d['organismo']) ?></td><td class="lbl"><?= e($et['ext']) ?></td><td><?= e($d['ext']) ?></td></tr>
            <tr><td class="lbl"><?= e($et['subdireccion']) ?></td><td><?= e($d['subdireccion']) ?></td><td class="lbl"><?= e($et['gerencia']) ?></td><td><?= e($d['gerencia']) ?></td></tr>
            <tr><td class="lbl"><?= e($et['ubicacion']) ?></td><td><?= e($d['ubicacion']) ?></td><td class="lbl"><?= e($et['remedy']) ?></td><td><?= e($d['remedy']) ?></td></tr>
        </table>
        <p class="hoja-nota"><?= e($c['nota_resguardo'] ?? '') ?></p>

        <div class="hoja-politicas">
            <p class="intro"><?= e($c['intro'] ?? '') ?></p>
            <?php foreach ((array) ($c['parrafos'] ?? []) as $p): ?><p><?= e($p) ?></p><?php endforeach; ?>
            <?php if (!empty($c['subtitulo'])): ?><p class="subtitulo"><?= e($c['subtitulo']) ?></p><?php endif; ?>
            <?php foreach ((array) ($c['parrafos2'] ?? []) as $p): ?><p><?= e($p) ?></p><?php endforeach; ?>
            <p class="manifiesto"><?= e($c['manifiesto'] ?? '') ?></p>
        </div>

        <table class="hoja-tabla hoja-equipo">
            <thead><tr><th><?= e($et['tipo']) ?></th><th><?= e($et['marca']) ?></th><th><?= e($et['modelo']) ?></th><th><?= e($et['serie']) ?></th><th class="inv"><?= e($et['inventario']) ?></th></tr></thead>
            <tbody><tr><td><?= e($d['tipo']) ?></td><td><?= e($d['marca']) ?></td><td><?= e($d['modelo']) ?></td><td class="serie"><?= e($d['serie']) ?></td><td class="serie"><?= e($d['inventario']) ?></td></tr></tbody>
        </table>

        <div class="hoja-seccion"><?= e($c['titulo_motivos'] ?? 'Motivo de formateo mencionado:') ?></div>
        <table class="hoja-tabla hoja-motivos">
            <thead><tr><th><?= e($et['motivo']) ?></th><th class="aplica"><?= e($et['aplica']) ?></th><th><?= e($et['motivo']) ?></th><th class="aplica"><?= e($et['aplica']) ?></th></tr></thead>
            <tbody>
            <?php foreach ($filas as [$a, $b]): ?>
                <tr>
                    <td><?= e($motivos[$a]) ?></td><td class="aplica"><?= $x($a) ?></td>
                    <td><?= $b !== null ? e($motivos[$b]) : '' ?></td><td class="aplica"><?= $b !== null ? $x($b) : '' ?></td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
        <div class="hoja-notas">
            <?php foreach ((array) ($c['notas'] ?? []) as $n): ?><p><?= e($n) ?></p><?php endforeach; ?>
        </div>

        <table class="hoja-tabla hoja-firmas">
            <tr class="trazo">
                <?php foreach (['usuario', 'responsable', 'ti'] as $rol): $fi = $firmas[$rol] ?? null; ?>
                    <td><?php if ($fi && $fi['src']): ?><img src="<?= e($fi['src']) ?>" alt="Firma de <?= e($fi['nombre']) ?>">
                        <?php elseif ($fi && $fi['modo'] === 'autografa'): ?><span class="autografa">Firma autógrafa en el original escaneado</span><?php endif; ?></td>
                <?php endforeach; ?>
            </tr>
            <?php if ($leyenda !== ''): ?>
                <tr class="leyenda"><td><?= e($leyenda) ?></td><td><?= e($leyenda) ?></td><td><?= e($leyenda) ?></td></tr>
            <?php endif; ?>
            <tr class="nombre">
                <?php foreach (['usuario', 'responsable', 'ti'] as $rol): $fi = $firmas[$rol] ?? null; ?>
                    <td><?= $fi ? e(mb_strtoupper((string) $fi['nombre']) . ' F-' . $fi['ficha']) : '&nbsp;' ?>
                        <div class="rol"><?= e($c['firmas'][$rol] ?? '') ?></div>
                        <?php if ($rol === 'ti' && !empty($f['metodo'])): ?><div class="metodo">Método: <?= e($f['metodo']) ?></div><?php endif; ?></td>
                <?php endforeach; ?>
            </tr>
            <tr class="fecha">
                <?php foreach (['usuario', 'responsable', 'ti'] as $rol): $fi = $firmas[$rol] ?? null; ?>
                    <td><?= $fi ? e(date('d/m/Y', (int) strtotime((string) $fi['firmado_en']))) : '&nbsp;' ?></td>
                <?php endforeach; ?>
            </tr>
        </table>
    </div>

    <footer class="hoja-pie">
        <div class="hoja-pie-linea">
            <?php if ($logos['pie']): ?><img src="<?= e($logos['pie']) ?>" alt="Leyenda del año"><?php endif; ?>
            <span class="linea"></span>
        </div>
        <div class="hoja-pie-datos">
            <span>Folio <?= e($f['folio']) ?><?= !empty($f['ticket_folio']) ? ' · Ticket ' . e($f['ticket_folio']) : '' ?> · Verificación <?= e($f['codigo']) ?></span>
        </div>
    </footer>
</article>
