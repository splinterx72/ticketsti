<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Core\Roles;
use App\Config\Database;
use App\Core\HttpException;
use App\Services\Catalog;
use App\Services\GarantiaService;

/**
 * Consulta del inventario GECA para TI: búsqueda y ficha de cada equipo.
 */
final class InventarioController extends Controller
{
    private const ROLES = Roles::TI;
    private const PER_PAGE = 30;

    public function index(): void
    {
        $this->requireRole(self::ROLES);
        $pdo = Database::getInstance();
        (new GarantiaService($pdo))->refreshVencidas();

        $q = mb_substr(trim((string) ($_GET['q'] ?? '')), 0, 60);
        $filtro = (string) ($_GET['filtro'] ?? 'vigentes');
        if (!in_array($filtro, ['vigentes', 'no_vigentes', 'discrepancia', 'sin_garantia', 'todos'], true)) {
            $filtro = 'vigentes';
        }

        $where = match ($filtro) {
            'vigentes'     => ['e.vigente_en_geca = 1'],
            'no_vigentes'  => ['e.vigente_en_geca = 0'],
            'discrepancia' => ['e.ubicacion_real_id IS NOT NULL', 'e.ubicacion_geca_id IS NOT NULL', 'e.ubicacion_real_id <> e.ubicacion_geca_id'],
            'sin_garantia' => ['e.vigente_en_geca = 1', "NOT EXISTS (SELECT 1 FROM garantias g WHERE g.equipo_id = e.id AND g.estado <> 'vencida')"],
            default        => ['1 = 1'],
        };
        $params = [];
        if ($q !== '') {
            $like = '%' . addcslashes($q, '%_\\') . '%';
            $where[] = '(e.serie LIKE ? OR e.serie_monitor1 LIKE ? OR e.serie_monitor2 LIKE ? OR u.ficha = ? OR u.nombre LIKE ? OR e.folio_resguardo LIKE ? OR ug.inmueble LIKE ?)';
            array_push($params, $like, $like, $like, $q, $like, $like, $like);
        }
        $whereSql = implode(' AND ', $where);
        $from = 'FROM equipos e
                 LEFT JOIN usuarios u ON u.id = e.usuario_id
                 LEFT JOIN ubicaciones ug ON ug.id = e.ubicacion_geca_id';

        $count = $pdo->prepare("SELECT COUNT(*) {$from} WHERE {$whereSql}");
        $count->execute($params);
        $total = (int) $count->fetchColumn();
        [$page, $pages, $offset] = TicketController::paginate($total, self::PER_PAGE);

        $stmt = $pdo->prepare(
            "SELECT e.id, e.serie, e.marca, e.modelo, e.vigente_en_geca, e.es_principal,
                    e.serie_monitor1, e.serie_monitor2, e.ubicacion_geca_id, e.ubicacion_real_id,
                    u.nombre AS usuario_nombre, u.ficha, ug.inmueble AS inmueble_geca,
                    (SELECT COUNT(*) FROM garantias g WHERE g.equipo_id = e.id AND g.estado <> 'vencida') AS garantias_activas,
                    (SELECT COUNT(*) FROM tickets t WHERE t.equipo_id = e.id) AS tickets_total
             {$from} WHERE {$whereSql}
             ORDER BY e.serie
             LIMIT " . self::PER_PAGE . " OFFSET {$offset}"
        );
        $stmt->execute($params);

        $this->view('inventario/index', [
            'title'   => 'Inventario',
            'equipos' => $stmt->fetchAll(),
            'q'       => $q,
            'filtro'  => $filtro,
            'page'    => $page,
            'pages'   => $pages,
            'total'   => $total,
        ]);
    }

    public function show(int $id): void
    {
        $this->requireRole(self::ROLES);
        $pdo = Database::getInstance();

        $stmt = $pdo->prepare(
            'SELECT e.*, u.nombre AS usuario_nombre, u.ficha, u.email, u.telefono_ext, u.jefe_nombre, u.registrado,
                    c.fecha_carga AS ultima_carga_fecha,
                    ug.eps AS g_eps, ug.subdireccion AS g_subdireccion, ug.gerencia AS g_gerencia, ug.zona AS g_zona, ug.sector AS g_sector, ug.inmueble AS g_inmueble,
                    ur.eps AS r_eps, ur.subdireccion AS r_subdireccion, ur.gerencia AS r_gerencia, ur.zona AS r_zona, ur.sector AS r_sector, ur.inmueble AS r_inmueble
               FROM equipos e
               LEFT JOIN usuarios u ON u.id = e.usuario_id
               LEFT JOIN cargas_geca c ON c.id = e.ultima_carga_geca_id
               LEFT JOIN ubicaciones ug ON ug.id = e.ubicacion_geca_id
               LEFT JOIN ubicaciones ur ON ur.id = e.ubicacion_real_id
              WHERE e.id = ?'
        );
        $stmt->execute([$id]);
        $equipo = $stmt->fetch();
        if (!$equipo) {
            throw new HttpException(404, 'El equipo no existe.');
        }
        $pick = static function (array $r, string $p): array {
            $o = [];
            foreach (['eps', 'subdireccion', 'gerencia', 'zona', 'sector', 'inmueble'] as $f) {
                $o[$f] = $r[$p . $f] ?? '';
            }
            return $o;
        };
        $equipo['ubicacion_geca'] = $equipo['ubicacion_geca_id'] !== null ? Catalog::etiquetaUbicacion($pick($equipo, 'g_')) : null;
        $equipo['ubicacion_real'] = $equipo['ubicacion_real_id'] !== null ? Catalog::etiquetaUbicacion($pick($equipo, 'r_')) : null;

        $tickets = $pdo->prepare(
            'SELECT t.id, t.folio, t.titulo, t.tipo_ticket, t.prioridad, t.componente_afectado, t.creado_en,
                    es.nombre AS estado_nombre, es.color_hex AS estado_color, s.nombre AS solicitante_nombre
               FROM tickets t
               JOIN estados es ON es.id = t.estado_id
               JOIN usuarios s ON s.id = t.usuario_solicitante_id
              WHERE t.equipo_id = ?
              ORDER BY t.id DESC'
        );
        $tickets->execute([$id]);

        $this->view('inventario/show', [
            'title'     => 'Equipo ' . $equipo['serie'],
            'equipo'    => $equipo,
            'garantias' => (new GarantiaService($pdo))->deEquipo($id),
            'tickets'   => $tickets->fetchAll(),
        ]);
    }
}
