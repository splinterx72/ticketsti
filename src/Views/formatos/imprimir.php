<?php
/**
 * Página de impresión (sin el layout del sistema): hoja carta lista para imprimir o guardar como PDF.
 *
 * @var array<string, mixed> $f
 * @var array<string, mixed> $doc
 */
?>
<!doctype html>
<html lang="es-MX">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="robots" content="noindex, nofollow">
    <title><?= e($f['folio'] . ' · ' . $f['titulo']) ?></title>
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css"
          integrity="sha384-QWTKZyjpPEjISv5WaRU9OFeRpok6YctnYmDr5pNlyT2bRjXh0JMhjY6hW+ALEwIH" crossorigin="anonymous">
    <link rel="stylesheet" href="<?= e(asset('css/formato.css')) ?>">
</head>
<body class="imprimir-cuerpo">
<div class="imprimir-barra">
    <a class="btn btn-sm btn-light" href="<?= e(url('/formatos/' . (int) $f['id'])) ?>">&larr; Volver</a>
    <span class="small text-body-secondary">Para PDF elige «Guardar como PDF», tamaño Carta y márgenes «Ninguno».</span>
    <button type="button" class="btn btn-sm btn-primary" data-imprimir>Imprimir / Guardar PDF</button>
</div>
<?= partial('formato_hoja', ['f' => $f, 'doc' => $doc]) ?>
<script src="<?= e(asset('js/firma.js')) ?>"></script>
</body>
</html>
