<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Config\Database;
use App\Services\Catalog;
use App\Services\GarantiaService;
use DateTimeImmutable;

/**
 * Reportes y tablero de agentes (TI).
 */
final class ReporteController extends Controller
{
    private const ROLES = ['agente_ti', 'admin_ti'];
    private const MAX_DIAS = 366;

    public function index(): void
    {
        $this->requireRole(self::ROLES);
        [$desde, $hasta] = $this->rango();
        $pdo = Database::getInstance();
        $p = [$desde . ' 00:00:00', $hasta . ' 23:59:59'];

        $kpi = $pdo->prepare(
            'SELECT
                (SELECT COUNT(*) FROM tickets WHERE creado_en BETWEEN ? AND ?) AS creados,
                (SELECT COUNT(*) FROM tickets WHERE cerrado_en BETWEEN ? AND ?) AS cerrados,
                (SELECT AVG(TIMESTAMPDIFF(MINUTE, creado_en, cerrado_en)) FROM tickets WHERE cerrado_en BETWEEN ? AND ?) AS min_resolucion,
                (SELECT COUNT(*) FROM tickets WHERE creado_en BETWEEN ? AND ? AND discrepancia_ubicacion = 1) AS discrepancias'
        );
        $kpi->execute([...$p, ...$p, ...$p, ...$p]);
        $kpis = $kpi->fetch() ?: [];
        $abiertos = (int) $pdo->query(
            'SELECT COUNT(*) FROM tickets t JOIN estados es ON es.id = t.estado_id WHERE es.es_cierre = 0'
        )->fetchColumn();

        $grupo = function (string $sql) use ($pdo, $p): array {
            $stmt = $pdo->prepare($sql);
            $stmt->execute($p);
            return $stmt->fetchAll();
        };

        $porTipo = array_map(
            static fn (array $r): array => ['etiqueta' => tipo_label((string) $r['clave']), 'total' => (int) $r['total']],
            $grupo('SELECT tipo_ticket AS clave, COUNT(*) AS total FROM tickets WHERE creado_en BETWEEN ? AND ? GROUP BY tipo_ticket ORDER BY total DESC')
        );
        $porDepto = $grupo(
            'SELECT d.nombre AS etiqueta, COUNT(*) AS total
               FROM tickets t JOIN departamentos d ON d.id = t.departamento_id
              WHERE t.creado_en BETWEEN ? AND ? GROUP BY d.id, d.nombre ORDER BY total DESC'
        );
        $porComponente = array_map(
            static fn (array $r): array => ['etiqueta' => componente_label((string) $r['clave']), 'total' => (int) $r['total']],
            $grupo('SELECT componente_afectado AS clave, COUNT(*) AS total FROM tickets WHERE creado_en BETWEEN ? AND ? GROUP BY componente_afectado ORDER BY total DESC')
        );
        $porPrioridad = array_map(
            static fn (array $r): array => ['etiqueta' => Catalog::PRIORIDADES[$r['clave']] ?? (string) $r['clave'], 'total' => (int) $r['total']],
            $grupo("SELECT prioridad AS clave, COUNT(*) AS total FROM tickets WHERE creado_en BETWEEN ? AND ?
                     GROUP BY prioridad ORDER BY FIELD(prioridad, 'critica', 'alta', 'media', 'baja')")
        );

        $estadoActual = $pdo->query(
            'SELECT es.nombre AS etiqueta, es.color_hex, COUNT(t.id) AS total
               FROM estados es LEFT JOIN tickets t ON t.estado_id = es.id
              WHERE es.es_cierre = 0
              GROUP BY es.id, es.nombre, es.color_hex ORDER BY es.id'
        )->fetchAll();

        $agentes = $pdo->prepare(
            "SELECT u.id, u.nombre,
                    (SELECT COUNT(*) FROM tickets t JOIN estados es ON es.id = t.estado_id
                      WHERE t.agente_asignado_id = u.id AND es.es_cierre = 0) AS abiertos,
                    (SELECT COUNT(*) FROM tickets t WHERE t.agente_asignado_id = u.id AND t.cerrado_en BETWEEN ? AND ?) AS cerrados,
                    (SELECT AVG(TIMESTAMPDIFF(MINUTE, t.creado_en, t.cerrado_en)) FROM tickets t
                      WHERE t.agente_asignado_id = u.id AND t.cerrado_en BETWEEN ? AND ?) AS min_resolucion,
                    (SELECT COUNT(*) FROM comentarios c WHERE c.usuario_id = u.id AND c.creado_en BETWEEN ? AND ?
                        AND c.mensaje NOT LIKE '[Sistema]%') AS respuestas
               FROM usuarios u
              WHERE u.rol IN ('agente_ti', 'admin_ti') AND u.registrado = 1 AND u.activo = 1
              ORDER BY cerrados DESC, abiertos DESC, u.nombre"
        );
        $agentes->execute([...$p, ...$p, ...$p]);

        $topEquipos = $grupo(
            'SELECT e.id, e.serie, e.marca, e.modelo, COUNT(*) AS total
               FROM tickets t JOIN equipos e ON e.id = t.equipo_id
              WHERE t.creado_en BETWEEN ? AND ? AND t.tipo_ticket <> \'actualizacion_ubicacion\'
              GROUP BY e.id, e.serie, e.marca, e.modelo
              HAVING total > 1
              ORDER BY total DESC LIMIT 10'
        );

        $this->view('reportes/index', [
            'title'    => 'Reportes',
            'desde'    => $desde,
            'hasta'    => $hasta,
            'kpis'     => [
                'creados'        => (int) ($kpis['creados'] ?? 0),
                'cerrados'       => (int) ($kpis['cerrados'] ?? 0),
                'abiertos'       => $abiertos,
                'min_resolucion' => $kpis['min_resolucion'] !== null ? (float) $kpis['min_resolucion'] : null,
                'discrepancias'  => (int) ($kpis['discrepancias'] ?? 0),
            ],
            'porTipo'       => $porTipo,
            'porDepto'      => array_map(static fn (array $r): array => ['etiqueta' => (string) $r['etiqueta'], 'total' => (int) $r['total']], $porDepto),
            'porComponente' => $porComponente,
            'porPrioridad'  => $porPrioridad,
            'estadoActual'  => $estadoActual,
            'agentes'       => $agentes->fetchAll(),
            'topEquipos'    => $topEquipos,
            'garantias'     => (new GarantiaService($pdo))->resumen(),
        ]);
    }

    /** Descarga CSV (compatible con Excel) de los tickets creados en el periodo. */
    public function export(): void
    {
        $this->requireRole(self::ROLES);
        [$desde, $hasta] = $this->rango();

        $stmt = Database::getInstance()->prepare(
            'SELECT t.folio, t.creado_en, t.cerrado_en, t.tipo_ticket, t.prioridad, t.titulo,
                    es.nombre AS estado, d.nombre AS departamento,
                    s.ficha, s.nombre AS solicitante, a.nombre AS agente,
                    e.serie, t.componente_afectado, t.hostname_reportado, t.discrepancia_ubicacion,
                    CASE WHEN t.cerrado_en IS NULL THEN NULL ELSE ROUND(TIMESTAMPDIFF(MINUTE, t.creado_en, t.cerrado_en) / 60, 1) END AS horas_resolucion
               FROM tickets t
               JOIN estados es ON es.id = t.estado_id
               JOIN departamentos d ON d.id = t.departamento_id
               JOIN usuarios s ON s.id = t.usuario_solicitante_id
               LEFT JOIN usuarios a ON a.id = t.agente_asignado_id
               LEFT JOIN equipos e ON e.id = t.equipo_id
              WHERE t.creado_en BETWEEN ? AND ?
              ORDER BY t.id'
        );
        $stmt->execute([$desde . ' 00:00:00', $hasta . ' 23:59:59']);

        header('Content-Type: text/csv; charset=UTF-8');
        header('Content-Disposition: attachment; filename="tickets_' . $desde . '_a_' . $hasta . '.csv"');
        header('Cache-Control: no-store, private');

        $out = fopen('php://output', 'wb');
        fwrite($out, "\xEF\xBB\xBF");
        fputcsv($out, ['Folio', 'Creado', 'Cerrado', 'Tipo', 'Prioridad', 'Título', 'Estado', 'Departamento',
            'Ficha', 'Solicitante', 'Agente', 'Serie', 'Componente', 'Hostname', 'Discrepancia ubicación', 'Horas de resolución'], ',', '"', '');
        while ($r = $stmt->fetch()) {
            fputcsv($out, [
                $r['folio'], $r['creado_en'], $r['cerrado_en'] ?? '', tipo_label((string) $r['tipo_ticket']),
                Catalog::PRIORIDADES[$r['prioridad']] ?? $r['prioridad'], self::safeCell((string) $r['titulo']), $r['estado'], $r['departamento'],
                $r['ficha'], self::safeCell((string) $r['solicitante']), $r['agente'] ?? '', $r['serie'] ?? '',
                componente_label((string) $r['componente_afectado']), $r['hostname_reportado'] ?? '',
                (int) $r['discrepancia_ubicacion'] === 1 ? 'Sí' : 'No', $r['horas_resolucion'] ?? '',
            ], ',', '"', '');
        }
        fclose($out);
    }

    /**
     * Rango de fechas validado (por defecto, últimos 30 días).
     *
     * @return array{0: string, 1: string}
     */
    private function rango(): array
    {
        $parse = static function (mixed $v): ?DateTimeImmutable {
            if (!is_string($v) || $v === '') {
                return null;
            }
            $d = DateTimeImmutable::createFromFormat('!Y-m-d', $v);
            return $d !== false && $d->format('Y-m-d') === $v ? $d : null;
        };
        $hoy = new DateTimeImmutable('today');
        $hasta = $parse($_GET['hasta'] ?? null) ?? $hoy;
        $desde = $parse($_GET['desde'] ?? null) ?? $hasta->modify('-29 days');
        if ($desde > $hasta) {
            [$desde, $hasta] = [$hasta, $desde];
        }
        if ((int) $desde->diff($hasta)->format('%a') > self::MAX_DIAS) {
            $desde = $hasta->modify('-' . self::MAX_DIAS . ' days');
        }
        return [$desde->format('Y-m-d'), $hasta->format('Y-m-d')];
    }

    /** Evita inyección de fórmulas al abrir el CSV en Excel. */
    private static function safeCell(string $value): string
    {
        return preg_match('/^[=+\-@\t\r]/', $value) === 1 ? "'" . $value : $value;
    }
}
