<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Core\Roles;
use App\Config\Database;
use App\Core\Auth;
use App\Core\HttpException;
use App\Services\Catalog;
use App\Services\GarantiaService;
use App\Services\TicketService;
use App\Services\UbicacionFisicaService;

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
            'discrepancia' => ['EXISTS (SELECT 1 FROM ubicaciones_fisicas uf JOIN geca_inmueble_instalacion gi ON gi.inmueble = ug.inmueble
                                         WHERE uf.id = e.real_ubicacion_fisica_id AND uf.instalacion_id <> gi.instalacion_id)'],
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
            "SELECT e.id, e.usuario_id, e.serie, e.tipo_componente, e.estado_resguardo, e.marca, e.modelo, e.vigente_en_geca, e.es_principal,
                    e.serie_monitor1, e.serie_monitor2, e.ubicacion_geca_id, e.ubicacion_real_id,
                    EXISTS (SELECT 1 FROM ubicaciones_fisicas uf JOIN geca_inmueble_instalacion gi ON gi.inmueble = ug.inmueble
                             WHERE uf.id = e.real_ubicacion_fisica_id AND uf.instalacion_id <> gi.instalacion_id) AS discrepancia,
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
            'SELECT e.*, u.nombre AS usuario_nombre, u.ficha, u.email, u.telefono_ext, u.jefe_ficha, u.jefe_nombre, u.registrado,
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
        $fisicas = new UbicacionFisicaService($pdo);
        $equipo['ubicacion_real'] = $equipo['real_ubicacion_fisica_id'] !== null
            ? $fisicas->etiqueta((int) $equipo['real_ubicacion_fisica_id'], $equipo['real_nivel_id'] !== null ? (int) $equipo['real_nivel_id'] : null, $equipo['real_ala'])
            : null;
        $instReal = $equipo['real_ubicacion_fisica_id'] !== null ? $fisicas->instalacionDeUbicacion((int) $equipo['real_ubicacion_fisica_id']) : null;
        $instGeca = $fisicas->instalacionDeInmuebleGeca($equipo['g_inmueble'] ?? null);
        $equipo['discrepancia'] = $instReal !== null && $instGeca !== null && $instReal !== $instGeca;


        $this->view('inventario/show', [
            'title'     => 'Equipo ' . $equipo['serie'],
            'equipo'    => $equipo,
            'garantias' => (new GarantiaService($pdo))->deEquipo($id),
            'tickets'   => (new TicketService($pdo))->historialEquipo($id, Auth::user()),
        ]);
    }
}
