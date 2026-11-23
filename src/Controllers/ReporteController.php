<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Core\Roles;
use App\Config\Database;
use App\Core\Auth;
use App\Services\Alcance;
use App\Services\Catalog;
use App\Services\GarantiaService;
use DateTimeImmutable;

/**
 * Reportes y tablero de agentes (TI).
 */
final class ReporteController extends Controller
{
    private const ROLES = Roles::TI;
    private const MAX_DIAS = 366;

    public function index(): void
    {
        $this->requireRole(self::ROLES);
        [$desde, $hasta] = $this->rango();
        $pdo = Database::getInstance();
        $p = [$desde . ' 00:00:00', $hasta . ' 23:59:59'];
        [$sc, $instalaciones, $instSel] = $this->alcance();

        $kpi = $pdo->prepare(
            "SELECT
                (SELECT COUNT(*) FROM tickets t WHERE t.creado_en BETWEEN ? AND ?{$sc}) AS creados,
                (SELECT COUNT(*) FROM tickets t WHERE t.cerrado_en BETWEEN ? AND ?{$sc}) AS cerrados,
                (SELECT AVG(TIMESTAMPDIFF(MINUTE, t.creado_en, t.cerrado_en)) FROM tickets t WHERE t.cerrado_en BETWEEN ? AND ?{$sc}) AS min_resolucion,
                (SELECT COUNT(*) FROM tickets t WHERE t.creado_en BETWEEN ? AND ? AND t.discrepancia_ubicacion = 1{$sc}) AS discrepancias"
        );
        $kpi->execute([...$p, ...$p, ...$p, ...$p]);
        $kpis = $kpi->fetch() ?: [];
        $abiertos = (int) $pdo->query(
            "SELECT COUNT(*) FROM tickets t JOIN estados es ON es.id = t.estado_id WHERE es.es_cierre = 0{$sc}"
        )->fetchColumn();

        $grupo = function (string $sql) use ($pdo, $p): array {
            $stmt = $pdo->prepare($sql);
            $stmt->execute($p);
            return $stmt->fetchAll();
        };

        $porTipo = array_map(
            static fn (array $r): array => ['etiqueta' => tipo_label((string) $r['clave']), 'total' => (int) $r['total']],
            $grupo("SELECT t.tipo_ticket AS clave, COUNT(*) AS total FROM tickets t WHERE t.creado_en BETWEEN ? AND ?{$sc} GROUP BY t.tipo_ticket ORDER BY total DESC")
        );
        $porDepto = $grupo(
            "SELECT d.nombre AS etiqueta, COUNT(*) AS total
               FROM tickets t JOIN departamentos d ON d.id = t.departamento_id
              WHERE t.creado_en BETWEEN ? AND ?{$sc} GROUP BY d.id, d.nombre ORDER BY total DESC"
        );
        $porInstalacion = $grupo(
            "SELECT COALESCE(i.nombre, 'Sin instalación') AS etiqueta, COUNT(*) AS total
               FROM tickets t LEFT JOIN instalaciones i ON i.id = t.instalacion_id
              WHERE t.creado_en BETWEEN ? AND ?{$sc} GROUP BY i.id, i.nombre ORDER BY total DESC"
        );
        $porComponente = array_map(
            static fn (array $r): array => ['etiqueta' => componente_label((string) $r['clave']), 'total' => (int) $r['total']],
            $grupo("SELECT t.componente_afectado AS clave, COUNT(*) AS total FROM tickets t WHERE t.creado_en BETWEEN ? AND ?{$sc} GROUP BY t.componente_afectado ORDER BY total DESC")
        );
        $porPrioridad = array_map(
            static fn (array $r): array => ['etiqueta' => Catalog::PRIORIDADES[$r['clave']] ?? (string) $r['clave'], 'total' => (int) $r['total']],
            $grupo("SELECT t.prioridad AS clave, COUNT(*) AS total FROM tickets t WHERE t.creado_en BETWEEN ? AND ?{$sc}
                     GROUP BY t.prioridad ORDER BY FIELD(t.prioridad, 'critica', 'alta', 'media', 'baja')")
        );

        $estadoActual = $pdo->query(
            "SELECT es.nombre AS etiqueta, es.color_hex, COUNT(t.id) AS total
               FROM estados es LEFT JOIN tickets t ON t.estado_id = es.id{$sc}
              WHERE es.es_cierre = 0
              GROUP BY es.id, es.nombre, es.color_hex ORDER BY es.id"
        )->fetchAll();

        // Tablero de agentes: el admin ve a todo TI; el supervisor, a sus agentes y a sí mismo.
        $user = Auth::user();
        $agenteFiltro = '';
        if (!(new Alcance($pdo))->veTodo($user)) {
            $ids = array_column((new Alcance($pdo))->agentesDe((int) $user['id']), 'id');
            $ids[] = (int) $user['id'];
            $agenteFiltro = ' AND u.id IN (' . implode(',', array_map('intval', $ids)) . ')';
        }
        $agentes = $pdo->prepare(
            "SELECT u.id, u.nombre,
                    (SELECT COUNT(*) FROM tickets t JOIN estados es ON es.id = t.estado_id
                      WHERE t.agente_asignado_id = u.id AND es.es_cierre = 0{$sc}) AS abiertos,
                    (SELECT COUNT(*) FROM tickets t WHERE t.agente_asignado_id = u.id AND t.cerrado_en BETWEEN ? AND ?{$sc}) AS cerrados,
                    (SELECT AVG(TIMESTAMPDIFF(MINUTE, t.creado_en, t.cerrado_en)) FROM tickets t
                      WHERE t.agente_asignado_id = u.id AND t.cerrado_en BETWEEN ? AND ?{$sc}) AS min_resolucion,
                    (SELECT COUNT(*) FROM comentarios c JOIN tickets t ON t.id = c.ticket_id
                      WHERE c.usuario_id = u.id AND c.creado_en BETWEEN ? AND ?
                        AND c.mensaje NOT LIKE '[Sistema]%'{$sc}) AS respuestas
               FROM usuarios u
              WHERE u.rol IN ('agente_ti', 'supervisor', 'admin_ti') AND u.registrado = 1 AND u.activo = 1{$agenteFiltro}
              ORDER BY cerrados DESC, abiertos DESC, u.nombre"
        );
        $agentes->execute([...$p, ...$p, ...$p]);

        $topEquipos = $grupo(
            "SELECT e.id, e.serie, e.marca, e.modelo, COUNT(*) AS total
               FROM tickets t JOIN equipos e ON e.id = t.equipo_id
              WHERE t.creado_en BETWEEN ? AND ? AND t.tipo_ticket <> 'actualizacion_ubicacion'{$sc}
              GROUP BY e.id, e.serie, e.marca, e.modelo
              HAVING total > 1
              ORDER BY total DESC LIMIT 10"
        );

        $this->view('reportes/index', [
            'title'    => 'Reportes',
            'desde'    => $desde,
            'hasta'    => $hasta,
            'instalaciones' => $instalaciones,
            'instSel'  => $instSel,
            'kpis'     => [
                'creados'        => (int) ($kpis['creados'] ?? 0),
                'cerrados'       => (int) ($kpis['cerrados'] ?? 0),
                'abiertos'       => $abiertos,
                'min_resolucion' => $kpis['min_resolucion'] !== null ? (float) $kpis['min_resolucion'] : null,
                'discrepancias'  => (int) ($kpis['discrepancias'] ?? 0),
            ],
            'porTipo'       => $porTipo,
            'porDepto'      => array_map(static fn (array $r): array => ['etiqueta' => (string) $r['etiqueta'], 'total' => (int) $r['total']], $porDepto),
            'porInstalacion' => array_map(static fn (array $r): array => ['etiqueta' => (string) $r['etiqueta'], 'total' => (int) $r['total']], $porInstalacion),
            'porComponente' => $porComponente,
            'porPrioridad'  => $porPrioridad,
            'estadoActual'  => $estadoActual,
            'agentes'       => $agentes->fetchAll(),
            'topEquipos'    => $topEquipos,
            'garantias'     => (new GarantiaService($pdo))->resumen(),
        ]);
    }

    /**
     * Filtro de instalación para las consultas de reportes (spec 7.3): el
     * admin ve todo; los demás, sus instalaciones vigentes. Los ids son
     * enteros obtenidos de la BD, por eso van en línea con intval().
     *
     * @return array{0: string, 1: list<array{id: int, nombre: string}>, 2: int}
     */
    private function alcance(): array
    {
        $pdo = Database::getInstance();
        $user = Auth::user();
        $alcance = new Alcance($pdo);
        $ids = $alcance->instalacionesDe((int) $user['id']);
        $veTodo = $alcance->veTodo($user);

        $instalaciones = [];
        if ($ids !== []) {
            $instalaciones = $pdo->query(
                'SELECT id, nombre FROM instalaciones WHERE id IN (' . implode(',', array_map('intval', $ids)) . ') ORDER BY nombre'
            )->fetchAll();
        }
        $sel = (int) ($_GET['inst'] ?? 0);
        $sel = $sel > 0 && in_array($sel, $ids, true) ? $sel : 0;
        return [Alcance::filtroReporteSql($ids, $veTodo, $sel), $instalaciones, $sel];
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
              WHERE t.creado_en BETWEEN ? AND ?' . $this->alcance()[0] . '
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
