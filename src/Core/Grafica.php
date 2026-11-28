<?php

declare(strict_types=1);

namespace App\Core;

/**
 * Gráficas ligeras generadas en el servidor (SVG/HTML, sin librerías externas).
 *
 * Reglas (guía de visualización): marcas delgadas, extremo de dato redondeado
 * de 4 px y base recta, líneas de 2 px, una sola escala, cuadrícula discreta,
 * leyenda + etiqueta directa, el texto nunca en el color de la serie, colores
 * de estado siempre con ícono y texto, y una vista de tabla equivalente.
 * Paleta validada (validate_palette.js): serie 1 #257de0, serie 2 #f47230.
 */
final class Grafica
{
    public const SERIE_1 = '#257de0';
    public const SERIE_2 = '#f47230';
    public const BUENO = '#0aac23';
    public const ALERTA = '#fbb315';
    public const CRITICO = '#dc3c3d';

    /**
     * Líneas por día (p. ej. creados vs. cerrados). Pasar el cursor por un día
     * muestra la guía, los puntos y el detalle (tooltip nativo accesible).
     *
     * @param list<string> $dias aaaa-mm-dd
     * @param list<array{0: string, 1: string, 2: list<int>}> $series [nombre, color, valores]
     */
    public static function lineas(array $dias, array $series, string $titulo): string
    {
        $w = 640;
        $h = 230;
        $izq = 34;
        $der = 74;
        $arr = 12;
        $aba = 26;
        $n = max(1, count($dias));
        $max = 1;
        foreach ($series as [, , $valores]) {
            $max = max($max, ...array_map('intval', $valores ?: [0]));
        }
        $paso = self::pasoEje($max);
        $tope = (int) (ceil($max / $paso) * $paso);
        $x = static fn (int $i): float => $izq + ($n === 1 ? 0 : $i * ($w - $izq - $der) / ($n - 1));
        $y = static fn (int $v): float => $arr + ($h - $arr - $aba) * (1 - $v / $tope);

        $svg = '<svg class="graf-lineas" viewBox="0 0 ' . $w . ' ' . $h . '" role="img" aria-label="' . e($titulo) . '" preserveAspectRatio="xMidYMid meet">';
        for ($v = 0; $v <= $tope; $v += $paso) {
            $yy = round($y($v), 1);
            $svg .= '<line x1="' . $izq . '" x2="' . ($w - $der) . '" y1="' . $yy . '" y2="' . $yy . '" class="' . ($v === 0 ? 'graf-base' : 'graf-rejilla') . '"/>';
            $svg .= '<text x="' . ($izq - 6) . '" y="' . ($yy + 4) . '" class="graf-eje" text-anchor="end">' . $v . '</text>';
        }
        foreach ($dias as $i => $d) {
            if (($i % 7 === 0 && $n - 1 - $i >= 4) || $i === $n - 1) {
                $svg .= '<text x="' . round($x($i), 1) . '" y="' . ($h - 8) . '" class="graf-eje" text-anchor="middle">' . e(date('d/m', (int) strtotime($d))) . '</text>';
            }
        }
        foreach ($series as [$nombre, $color, $valores]) {
            $pts = [];
            foreach ($valores as $i => $v) {
                $pts[] = round($x($i), 1) . ',' . round($y((int) $v), 1);
            }
            $svg .= '<polyline points="' . implode(' ', $pts) . '" fill="none" stroke="' . e($color) . '" stroke-width="2" stroke-linejoin="round" stroke-linecap="round"/>';
            // Etiqueta directa al final de la línea (texto en tinta, marca en color).
            $ultimo = (int) end($valores);
            $svg .= '<circle cx="' . round($x($n - 1), 1) . '" cy="' . round($y($ultimo), 1) . '" r="4" fill="' . e($color) . '" stroke="#fff" stroke-width="2"/>';
            $svg .= '<text x="' . round($x($n - 1) + 9, 1) . '" y="' . round($y($ultimo) + 4, 1) . '" class="graf-etiqueta">' . e($nombre) . ' ' . $ultimo . '</text>';
        }
        // Capa de interacción: una columna por día con guía, puntos y detalle.
        $ancho = ($w - $izq - $der) / max(1, $n - 1);
        foreach ($dias as $i => $d) {
            $detalle = date('d/m/Y', (int) strtotime($d)) . ': ' . implode(' · ', array_map(
                static fn (array $s): string => $s[0] . ' ' . (int) ($s[2][$i] ?? 0),
                $series
            ));
            $svg .= '<g class="graf-dia"><title>' . e($detalle) . '</title>'
                . '<rect x="' . round($x($i) - $ancho / 2, 1) . '" y="' . $arr . '" width="' . round($ancho, 1) . '" height="' . ($h - $arr - $aba) . '" fill="transparent"/>'
                . '<line x1="' . round($x($i), 1) . '" x2="' . round($x($i), 1) . '" y1="' . $arr . '" y2="' . ($h - $aba) . '" class="graf-guia"/>';
            foreach ($series as [, $color, $valores]) {
                $svg .= '<circle cx="' . round($x($i), 1) . '" cy="' . round($y((int) ($valores[$i] ?? 0)), 1) . '" r="4" fill="' . e($color) . '" stroke="#fff" stroke-width="2" class="graf-punto"/>';
            }
            $svg .= '</g>';
        }
        $svg .= '</svg>';

        $leyenda = '<div class="graf-leyenda">';
        foreach ($series as [$nombre, $color]) {
            $leyenda .= '<span><i class="graf-muestra graf-muestra-linea" style="background:' . e($color) . '"></i>' . e($nombre) . '</span>';
        }
        $leyenda .= '</div>';

        $tabla = '<table class="table table-sm small mb-0"><thead><tr><th>Día</th>';
        foreach ($series as [$nombre]) {
            $tabla .= '<th class="text-end">' . e($nombre) . '</th>';
        }
        $tabla .= '</tr></thead><tbody>';
        foreach (array_reverse($dias, true) as $i => $d) {
            $tabla .= '<tr><td>' . e(date('d/m/Y', (int) strtotime($d))) . '</td>';
            foreach ($series as [, , $valores]) {
                $tabla .= '<td class="text-end">' . (int) ($valores[$i] ?? 0) . '</td>';
            }
            $tabla .= '</tr>';
        }
        $tabla .= '</tbody></table>';

        return $leyenda . $svg . self::vistaTabla($tabla);
    }

