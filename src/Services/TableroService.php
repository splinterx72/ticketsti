<?php

declare(strict_types=1);

namespace App\Services;

use PDO;

/**
 * Datos de los tableros: "Mi día" del agente y paneles de supervisor y
 * coordinador (indicadores, series para gráficas y tablas por instalación).
 */
final class TableroService
{
    public function __construct(private readonly PDO $pdo)
    {
    }

    /**
     * Tickets abiertos de las instalaciones del agente agrupados como ruta:
     * instalación › ubicación física (edificio, nave, área…) › nivel.
     *
     * @param array<string, mixed> $user
     * @return list<array{id: int, nombre: string, total: int, lugares: list<array<string, mixed>>}>
     */
    public function rutaAgente(array $user): array
    {
        $ids = (new Alcance($this->pdo))->instalacionesDe((int) $user['id']);
        if ($ids === []) {
            return [];
        }
        $in = implode(',', array_map('intval', $ids));
        $stmt = $this->pdo->prepare(
            "SELECT t.instalacion_id, i.nombre AS instalacion,
                    t.reportada_ubicacion_fisica_id AS ubicacion_id, uf.nombre AS ubicacion, uf.indicaciones,
                    t.reportada_nivel_id AS nivel_id, n.nombre AS nivel,
                    COUNT(*) AS total,
                    COALESCE(SUM(t.agente_asignado_id = ?), 0) AS mios,
                    COALESCE(SUM(t.agente_asignado_id IS NULL), 0) AS sin_asignar,
                    COALESCE(SUM(" . SlaService::sqlVencido() . "), 0) AS vencidos
               FROM tickets t
               JOIN estados es ON es.id = t.estado_id
               JOIN instalaciones i ON i.id = t.instalacion_id
               LEFT JOIN ubicaciones_fisicas uf ON uf.id = t.reportada_ubicacion_fisica_id
               LEFT JOIN niveles n ON n.id = t.reportada_nivel_id
              WHERE es.es_cierre = 0 AND t.instalacion_id IN ({$in})
              GROUP BY t.instalacion_id, i.nombre, t.reportada_ubicacion_fisica_id, uf.nombre, uf.indicaciones, t.reportada_nivel_id, n.nombre, n.orden
              ORDER BY i.nombre, (uf.nombre IS NULL), uf.nombre, n.orden"
        );
        $stmt->execute([(int) $user['id']]);
        $out = [];
        foreach ($stmt->fetchAll() as $r) {
            $iid = (int) $r['instalacion_id'];
            $out[$iid] ??= ['id' => $iid, 'nombre' => (string) $r['instalacion'], 'total' => 0, 'lugares' => []];
            $out[$iid]['total'] += (int) $r['total'];
            $out[$iid]['lugares'][] = [
                'ubicacion_id' => $r['ubicacion_id'] !== null ? (int) $r['ubicacion_id'] : null,
                'ubicacion'    => $r['ubicacion'] ?? 'Sin lugar indicado',
                'indicaciones' => $r['indicaciones'],
                'nivel_id'     => $r['nivel_id'] !== null ? (int) $r['nivel_id'] : null,
                'nivel'        => $r['nivel'],
                'total'        => (int) $r['total'],
                'mios'         => (int) $r['mios'],
                'sin_asignar'  => (int) $r['sin_asignar'],
                'vencidos'     => (int) $r['vencidos'],
            ];
        }
        return array_values($out);
    }

    /**
     * Tickets abiertos asignados al agente, los más urgentes primero.
     *
     * @return list<array<string, mixed>>
     */
    public function misAsignados(int $agenteId, int $limite = 12): array
    {
        $stmt = $this->pdo->prepare(
            "SELECT t.id, t.folio, t.titulo, t.prioridad, t.creado_en, t.atendido_en, t.cerrado_en, es.es_cierre,
                    es.nombre AS estado_nombre, es.color_hex AS estado_color, i.nombre AS instalacion,
                    uf.nombre AS ubicacion, n.nombre AS nivel
               FROM tickets t
               JOIN estados es ON es.id = t.estado_id
               LEFT JOIN instalaciones i ON i.id = t.instalacion_id
               LEFT JOIN ubicaciones_fisicas uf ON uf.id = t.reportada_ubicacion_fisica_id
               LEFT JOIN niveles n ON n.id = t.reportada_nivel_id
              WHERE es.es_cierre = 0 AND t.agente_asignado_id = ?
              ORDER BY " . SlaService::sqlVencido() . " DESC, FIELD(t.prioridad, 'critica', 'alta', 'media', 'baja'), t.creado_en
              LIMIT " . max(1, $limite)
        );
        $stmt->execute([$agenteId]);
        return $stmt->fetchAll();
    }

