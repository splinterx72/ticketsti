<?php

declare(strict_types=1);

/**
 * Funciones auxiliares globales para vistas y controladores.
 */

use App\Core\Csrf;
use App\Core\Router;

if (!function_exists('e')) {
    /** Escapa texto para HTML (anti-XSS). */
    function e(mixed $value): string
    {
        if ($value === null) {
            return '';
        }
        if (is_bool($value)) {
            $value = $value ? '1' : '0';
        }
        return htmlspecialchars((string) $value, ENT_QUOTES | ENT_SUBSTITUTE | ENT_HTML5, 'UTF-8');
    }
}

if (!function_exists('url')) {
    /** URL absoluta-relativa respetando la subcarpeta de instalación. */
    function url(string $path = '/'): string
    {
        return Router::basePath() . '/' . ltrim($path, '/');
    }
}

if (!function_exists('asset')) {
    /** URL de un recurso estático en /assets con cache-busting por fecha de modificación. */
    function asset(string $path): string
    {
        $path = ltrim($path, '/');
        $file = ROOT_PATH . '/assets/' . $path;
        $version = is_file($file) ? (string) filemtime($file) : '1';
        return url('assets/' . $path) . '?v=' . $version;
    }
}

if (!function_exists('csrf_field')) {
    function csrf_field(): string
    {
        return Csrf::field();
    }
}

if (!function_exists('config')) {
    /** Lee un valor de configuración con notación de punto: config('app.name'). */
    function config(string $key, mixed $default = null): mixed
    {
        $value = $GLOBALS['__app_config'] ?? [];
        foreach (explode('.', $key) as $segment) {
            if (!is_array($value) || !array_key_exists($segment, $value)) {
                return $default;
            }
            $value = $value[$segment];
        }
        return $value;
    }
}

if (!function_exists('redirect')) {
    function redirect(string $path): never
    {
        header('Location: ' . url($path), true, 303);
        exit;
    }
}

if (!function_exists('fecha_mx')) {
    /** Formatea una fecha/hora de BD a d/m/Y H:i. */
    function fecha_mx(?string $value, bool $withTime = true): string
    {
        if ($value === null || $value === '') {
            return '—';
        }
        $ts = strtotime($value);
        if ($ts === false) {
            return e($value);
        }
        return date($withTime ? 'd/m/Y H:i' : 'd/m/Y', $ts);
    }
}
