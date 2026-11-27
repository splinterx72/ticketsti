<?php

declare(strict_types=1);

/**
 * Uso: php tests/run.php [Filtro]
 * Ejecuta las funciones test_* de tests/*Test.php (cada una sobre una base limpia).
 */

require __DIR__ . '/bootstrap.php';

$filter = $argv[1] ?? '';
$files = glob(__DIR__ . '/*Test.php') ?: [];
sort($files);

$passed = 0;
$failed = 0;
foreach ($files as $file) {
    if ($filter !== '' && stripos(basename($file), $filter) === false) {
        continue;
    }
    $before = get_defined_functions()['user'];
    require $file;
    $tests = array_values(array_filter(
        array_diff(get_defined_functions()['user'], $before),
        static fn (string $f): bool => str_starts_with($f, 'test_')
    ));

    foreach ($tests as $test) {
        $GLOBALS['__test_failures'] = [];
        try {
            reset_db();
            \App\Services\ModuloService::olvidar();
            $test();
        } catch (Throwable $e) {
            $GLOBALS['__test_failures'][] = 'Excepción ' . $e::class . ': ' . $e->getMessage() . ' (' . basename($e->getFile()) . ':' . $e->getLine() . ')';
        }
        if ($GLOBALS['__test_failures'] === []) {
            $passed++;
            echo "  OK   {$test}\n";
        } else {
            $failed++;
            echo "  FAIL {$test}\n";
            foreach ($GLOBALS['__test_failures'] as $msg) {
                echo "       - {$msg}\n";
            }
        }
    }
}

echo "\n{$passed} OK, {$failed} FALLIDAS\n";
exit($failed > 0 ? 1 : 0);