    /**
     * Barras horizontales de una sola serie, valor en la punta; cada barra puede enlazar.
     *
     * @param list<array{0: string, 1: int, 2?: string|null}> $items [etiqueta, valor, enlace]
     */
    public static function barras(array $items, string $unidad = '', string $color = self::SERIE_1): string
    {
        if ($items === []) {
            return '<div class="small text-body-secondary">Sin datos.</div>';
        }
        $max = max(1, ...array_map(static fn (array $i): int => (int) $i[1], $items));
        $html = '<ul class="graf-barras list-unstyled mb-0">';
        $tabla = '<table class="table table-sm small mb-0"><tbody>';
        foreach ($items as $it) {
            [$etq, $val] = $it;
            $enlace = $it[2] ?? null;
            $pct = round(((int) $val) * 100 / $max, 1);
            $detalle = $etq . ': ' . number_format((int) $val) . ($unidad !== '' ? ' ' . $unidad : '');
            $fila = '<span class="graf-barra-etq" title="' . e($etq) . '">' . e($etq) . '</span>'
                . '<span class="graf-barra-pista"><span class="graf-barra" style="width:' . max($pct, (int) $val > 0 ? 1.5 : 0) . '%;background:' . e($color) . '"></span>'
                . '<span class="graf-barra-valor">' . e(number_format((int) $val)) . '</span></span>';
            $html .= '<li title="' . e($detalle) . '">' . ($enlace !== null ? '<a href="' . e($enlace) . '">' . $fila . '</a>' : $fila) . '</li>';
            $tabla .= '<tr><td>' . e($etq) . '</td><td class="text-end">' . e(number_format((int) $val)) . '</td></tr>';
        }
        $html .= '</ul>';
        $tabla .= '</tbody></table>';
        return $html . self::vistaTabla($tabla);
    }

