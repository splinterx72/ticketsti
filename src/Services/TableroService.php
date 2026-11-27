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
}
