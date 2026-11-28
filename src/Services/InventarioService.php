<?php

declare(strict_types=1);

namespace App\Services;

use PDO;

/**
 * Inventario por instalación. La instalación de un equipo es la de su
 * ubicación física real (último reporte); si no tiene, la equivalencia del
 * inmueble GECA. Las condiciones usan los índices de equipos (alias "e").
 */
final class InventarioService
{
    public function __construct(private readonly PDO $pdo)
    {
    }

    /**
     * @param list<int> $instalaciones
     * @return array{0: string, 1: list<int>} condición SQL y parámetros
     */
    public function condicionInstalaciones(array $instalaciones): array
    {
        $ids = array_values(array_unique(array_map('intval', $instalaciones)));
        if ($ids === []) {
            return ['1 = 0', []];
        }
        $in = implode(',', array_fill(0, count($ids), '?'));
        $sql = "((e.real_ubicacion_fisica_id IS NOT NULL
                  AND e.real_ubicacion_fisica_id IN (SELECT uf.id FROM ubicaciones_fisicas uf WHERE uf.instalacion_id IN ({$in})))
              OR (e.real_ubicacion_fisica_id IS NULL
                  AND e.ubicacion_geca_id IN (SELECT ugi.id FROM ubicaciones ugi JOIN geca_inmueble_instalacion gi ON gi.inmueble = ugi.inmueble
                                               WHERE gi.instalacion_id IN ({$in}))))";
        return [$sql, array_merge($ids, $ids)];
    }

    /** Equipos sin ubicación física y cuyo inmueble GECA no tiene equivalencia. */
    public function condicionSinInstalacion(): string
    {
        return '(e.real_ubicacion_fisica_id IS NULL
                 AND (e.ubicacion_geca_id IS NULL
                      OR e.ubicacion_geca_id NOT IN (SELECT ugs.id FROM ubicaciones ugs JOIN geca_inmueble_instalacion gs ON gs.inmueble = ugs.inmueble)))';
    }

    /**
     * Indicadores de equipos vigentes por instalación (panel de supervisión).
     *
     * @param list<int> $instalaciones
     * @return array<int, array{equipos: int, con_garantia: int, discrepancias: int, sin_trabajador: int}>
     */
    public function resumenPorInstalacion(array $instalaciones): array
    {
        $out = [];
        foreach (array_unique(array_map('intval', $instalaciones)) as $id) {
            [$cond, $params] = $this->condicionInstalaciones([$id]);
            $stmt = $this->pdo->prepare(
                "SELECT COUNT(*) AS equipos,
                        COALESCE(SUM(EXISTS (SELECT 1 FROM garantias g WHERE g.equipo_id = e.id AND g.estado <> 'vencida')), 0) AS con_garantia,
                        COALESCE(SUM(e.real_ubicacion_fisica_id IS NOT NULL AND EXISTS (
                            SELECT 1 FROM ubicaciones_fisicas uf JOIN ubicaciones ug ON ug.id = e.ubicacion_geca_id
                              JOIN geca_inmueble_instalacion gi ON gi.inmueble = ug.inmueble
                             WHERE uf.id = e.real_ubicacion_fisica_id AND uf.instalacion_id <> gi.instalacion_id)), 0) AS discrepancias,
                        COALESCE(SUM(e.usuario_id IS NULL), 0) AS sin_trabajador
                   FROM equipos e
                  WHERE e.vigente_en_geca = 1 AND {$cond}"
            );
            $stmt->execute($params);
            $out[$id] = array_map('intval', $stmt->fetch());
        }
        return $out;
    }

    /**
     * Equipos de una instalación según SOLO el GECA: equipos vigentes cuyo
     * inmueble GECA está relacionado con la instalación, agrupados por
     * inmueble, referencia de ubicación (texto del GECA, normalizado) y tipo.
     * Los monitores casados cuentan como MONITOR.
     *
     * @return array{total: int, inmuebles: array<string, int>, tipos: array<string, int>,
     *               filas: list<array{inmueble: string, referencia: string, total: int, tipos: array<string, int>}>,
     *               con_equivalencia: bool}
     */
    public function gecaPorUbicacion(int $instalacionId): array
    {
        $eq = $this->pdo->prepare('SELECT COUNT(*) FROM geca_inmueble_instalacion WHERE instalacion_id = ?');
        $eq->execute([$instalacionId]);
        $stmt = $this->pdo->prepare(
            "SELECT ug.inmueble, COALESCE(e.ubicacion_referencia, '') AS referencia,
                    COALESCE(NULLIF(UPPER(TRIM(e.tipo_componente)), ''), 'SIN TIPO') AS tipo,
                    COUNT(*) AS equipos,
                    COALESCE(SUM(e.serie_monitor1 IS NOT NULL AND e.serie_monitor1 <> ''), 0)
                      + COALESCE(SUM(e.serie_monitor2 IS NOT NULL AND e.serie_monitor2 <> ''), 0) AS monitores
               FROM equipos e
               JOIN ubicaciones ug ON ug.id = e.ubicacion_geca_id
               JOIN geca_inmueble_instalacion gi ON gi.inmueble = ug.inmueble
              WHERE e.vigente_en_geca = 1 AND gi.instalacion_id = ?
              GROUP BY ug.inmueble, COALESCE(e.ubicacion_referencia, ''), COALESCE(NULLIF(UPPER(TRIM(e.tipo_componente)), ''), 'SIN TIPO')"
        );
        $stmt->execute([$instalacionId]);

        $filas = [];
        $inmuebles = [];
        $tipos = [];
        $total = 0;
        foreach ($stmt->fetchAll() as $r) {
            $inmueble = (string) $r['inmueble'];
            $ref = self::referencia((string) $r['referencia']);
            $key = $inmueble . "\x1F" . $ref;
            $filas[$key] ??= ['inmueble' => $inmueble, 'referencia' => $ref, 'total' => 0, 'tipos' => []];
            foreach ([(string) $r['tipo'] => (int) $r['equipos'], 'MONITOR' => (int) $r['monitores']] as $tipo => $n) {
                if ($n === 0) {
                    continue;
                }
                $filas[$key]['tipos'][$tipo] = ($filas[$key]['tipos'][$tipo] ?? 0) + $n;
                $filas[$key]['total'] += $n;
                $inmuebles[$inmueble] = ($inmuebles[$inmueble] ?? 0) + $n;
                $tipos[$tipo] = ($tipos[$tipo] ?? 0) + $n;
                $total += $n;
            }
        }
        $filas = array_values($filas);
        usort($filas, static fn (array $a, array $b): int => [$a['inmueble'], $b['total']] <=> [$b['inmueble'], $a['total']]);
        arsort($inmuebles);
        arsort($tipos);
        return ['total' => $total, 'inmuebles' => $inmuebles, 'tipos' => $tipos, 'filas' => $filas, 'con_equivalencia' => (int) $eq->fetchColumn() > 0];
    }

    /** Referencia de ubicación del GECA normalizada (mayúsculas, espacios simples); vacía = "Sin referencia". */
    public static function referencia(string $texto): string
    {
        $t = mb_strtoupper(trim((string) preg_replace('/\s+/u', ' ', $texto)));
        $t = trim($t, " .,;:-");
        return $t === '' ? 'Sin referencia' : $t;
    }
}
