<?php

declare(strict_types=1);

use App\Services\DiagnosticoService;

function test_detecta_migraciones_pendientes_por_tablas_y_columnas(): void
{
    $svc = new DiagnosticoService(test_pdo(), ROOT_PATH);
    $todas = $svc->migraciones();
    assert_true(count($todas) >= 8, 'revisa todas las migraciones de la carpeta');
    assert_eq(array_values(array_filter($todas, static fn (array $m): bool => !$m['aplicada'])), [], 'base nueva: todas aplicadas');

    test_pdo()->exec('SET FOREIGN_KEY_CHECKS = 0');
    test_pdo()->exec('DROP TABLE delegaciones');
    test_pdo()->exec('ALTER TABLE tickets DROP FOREIGN KEY fk_ticket_creado_por');
    test_pdo()->exec('ALTER TABLE tickets DROP COLUMN creado_por_id');
    test_pdo()->exec('SET FOREIGN_KEY_CHECKS = 1');
    $pend = array_values(array_filter($svc->migraciones(), static fn (array $m): bool => !$m['aplicada']));
    assert_eq(array_column($pend, 'archivo'), ['2026-11-30_delegaciones.sql'], 'señala exactamente la que falta');
    assert_eq($pend[0]['faltan'], ['tabla delegaciones', 'columna tickets.creado_por_id'], 'y qué le falta');
}

function test_revisa_extensiones_y_carpetas(): void
{
    $svc = new DiagnosticoService(test_pdo(), ROOT_PATH);
    $ext = array_column($svc->extensiones(), 'ok', 'nombre');
    assert_true(array_key_exists('sodium', $ext) && array_key_exists('gd', $ext) && array_key_exists('fileinfo', $ext), 'incluye las de los módulos');
    $carpetas = array_column($svc->carpetas(), 'ok', 'ruta');
    assert_true(array_key_exists('storage/keys', $carpetas) && array_key_exists('storage/formatos', $carpetas), 'revisa storage/keys y storage/formatos');
}

function test_ultimos_errores_del_registro(): void
{
    $dir = sys_get_temp_dir() . '/diag' . bin2hex(random_bytes(3));
    mkdir($dir . '/storage/logs', 0777, true);
    $lineas = [];
    for ($i = 1; $i <= 60; $i++) {
        $lineas[] = "[27-Nov-2026 10:{$i}] Error {$i}";
    }
    file_put_contents($dir . '/storage/logs/php-error.log', implode("\n", $lineas) . "\n");
    $err = (new DiagnosticoService(test_pdo(), $dir))->ultimosErrores(40);
    assert_eq(count($err), 40, 'solo las últimas 40 líneas');
    assert_eq(end($err), '[27-Nov-2026 10:60] Error 60', 'la más reciente al final');
}
