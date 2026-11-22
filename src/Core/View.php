<?php

declare(strict_types=1);

namespace App\Core;

use RuntimeException;

/**
 * Motor de vistas en PHP plano.
 * Las plantillas viven en src/Views y se renderizan dentro de un layout.
 */
final class View
{
    private static string $viewsPath = '';

    /** @var array<string, mixed> variables compartidas con todas las vistas */
    private static array $shared = [];

    public static function setPath(string $path): void
    {
        self::$viewsPath = rtrim($path, '/\\');
    }

    public static function share(string $key, mixed $value): void
    {
        self::$shared[$key] = $value;
    }

    public static function shared(string $key, mixed $default = null): mixed
    {
        return self::$shared[$key] ?? $default;
    }

    /**
     * @param array<string, mixed> $data
     */
    public static function render(string $template, array $data = [], ?string $layout = 'layouts/main'): string
    {
        $content = self::renderFile($template, $data);
        if ($layout === null) {
            return $content;
        }
        return self::renderFile($layout, array_merge($data, ['content' => $content]));
    }

    /**
     * @param array<string, mixed> $data
     */
    private static function renderFile(string $template, array $data): string
    {
        if (preg_match('#^[a-z0-9_/-]+$#i', $template) !== 1 || str_contains($template, '..')) {
            throw new RuntimeException('Nombre de vista inválido.');
        }
        $file = self::$viewsPath . '/' . $template . '.php';
        if (!is_file($file)) {
            throw new RuntimeException("Vista no encontrada: {$template}");
        }

        $vars = array_merge(self::$shared, $data);
        ob_start();
        try {
            (static function (string $__file, array $__vars): void {
                extract($__vars, EXTR_SKIP);
                require $__file;
            })($file, $vars);
        } catch (\Throwable $e) {
            ob_end_clean();
            throw $e;
        }
        return (string) ob_get_clean();
    }
}