    /**
     * Panel de supervisor/coordinador para un conjunto de instalaciones:
     * indicadores, tendencia de 30 días, semáforo de tiempos, distribuciones
     * y tabla por instalación.
     *
     * @param list<int> $instalaciones
     * @return array<string, mixed>
     */
    public function panel(array $instalaciones): array
    {
        $ids = array_values(array_unique(array_map('intval', $instalaciones)));
        $in = $ids === [] ? '0' : implode(',', $ids);
        $abierto = "es.es_cierre = 0";
        $base = "FROM tickets t JOIN estados es ON es.id = t.estado_id WHERE t.instalacion_id IN ({$in})";

        $k = $this->pdo->query(
            "SELECT COALESCE(SUM({$abierto}), 0) AS abiertos,
                    COALESCE(SUM({$abierto} AND t.agente_asignado_id IS NULL), 0) AS sin_asignar,
                    COALESCE(SUM(" . SlaService::sqlVencido() . "), 0) AS vencidos,
                    COALESCE(SUM(es.es_cierre = 1 AND t.cerrado_en >= NOW() - INTERVAL 7 DAY), 0) AS cerrados_7d
               {$base}"
        )->fetch();
        $res = $this->pdo->query(
            "SELECT COUNT(*) AS n,
                    AVG(TIMESTAMPDIFF(MINUTE, t.creado_en, t.cerrado_en)) / 60 AS horas,
                    SUM(t.cerrado_en <= t.creado_en + INTERVAL s.horas_resolucion HOUR) AS a_tiempo
               FROM tickets t JOIN sla_prioridades s ON s.prioridad = t.prioridad
              WHERE t.instalacion_id IN ({$in}) AND t.cerrado_en >= NOW() - INTERVAL 30 DAY"
        )->fetch();
        $inv = new InventarioService($this->pdo);
        $eq = ['equipos' => 0, 'con_garantia' => 0, 'discrepancias' => 0, 'sin_trabajador' => 0];
        foreach ($inv->resumenPorInstalacion($ids) as $r) {
            foreach ($eq as $c => $v) {
                $eq[$c] = $v + $r[$c];
            }
        }
        $tecnicos = (int) $this->pdo->query(
            "SELECT COUNT(DISTINCT x.agente_id) FROM agente_instalaciones x
              WHERE x.instalacion_id IN ({$in}) AND (x.desde IS NULL OR x.desde <= CURDATE()) AND (x.hasta IS NULL OR x.hasta >= CURDATE())"
        )->fetchColumn();

        // Tendencia: creados y cerrados por día (últimos 30 días).
        $dias = [];
        for ($d = 29; $d >= 0; $d--) {
            $dias[] = date('Y-m-d', strtotime("-{$d} days"));
        }
        $creados = array_fill_keys($dias, 0);
        $cerrados = array_fill_keys($dias, 0);
        foreach ($this->pdo->query("SELECT DATE(t.creado_en) AS d, COUNT(*) AS n FROM tickets t WHERE t.instalacion_id IN ({$in}) AND t.creado_en >= CURDATE() - INTERVAL 29 DAY GROUP BY d") as $r) {
            if (isset($creados[$r['d']])) { $creados[$r['d']] = (int) $r['n']; }
        }
        foreach ($this->pdo->query("SELECT DATE(t.cerrado_en) AS d, COUNT(*) AS n FROM tickets t WHERE t.instalacion_id IN ({$in}) AND t.cerrado_en >= CURDATE() - INTERVAL 29 DAY GROUP BY d") as $r) {
            if (isset($cerrados[$r['d']])) { $cerrados[$r['d']] = (int) $r['n']; }
        }

        // Semáforo de los abiertos.
        $sla = new SlaService($this->pdo);
        $semaforo = ['en_tiempo' => 0, 'por_vencer' => 0, 'vencido' => 0];
        foreach ($this->pdo->query("SELECT t.prioridad, t.creado_en, t.atendido_en, t.cerrado_en {$base} AND {$abierto}") as $t) {
            $e = $sla->estado($t);
            $etapa = in_array($e['respuesta'], ['en_tiempo', 'por_vencer', 'vencido'], true) ? $e['respuesta'] : $e['resolucion'];
            if (isset($semaforo[$etapa])) {
                $semaforo[$etapa]++;
            }
        }

        $porTipo = [];
        foreach ($this->pdo->query("SELECT t.tipo_ticket, COUNT(*) AS n {$base} AND {$abierto} GROUP BY t.tipo_ticket ORDER BY n DESC, t.tipo_ticket") as $r) {
            $porTipo[(string) $r['tipo_ticket']] = (int) $r['n'];
        }

        [$cond, $params] = $inv->condicionInstalaciones($ids);
        $st = $this->pdo->prepare(
            "SELECT COALESCE(e.tipo_componente, 'SIN TIPO') AS tipo, COUNT(*) AS n FROM equipos e
              WHERE e.vigente_en_geca = 1 AND {$cond} GROUP BY tipo ORDER BY n DESC, tipo"
        );
        $st->execute($params);
        $equiposTipo = [];
        foreach ($st->fetchAll() as $r) {
            $equiposTipo[(string) $r['tipo']] = (int) $r['n'];
        }

        $porLugar = $this->pdo->query(
            "SELECT i.nombre AS instalacion, t.instalacion_id, t.reportada_ubicacion_fisica_id AS ubicacion_id,
                    COALESCE(uf.nombre, 'Sin lugar indicado') AS ubicacion, COUNT(*) AS abiertos,
                    COALESCE(SUM(t.agente_asignado_id IS NULL), 0) AS sin_asignar
               FROM tickets t JOIN estados es ON es.id = t.estado_id
               JOIN instalaciones i ON i.id = t.instalacion_id
               LEFT JOIN ubicaciones_fisicas uf ON uf.id = t.reportada_ubicacion_fisica_id
              WHERE t.instalacion_id IN ({$in}) AND {$abierto}
              GROUP BY i.nombre, t.instalacion_id, t.reportada_ubicacion_fisica_id, uf.nombre
              ORDER BY abiertos DESC, i.nombre, ubicacion LIMIT 15"
        )->fetchAll();
        foreach ($porLugar as &$l) {
            $l['abiertos'] = (int) $l['abiertos'];
            $l['sin_asignar'] = (int) $l['sin_asignar'];
        }
        unset($l);

        $porInst = $this->pdo->query(
            "SELECT i.id, i.nombre, z.nombre AS zona,
                    (SELECT COUNT(*) FROM tickets t JOIN estados es ON es.id = t.estado_id WHERE t.instalacion_id = i.id AND es.es_cierre = 0) AS abiertos,
                    (SELECT COUNT(*) FROM tickets t JOIN estados es ON es.id = t.estado_id WHERE t.instalacion_id = i.id AND es.es_cierre = 0 AND t.agente_asignado_id IS NULL) AS sin_asignar,
                    (SELECT COUNT(*) FROM tickets t JOIN estados es ON es.id = t.estado_id WHERE t.instalacion_id = i.id AND " . SlaService::sqlVencido() . ") AS vencidos,
                    (SELECT COUNT(*) FROM tickets t WHERE t.instalacion_id = i.id AND t.cerrado_en >= NOW() - INTERVAL 7 DAY) AS cerrados_7d,
                    (SELECT ROUND(AVG(TIMESTAMPDIFF(MINUTE, t.creado_en, t.cerrado_en)) / 60, 1) FROM tickets t
                      WHERE t.instalacion_id = i.id AND t.cerrado_en >= NOW() - INTERVAL 30 DAY) AS horas,
                    (SELECT COUNT(DISTINCT x.agente_id) FROM agente_instalaciones x WHERE x.instalacion_id = i.id
                        AND (x.desde IS NULL OR x.desde <= CURDATE()) AND (x.hasta IS NULL OR x.hasta >= CURDATE())) AS tecnicos,
                    (SELECT GROUP_CONCAT(u.nombre ORDER BY x.tipo, u.nombre SEPARATOR ', ') FROM supervisor_instalaciones x JOIN usuarios u ON u.id = x.supervisor_id
                      WHERE x.instalacion_id = i.id AND (x.desde IS NULL OR x.desde <= CURDATE()) AND (x.hasta IS NULL OR x.hasta >= CURDATE())) AS supervisores
               FROM instalaciones i LEFT JOIN zonas z ON z.id = i.zona_id
              WHERE i.id IN ({$in})
              ORDER BY z.nombre IS NULL, z.nombre, i.nombre"
        )->fetchAll();
        $equiposInst = $inv->resumenPorInstalacion($ids);
        foreach ($porInst as &$r) {
            $r['equipos'] = $equiposInst[(int) $r['id']]['equipos'] ?? 0;
            foreach (['abiertos', 'sin_asignar', 'vencidos', 'cerrados_7d', 'tecnicos'] as $c) {
                $r[$c] = (int) $r[$c];
            }
        }
        unset($r);

        $cerrados30 = (int) ($res['n'] ?? 0);
        return [
            'kpi' => [
                'abiertos'      => (int) $k['abiertos'],
                'sin_asignar'   => (int) $k['sin_asignar'],
                'vencidos'      => (int) $k['vencidos'],
                'cerrados_7d'   => (int) $k['cerrados_7d'],
                'horas_promedio' => $cerrados30 > 0 ? round((float) $res['horas'], 1) : null,
                'cumplimiento'  => $cerrados30 > 0 ? (int) round(((int) $res['a_tiempo']) * 100 / $cerrados30) : null,
                'tecnicos'      => $tecnicos,
            ] + $eq,
            'serie'          => ['dias' => $dias, 'creados' => array_values($creados), 'cerrados' => array_values($cerrados)],
            'sla'            => $semaforo,
            'por_tipo'       => $porTipo,
            'equipos_tipo'   => $equiposTipo,
            'por_lugar'      => $porLugar,
            'por_instalacion' => $porInst,
        ];
    }
}
