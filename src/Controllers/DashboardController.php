<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Core\Roles;
use App\Config\Database;
use App\Core\Auth;
use App\Services\Alcance;
use App\Services\AvisoService;
use App\Services\Catalog;
use App\Services\TicketService;
use App\Services\TareaService;
use App\Services\TableroService;
use App\Services\SlaService;

/**
 * Página de inicio: tickets recientes y equipos del usuario; para TI,
 * además, indicadores de la bandeja y del inventario.
 */
final class DashboardController extends Controller
{
    public function index(): void
    {
        $this->requireLogin();
        $pdo = Database::getInstance();
        $user = Auth::user();
        $userId = (int) $user['id'];
        $esTi = Auth::hasRole(Roles::TI);

        $recientes = $pdo->prepare(
            'SELECT t.id, t.folio, t.titulo, t.prioridad, t.actualizado_en,
                    es.nombre AS estado_nombre, es.color_hex AS estado_color
               FROM tickets t JOIN estados es ON es.id = t.estado_id
              WHERE t.usuario_solicitante_id = ?
              ORDER BY es.es_cierre ASC, t.actualizado_en DESC
              LIMIT 5'
        );
        $recientes->execute([$userId]);

        // Tablero personal (spec 7.6 ter): abiertos, en espera de su respuesta, resueltos en 30 días.
        $mios = $pdo->prepare(
            "SELECT COALESCE(SUM(es.es_cierre = 0), 0) AS abiertos,
                    COALESCE(SUM(es.es_cierre = 0 AND es.nombre = ?), 0) AS en_espera,
                    COALESCE(SUM(es.es_cierre = 1 AND t.cerrado_en >= NOW() - INTERVAL 30 DAY), 0) AS resueltos_30
               FROM tickets t JOIN estados es ON es.id = t.estado_id
              WHERE t.usuario_solicitante_id = ?"
        );
        $mios->execute([Catalog::ESTADO_ESPERA_USUARIO, $userId]);
        $misStats = array_map('intval', $mios->fetch() ?: []);

        $data = [
            'title'         => 'Inicio',
            'esTi'          => $esTi,
            'misTickets'    => $recientes->fetchAll(),
            'misAbiertos'   => $misStats['abiertos'] ?? 0,
            'misStats'      => $misStats,
            'misEquipos'    => (new TicketService($pdo))->equiposDeUsuario($userId),
            'avisos'        => (new AvisoService($pdo))->vigentesPara($user),
            'stats'         => [],
            'ticketStats'   => [],
            'ultimaCarga'   => null,
        ];

        if ($esTi) {
            [$scope, $scopeParams] = (new Alcance($pdo))->filtroTicketsSql($user, 't');
            $data['stats'] = $pdo->query(
                'SELECT
                    (SELECT COUNT(*) FROM usuarios)                              AS usuarios,
                    (SELECT COUNT(*) FROM usuarios WHERE registrado = 1)         AS usuarios_registrados,
                    (SELECT COUNT(*) FROM equipos WHERE vigente_en_geca = 1)     AS equipos_vigentes,
                    (SELECT COUNT(*) FROM equipos WHERE vigente_en_geca = 0)     AS equipos_no_vigentes,
                    (SELECT COUNT(*) FROM equipos e
                       JOIN ubicaciones_fisicas uf ON uf.id = e.real_ubicacion_fisica_id
                       JOIN ubicaciones ug ON ug.id = e.ubicacion_geca_id
                       JOIN geca_inmueble_instalacion gi ON gi.inmueble = ug.inmueble
                      WHERE e.vigente_en_geca = 1 AND uf.instalacion_id <> gi.instalacion_id) AS equipos_discrepancia,
                    (SELECT COUNT(*) FROM ubicaciones)                           AS ubicaciones'
            )->fetch() ?: [];

            $ts = $pdo->prepare(
                "SELECT
                    COALESCE(SUM(es.es_cierre = 0), 0) AS abiertos,
                    COALESCE(SUM(es.es_cierre = 0 AND t.agente_asignado_id IS NULL), 0) AS sin_asignar,
                    COALESCE(SUM(es.es_cierre = 0 AND t.agente_asignado_id = ?), 0) AS mios,
                    COALESCE(SUM(es.es_cierre = 0 AND t.prioridad IN ('alta', 'critica')), 0) AS urgentes,
                    COALESCE(SUM(es.es_cierre = 1 AND DATE(t.cerrado_en) = CURDATE()), 0) AS cerrados_hoy,
                    COALESCE(SUM(" . SlaService::sqlVencido() . "), 0) AS sla
                   FROM tickets t JOIN estados es ON es.id = t.estado_id
                  WHERE {$scope}"
            );
            $ts->execute([$userId, ...$scopeParams]);
            $data['ticketStats'] = array_map('intval', $ts->fetch() ?: []);

            // "Mi día": asignados, ruta por edificio y nivel, tareas pendientes.
            $tablero = new TableroService($pdo);
            $sla = new SlaService($pdo);
            $data['asignados'] = array_map(static fn (array $t): array => $t + ['sla' => $sla->estado($t)], $tablero->misAsignados($userId));
            $data['ruta'] = $tablero->rutaAgente($user);
            $data['misTareas'] = array_values(array_filter(
                (new TareaService($pdo))->deAgente($userId),
                static fn (array $t): bool => !in_array($t['estado'], ['entregada', 'aceptada'], true)
            ));

            $data['ultimaCarga'] = $pdo->query(
                'SELECT c.*, u.nombre AS agente
                   FROM cargas_geca c
                   LEFT JOIN usuarios u ON u.id = c.usuario_ti_id
                  ORDER BY c.id DESC
                  LIMIT 1'
            )->fetch() ?: null;
        }

        $this->view('dashboard/index', $data);
    }
}
