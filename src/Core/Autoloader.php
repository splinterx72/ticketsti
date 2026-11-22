<?php

declare(strict_types=1);

namespace App\Core;

/**
 * Autoloader PSR-4 nativo (sin Composer).
 *
 * Mapea el namespace raíz "App\" a la carpeta /src:
 *   App\Services\GecaSyncService  ->  src/Services/GecaSyncService.php
 */
final class Autoloader
{
    /** @var array<string, string> prefijo de namespace => directorio base */
    private static array $prefixes = [];

    private static bool $registered = false;

    public static function register(string $prefix, string $baseDir): void
    {
        $prefix = trim($prefix, '\\') . '\\';
        self::$prefixes[$prefix] = rtrim($baseDir, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR;

        if (!self::$registered) {
            spl_autoload_register([self::class, 'load'], true, true);
            self::$registered = true;
        }
    }

    public static function load(string $class): void
    {
        foreach (self::$prefixes as $prefix => $baseDir) {
            if (strncmp($class, $prefix, strlen($prefix)) !== 0) {
                continue;
            }

            $relative = substr($class, strlen($prefix));
            // Evita intentos de path traversal con nombres de clase manipulados.
            if ($relative === '' || preg_match('/[^A-Za-z0-9_\\\\]/', $relative) === 1) {
                return;
            }

            $file = $baseDir . str_replace('\\', DIRECTORY_SEPARATOR, $relative) . '.php';
            if (is_file($file)) {
                require $file;
            }
            return;
        }
    }
}
