<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Core\Roles;
use App\Config\Database;
use App\Core\Auth;
use App\Services\TicketService;

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

        $abiertos = $pdo->prepare(
            'SELECT COUNT(*) FROM tickets t JOIN estados es ON es.id = t.estado_id
              WHERE t.usuario_solicitante_id = ? AND es.es_cierre = 0'
        );
        $abiertos->execute([$userId]);

        $data = [
            'title'         => 'Inicio',
            'esTi'          => $esTi,
            'misTickets'    => $recientes->fetchAll(),
            'misAbiertos'   => (int) $abiertos->fetchColumn(),
            'misEquipos'    => (new TicketService($pdo))->equiposDeUsuario($userId),
            'stats'         => [],
            'ticketStats'   => [],
            'ultimaCarga'   => null,
        ];

        if ($esTi) {
            $data['stats'] = $pdo->query(
                'SELECT
                    (SELECT COUNT(*) FROM usuarios)                              AS usuarios,
                    (SELECT COUNT(*) FROM usuarios WHERE registrado = 1)         AS usuarios_registrados,
                    (SELECT COUNT(*) FROM equipos WHERE vigente_en_geca = 1)     AS equipos_vigentes,
                    (SELECT COUNT(*) FROM equipos WHERE vigente_en_geca = 0)     AS equipos_no_vigentes,
                    (SELECT COUNT(*) FROM equipos
                      WHERE ubicacion_real_id IS NOT NULL
                        AND ubicacion_geca_id IS NOT NULL
                        AND ubicacion_real_id <> ubicacion_geca_id)              AS equipos_discrepancia,
                    (SELECT COUNT(*) FROM ubicaciones)                           AS ubicaciones'
            )->fetch() ?: [];

            $ts = $pdo->prepare(
                "SELECT
                    COALESCE(SUM(es.es_cierre = 0), 0) AS abiertos,
                    COALESCE(SUM(es.es_cierre = 0 AND t.agente_asignado_id IS NULL), 0) AS sin_asignar,
                    COALESCE(SUM(es.es_cierre = 0 AND t.agente_asignado_id = ?), 0) AS mios,
                    COALESCE(SUM(es.es_cierre = 0 AND t.prioridad IN ('alta', 'critica')), 0) AS urgentes,
                    COALESCE(SUM(es.es_cierre = 1 AND DATE(t.cerrado_en) = CURDATE()), 0) AS cerrados_hoy
                   FROM tickets t JOIN estados es ON es.id = t.estado_id"
            );
            $ts->execute([$userId]);
            $data['ticketStats'] = array_map('intval', $ts->fetch() ?: []);

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