    /**
     * Barra segmentada de un total en partes (p. ej. semáforo de tiempos).
     *
     * @param list<array{0: string, 1: int, 2: string, 3: string}> $partes [nombre, valor, color, ícono]
     */
    public static function segmentada(array $partes): string
    {
        $total = array_sum(array_map(static fn (array $p): int => (int) $p[1], $partes));
        if ($total === 0) {
            return '<div class="small text-body-secondary">No hay tickets abiertos.</div>';
        }
        $barra = '<div class="graf-segmentos" role="img" aria-label="' . e(implode(', ', array_map(
            static fn (array $p): string => $p[0] . ' ' . (int) $p[1],
            $partes
        ))) . '">';
        $leyenda = '<ul class="graf-leyenda-estado list-unstyled mb-0">';
        foreach ($partes as [$nombre, $valor, $color, $icono]) {
            $valor = (int) $valor;
            $pct = (int) round($valor * 100 / $total);
            if ($valor > 0) {
                $tinta = $color === self::ALERTA ? '#0b0b0b' : '#ffffff';
                $barra .= '<span style="flex:' . $valor . ';background:' . e($color) . ';color:' . $tinta . '" title="' . e("{$nombre}: {$valor} ({$pct} %)") . '">'
                    . ($pct >= 12 ? $pct . ' %' : '') . '</span>';
            }
            $leyenda .= '<li><i class="bi ' . e($icono) . '" style="color:' . e($color) . '"></i> ' . e($nombre)
                . ' <strong>' . $valor . '</strong></li>';
        }
        return $barra . '</div>' . $leyenda . '</ul>';
    }

    /**
     * Anillo de avance (0–100 %) con el porcentaje al centro y el detalle en
     * texto debajo (el texto es la vista equivalente). Al 100 % cambia al color
     * de estado "bueno" con palomita.
     */
    public static function anillo(int $porcentaje, string $detalle, int $tam = 120): string
    {
        $p = max(0, min(100, $porcentaje));
        $r = 42;
        $c = 2 * M_PI * $r;
        $lleno = round($c * $p / 100, 2);
        $color = $p >= 100 ? self::BUENO : self::SERIE_1;
        $svg = '<svg viewBox="0 0 100 100" width="' . $tam . '" height="' . $tam . '" role="img" aria-label="' . e("Avance {$p} %. {$detalle}") . '">'
            . '<circle cx="50" cy="50" r="' . $r . '" fill="none" stroke="#e3e9ed" stroke-width="10"/>'
            . ($p > 0 ? '<circle cx="50" cy="50" r="' . $r . '" fill="none" stroke="' . $color . '" stroke-width="10" stroke-linecap="' . ($p >= 100 ? 'butt' : 'round') . '"'
                . ' stroke-dasharray="' . $lleno . ' ' . round($c, 2) . '" transform="rotate(-90 50 50)"/>' : '')
            . '<text x="50" y="' . ($p >= 100 ? '50' : '55') . '" text-anchor="middle" font-size="20" font-weight="700" fill="#1f2d33">' . $p . '%</text>'
            . ($p >= 100 ? '<text x="50" y="68" text-anchor="middle" font-size="11" fill="#1f2d33">✓ Completa</text>' : '')
            . '</svg>';
        return '<div class="graf-anillo text-center">' . $svg . '<div class="small text-body-secondary mt-1">' . e($detalle) . '</div></div>';
    }

    private static function vistaTabla(string $tabla): string
    {
        return '<details class="graf-tabla"><summary>Ver como tabla</summary><div class="table-responsive">' . $tabla . '</div></details>';
    }

    private static function pasoEje(int $max): int
    {
        foreach ([1, 2, 5, 10, 20, 25, 50, 100, 200, 250, 500, 1000, 2000, 5000] as $p) {
            if ($max / $p <= 5) {
                return $p;
            }
        }
        return (int) ceil($max / 5);
    }
}
