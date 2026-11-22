<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Config\Database;
use App\Core\Auth;

/**
 * Página de inicio con indicadores del inventario.
 */
final class DashboardController extends Controller
{
    public function index(): void
    {
        $this->requireLogin();
        $pdo = Database::getInstance();

        $stats = $pdo->query(
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
        )->fetch();

        $ultimaCarga = $pdo->query(
            'SELECT c.*, u.nombre AS agente
               FROM cargas_geca c
               LEFT JOIN usuarios u ON u.id = c.usuario_ti_id
              ORDER BY c.id DESC
              LIMIT 1'
        )->fetch() ?: null;

        $misEquipos = [];
        $user = Auth::user();
        if ($user !== null) {
            $stmt = $pdo->prepare(
                'SELECT e.serie, e.marca, e.modelo, e.es_principal, e.vigente_en_geca,
                        e.serie_monitor1, e.serie_monitor2, ug.inmueble AS inmueble_geca
                   FROM equipos e
                   LEFT JOIN ubicaciones ug ON ug.id = e.ubicacion_geca_id
                  WHERE e.usuario_id = ?
                  ORDER BY e.es_principal DESC, e.serie'
            );
            $stmt->execute([(int) $user['id']]);
            $misEquipos = $stmt->fetchAll();
        }

        $this->view('dashboard/index', [
            'title'       => 'Inicio',
            'stats'       => $stats ?: [],
            'ultimaCarga' => $ultimaCarga,
            'misEquipos'  => $misEquipos,
            'esTi'        => Auth::hasRole(['agente_ti', 'admin_ti']),
        ]);
    }
}
