<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Core\Roles;
use App\Config\Database;
use App\Core\Auth;
use App\Core\HttpException;
use App\Core\Session;
use App\Core\ValidationException;
use App\Services\GarantiaService;

/**
 * Módulo de garantías (TI).
 */
final class GarantiaController extends Controller
{
    private const ROLES = Roles::TI;
    private const PER_PAGE = 25;
    private const FIELDS = ['serie', 'elemento_cubierto', 'proveedor', 'numero_poliza_contrato', 'fecha_inicio', 'fecha_vencimiento', 'estado', 'notas'];

    /** GET /garantias: historial de solo lectura de las garantías de mis equipos. */
    public function mine(): void
    {
        $this->requireModulo('garantias');
        $this->view('garantias/mias', [
            'title'     => 'Mis garantías',
            'garantias' => (new GarantiaService(Database::getInstance()))->deUsuario((int) Auth::id()),
        ]);
    }

    public function index(): void
    {
        $this->requireModulo('garantias', self::ROLES);
        $pdo = Database::getInstance();
        $service = new GarantiaService($pdo);
        $resumen = $service->resumen();

        $vista = (string) ($_GET['vista'] ?? 'vigentes');
        if (!in_array($vista, ['vigentes', 'por_vencer', 'en_reclamacion', 'vencidas', 'todas'], true)) {
            $vista = 'vigentes';
        }
        $q = mb_substr(trim((string) ($_GET['q'] ?? '')), 0, 60);

        $where = match ($vista) {
            'vigentes'       => ["g.estado = 'vigente'"],
            'por_vencer'     => ["g.estado = 'vigente'", 'g.fecha_vencimiento <= CURDATE() + INTERVAL ' . GarantiaService::DIAS_POR_VENCER . ' DAY'],
            'en_reclamacion' => ["g.estado = 'en_reclamacion'"],
            'vencidas'       => ["g.estado = 'vencida'"],
            default          => ['1 = 1'],
        };
        $params = [];
        if ($q !== '') {
            $like = '%' . addcslashes($q, '%_\\') . '%';
            $where[] = '(e.serie LIKE ? OR e.serie_monitor1 LIKE ? OR e.serie_monitor2 LIKE ? OR g.proveedor LIKE ? OR g.numero_poliza_contrato LIKE ? OR u.nombre LIKE ?)';
            array_push($params, $like, $like, $like, $like, $like, $like);
        }
        $whereSql = implode(' AND ', $where);
        $from = 'FROM garantias g JOIN equipos e ON e.id = g.equipo_id LEFT JOIN usuarios u ON u.id = e.usuario_id';

        $count = $pdo->prepare("SELECT COUNT(*) {$from} WHERE {$whereSql}");
        $count->execute($params);
        $total = (int) $count->fetchColumn();
        [$page, $pages, $offset] = TicketController::paginate($total, self::PER_PAGE);

        $order = $vista === 'vencidas' ? 'g.fecha_vencimiento DESC' : 'g.fecha_vencimiento ASC';
        $stmt = $pdo->prepare(
            "SELECT g.*, e.id AS equipo_id, e.serie, e.marca, e.modelo, e.serie_monitor1, e.serie_monitor2,
                    u.nombre AS usuario_nombre
             {$from} WHERE {$whereSql}
             ORDER BY {$order}, g.id DESC
             LIMIT " . self::PER_PAGE . " OFFSET {$offset}"
        );
        $stmt->execute($params);

        $this->view('garantias/index', [
            'title'     => 'Garantías',
            'garantias' => $stmt->fetchAll(),
            'resumen'   => $resumen,
            'vista'     => $vista,
            'q'         => $q,
            'page'      => $page,
            'pages'     => $pages,
            'total'     => $total,
        ]);
    }

    public function create(): void
    {
        $this->requireModulo('garantias', self::ROLES);
        $serie = '';
        $elemento = 'equipo';
        $equipoId = (int) ($_GET['equipo'] ?? 0);
        if ($equipoId > 0) {
            $stmt = Database::getInstance()->prepare('SELECT serie FROM equipos WHERE id = ?');
            $stmt->execute([$equipoId]);
            $serie = (string) ($stmt->fetchColumn() ?: '');
        }
        $elementoGet = (string) ($_GET['elemento'] ?? '');
        if (array_key_exists($elementoGet, GarantiaService::ELEMENTOS)) {
            $elemento = $elementoGet;
        }

        $this->view('garantias/form', [
            'title'    => 'Registrar garantía',
            'garantia' => [
                'id' => null, 'serie' => $serie, 'elemento_cubierto' => $elemento, 'proveedor' => '',
                'numero_poliza_contrato' => '', 'fecha_inicio' => date('Y-m-d'),
                'fecha_vencimiento' => date('Y-m-d', strtotime('+1 year')), 'estado' => 'vigente', 'notas' => '',
            ],
        ]);
    }

    public function store(): void
    {
        $this->requireModulo('garantias', self::ROLES);
        $this->verifyCsrf();
        try {
            $id = (new GarantiaService(Database::getInstance()))->save(null, $this->collect(), (int) Auth::id());
        } catch (ValidationException $e) {
            $this->fail($e, '/ti/garantias/nueva');
        }
        $equipo = $this->equipoDeGarantia($id);
        Session::flash('success', 'Garantía registrada.');
        redirect('/ti/equipos/' . $equipo);
    }

    public function edit(int $id): void
    {
        $this->requireModulo('garantias', self::ROLES);
        $g = (new GarantiaService(Database::getInstance()))->find($id);
        if ($g === null) {
            throw new HttpException(404, 'La garantía no existe.');
        }
        $this->view('garantias/form', ['title' => 'Editar garantía', 'garantia' => $g]);
    }

    public function update(int $id): void
    {
        $this->requireModulo('garantias', self::ROLES);
        $this->verifyCsrf();
        $service = new GarantiaService(Database::getInstance());
        if ($service->find($id) === null) {
            throw new HttpException(404, 'La garantía no existe.');
        }
        try {
            $service->save($id, $this->collect(), (int) Auth::id());
        } catch (ValidationException $e) {
            $this->fail($e, '/ti/garantias/' . $id . '/editar');
        }
        Session::flash('success', 'Garantía actualizada.');
        redirect('/ti/equipos/' . $this->equipoDeGarantia($id));
    }

    public function destroy(int $id): void
    {
        $this->requireRole(['admin_ti']);
        $this->verifyCsrf();
        $service = new GarantiaService(Database::getInstance());
        $g = $service->find($id);
        if ($g === null) {
            throw new HttpException(404, 'La garantía no existe.');
        }
        $service->delete($id);
        Session::flash('success', 'Garantía eliminada.');
        redirect('/ti/equipos/' . (int) $g['equipo_id']);
    }

    /** @return array<string, string> */
    private function collect(): array
    {
        $in = [];
        foreach (self::FIELDS as $f) {
            $in[$f] = $f === 'notas' && is_string($_POST['notas'] ?? null) ? (string) $_POST['notas'] : $this->input($f);
        }
        return $in;
    }

    private function fail(ValidationException $e, string $back): never
    {
        foreach ($e->errors() as $error) {
            Session::flash('danger', $error);
        }
        $this->rememberInput(self::FIELDS);
        redirect($back);
    }

    private function equipoDeGarantia(int $id): int
    {
        $stmt = Database::getInstance()->prepare('SELECT equipo_id FROM garantias WHERE id = ?');
        $stmt->execute([$id]);
        return (int) $stmt->fetchColumn();
    }
}
