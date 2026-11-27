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

if (!function_exists('estado_badge')) {
    /** Insignia del estado con su color de catálogo y texto legible (claro u oscuro). */
    function estado_badge(string $nombre, string $color): string
    {
        $hex = preg_match('/^#[0-9a-fA-F]{6}$/', $color) === 1 ? $color : '#6c757d';
        [$r, $g, $b] = sscanf($hex, '#%02x%02x%02x');
        $luminance = (0.299 * $r + 0.587 * $g + 0.114 * $b) / 255;
        $text = $luminance > 0.6 ? '#212529' : '#ffffff';
        return '<span class="badge" style="background-color:' . e($hex) . ';color:' . $text . '">' . e($nombre) . '</span>';
    }
}

if (!function_exists('prioridad_badge')) {
    function prioridad_badge(string $prioridad): string
    {
        [$label, $class] = match ($prioridad) {
            'critica' => ['Crítica', 'text-bg-danger'],
            'alta'    => ['Alta', 'text-bg-warning'],
            'media'   => ['Media', 'text-bg-info'],
            default   => ['Baja', 'text-bg-light border'],
        };
        return '<span class="badge ' . $class . '">' . e($label) . '</span>';
    }
}

if (!function_exists('tipo_label')) {
    function tipo_label(string $tipo): string
    {
        return match ($tipo) {
            'incidencia'              => 'Incidencia',
            'solicitud'               => 'Solicitud',
            'garantia'                => 'Garantía',
            'actualizacion_ubicacion' => 'Actualización de ubicación',
            default                   => $tipo,
        };
    }
}

if (!function_exists('componente_label')) {
    function componente_label(string $componente): string
    {
        return match ($componente) {
            'equipo'    => 'Equipo',
            'monitor_1' => 'Monitor 1',
            'monitor_2' => 'Monitor 2',
            default     => 'General',
        };
    }
}

if (!function_exists('texto_multilinea')) {
    /** Escapa y conserva saltos de línea. */
    function texto_multilinea(?string $value): string
    {
        return nl2br(e($value ?? ''), false);
    }
}

if (!function_exists('hace')) {
    /** Tiempo relativo en español: "hace 5 min", "hace 2 h", "hace 3 días". */
    function hace(?string $fecha): string
    {
        if ($fecha === null || ($ts = strtotime($fecha)) === false) {
            return '—';
        }
        $diff = time() - $ts;
        return match (true) {
            $diff < 60        => 'hace un momento',
            $diff < 3600      => 'hace ' . intdiv($diff, 60) . ' min',
            $diff < 86400     => 'hace ' . intdiv($diff, 3600) . ' h',
            $diff < 86400 * 30 => 'hace ' . intdiv($diff, 86400) . ' día' . (intdiv($diff, 86400) === 1 ? '' : 's'),
            default           => date('d/m/Y', $ts),
        };
    }
}

if (!function_exists('query_url')) {
    /** URL con la query actual modificada (para filtros y paginación). */
    function query_url(string $path, array $changes): string
    {
        $params = array_merge($_GET, $changes);
        $params = array_filter($params, static fn (mixed $v): bool => $v !== '' && $v !== null && $v !== 0 && $v !== '0');
        $qs = http_build_query($params);
        return url($path) . ($qs !== '' ? '?' . $qs : '');
    }
}

if (!function_exists('garantia_badge')) {
    /** Estado de garantía con ícono + texto (nunca solo color). */
    function garantia_badge(string $estado): string
    {
        [$label, $class, $icon] = match ($estado) {
            'vigente'        => ['Vigente', 'text-bg-success', 'bi-shield-check'],
            'en_reclamacion' => ['En reclamación', 'text-bg-warning', 'bi-tools'],
            default          => ['Vencida', 'text-bg-secondary', 'bi-shield-x'],
        };
        return '<span class="badge ' . $class . '"><i class="bi ' . $icon . ' me-1"></i>' . e($label) . '</span>';
    }
}

if (!function_exists('partial')) {
    /** Renderiza src/Views/partials/{nombre}.php sin layout. @param array<string, mixed> $data */
    function partial(string $nombre, array $data = []): string
    {
        return \App\Core\View::render('partials/' . $nombre, $data, null);
    }
}

if (!function_exists('ubicacion_usuario')) {
    /**
     * Etiqueta de la ubicación física habitual del usuario con sesión
     * ("Instalación › Ubicación › Nivel X › Ala Y") o null si no la ha capturado.
     *
     * @param array<string, mixed> $user
     */
    function ubicacion_usuario(array $user): ?string
    {
        if (empty($user['habitual_ubicacion_fisica_id'])) {
            return null;
        }
        try {
            return (new \App\Services\UbicacionFisicaService(\App\Config\Database::getInstance()))->etiqueta(
                (int) $user['habitual_ubicacion_fisica_id'],
                $user['habitual_nivel_id'] !== null ? (int) $user['habitual_nivel_id'] : null,
                $user['habitual_ala'] ?? null
            ) ?: null;
        } catch (\Throwable) {
            return null;
        }
    }
}

if (!function_exists('tipo_equipo')) {
    /** Nombre legible del tipo de componente del GECA (CPU, Laptop, Monitor...). */
    function tipo_equipo(?string $tipo): string
    {
        $tipo = mb_strtoupper(trim((string) $tipo));
        return match ($tipo) {
            ''          => '',
            'CPU', 'PC' => $tipo,
            'MACBOOK'   => 'MacBook',
            default     => mb_convert_case($tipo, MB_CASE_TITLE),
        };
    }
}

if (!function_exists('sla_badge')) {
    /**
     * Semáforo de tiempo de atención (ícono + texto, nunca solo color).
     * Abierto sin atender: plazo de respuesta; atendido: plazo de resolución.
     *
     * @param array{respuesta: string, resolucion: string, vence_respuesta: string, vence_resolucion: string} $sla
     */
    function sla_badge(array $sla, bool $cerrado): string
    {
        $etapa = $cerrado || !in_array($sla['respuesta'], ['en_tiempo', 'por_vencer', 'vencido'], true) ? 'resolucion' : 'respuesta';
        $estado = $sla[$etapa];
        [$clase, $icono, $texto] = match ($estado) {
            'en_tiempo'       => ['sla-ok', 'bi-clock', 'En tiempo'],
            'por_vencer'      => ['sla-riesgo', 'bi-hourglass-split', 'Por vencer'],
            'vencido'         => ['sla-vencido', 'bi-alarm', 'Vencido'],
            'cumplido'        => ['sla-ok', 'bi-check2', 'Cumplido'],
            default           => ['sla-vencido', 'bi-x', 'Fuera de tiempo'],
        };
        $vence = $etapa === 'respuesta' ? $sla['vence_respuesta'] : $sla['vence_resolucion'];
        $titulo = ($etapa === 'respuesta' ? 'Primera atención' : 'Resolución') . ' a más tardar: ' . fecha_mx($vence);
        return '<span class="sla-badge ' . $clase . '" title="' . e($titulo) . '"><i class="bi ' . $icono . '"></i> '
            . e($texto) . '<span class="visually-hidden"> (' . e($titulo) . ')</span></span>';
    }
}

