<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Config\Database;
use App\Core\Auth;
use App\Core\HttpException;
use App\Core\Session;
use App\Core\ValidationException;
use App\Services\Catalog;
use App\Services\GarantiaService;
use App\Services\Mail\Notifier;
use App\Services\TicketService;

/**
 * Portal del trabajador: mis tickets, nuevo ticket, detalle y comentarios.
 * El detalle también lo usa TI (con panel de gestión y notas internas).
 */
final class TicketController extends Controller
{
    private const PER_PAGE = 15;

    private const FORM_FIELDS = [
        'tipo_ticket', 'prioridad', 'titulo', 'descripcion', 'equipo_id',
        'componente_afectado', 'hostname_reportado', 'ubicacion_reportada_id',
    ];

    public function index(): void
    {
        $this->requireLogin();
        $user = Auth::user();
        $pdo = Database::getInstance();

        $filtro = (string) ($_GET['estado'] ?? 'abiertos');
        if (!in_array($filtro, ['abiertos', 'cerrados', 'todos'], true)) {
            $filtro = 'abiertos';
        }

        $where = 't.usuario_solicitante_id = ?';
        $params = [(int) $user['id']];
        if ($filtro !== 'todos') {
            $where .= ' AND es.es_cierre = ' . ($filtro === 'cerrados' ? '1' : '0');
        }

        $count = $pdo->prepare("SELECT COUNT(*) FROM tickets t JOIN estados es ON es.id = t.estado_id WHERE {$where}");
        $count->execute($params);
        $total = (int) $count->fetchColumn();
        [$page, $pages, $offset] = self::paginate($total);

        $stmt = $pdo->prepare(
            "SELECT t.id, t.folio, t.titulo, t.tipo_ticket, t.prioridad, t.creado_en, t.actualizado_en,
                    t.discrepancia_ubicacion, t.componente_afectado,
                    es.nombre AS estado_nombre, es.color_hex AS estado_color,
                    e.serie, a.nombre AS agente_nombre
               FROM tickets t
               JOIN estados es ON es.id = t.estado_id
               LEFT JOIN equipos e ON e.id = t.equipo_id
               LEFT JOIN usuarios a ON a.id = t.agente_asignado_id
              WHERE {$where}
              ORDER BY t.actualizado_en DESC, t.id DESC
              LIMIT " . self::PER_PAGE . " OFFSET {$offset}"
        );
        $stmt->execute($params);

        $this->view('tickets/index', [
            'title'   => 'Mis tickets',
            'tickets' => $stmt->fetchAll(),
            'filtro'  => $filtro,
            'page'    => $page,
            'pages'   => $pages,
            'total'   => $total,
        ]);
    }

    public function create(): void
    {
        $this->requireLogin();
        $user = Auth::user();
        $service = new TicketService(Database::getInstance());
        $equipos = $service->equiposDeUsuario((int) $user['id']);

        $habitual = Database::getInstance()->prepare('SELECT ubicacion_habitual_id FROM usuarios WHERE id = ?');
        $habitual->execute([(int) $user['id']]);

        $this->view('tickets/create', [
            'title'       => 'Nuevo ticket',
            'equipos'     => $equipos,
            'ubicaciones' => Catalog::ubicaciones(),
            'ubicacionHabitual' => ($h = $habitual->fetchColumn()) !== false && $h !== null ? (int) $h : null,
            'tipos'       => array_intersect_key(Catalog::TIPOS, array_flip(Catalog::TIPOS_USUARIO)),
            'prioridades' => Auth::hasRole(['agente_ti', 'admin_ti'])
                ? Catalog::PRIORIDADES
                : array_intersect_key(Catalog::PRIORIDADES, array_flip(Catalog::PRIORIDADES_USUARIO)),
        ]);
    }

    public function store(): void
    {
        $this->requireLogin();
        $this->verifyCsrf();

        $input = [];
        foreach (self::FORM_FIELDS as $f) {
            $input[$f] = $this->input($f);
        }
        $input['descripcion'] = is_string($_POST['descripcion'] ?? null) ? (string) $_POST['descripcion'] : '';

        try {
            $result = (new TicketService(Database::getInstance()))->create(Auth::user(), $input);
        } catch (ValidationException $e) {
            foreach ($e->errors() as $error) {
                Session::flash('danger', $error);
            }
            $this->rememberInput(self::FORM_FIELDS);
            redirect('/tickets/nuevo');
        }

        Notifier::ticketCreado($result['id']);
        Session::flash('success', "Ticket {$result['folio']} creado. Te avisaremos por aquí cuando TI lo atienda.");
        if ($result['discrepancia']) {
            Session::flash('warning', "La ubicación que indicaste no coincide con la del resguardo GECA. Ya actualizamos la ubicación real del equipo y TI dará seguimiento al cambio de resguardo ({$result['vinculado']}).");
        }
        redirect('/tickets/' . $result['id']);
    }

    public function show(int $id): void
    {
        $this->requireLogin();
        $user = Auth::user();
        $esTi = Auth::hasRole(['agente_ti', 'admin_ti']);
        $service = new TicketService(Database::getInstance());

        $ticket = $service->find($id);
        // 404 (no 403) para no revelar que el folio existe.
        if ($ticket === null || (!$esTi && (int) $ticket['usuario_solicitante_id'] !== (int) $user['id'])) {
            throw new HttpException(404, 'El ticket solicitado no existe.');
        }

        $this->view('tickets/show', [
            'title'        => $ticket['folio'],
            'ticket'       => $ticket,
            'comentarios'  => $service->comentarios($id, $esTi),
            'garantias'    => $ticket['equipo_id'] !== null
                ? (new GarantiaService(Database::getInstance()))->activasDeEquipo((int) $ticket['equipo_id'])
                : [],
            'esTi'         => $esTi,
            'estados'      => $esTi ? Catalog::estados() : [],
            'departamentos' => $esTi ? Catalog::departamentos() : [],
            'agentes'      => $esTi ? Catalog::agentes() : [],
        ]);
    }

    public function comment(int $id): void
    {
        $this->requireLogin();
        $this->verifyCsrf();
        $user = Auth::user();
        $esTi = Auth::hasRole(['agente_ti', 'admin_ti']);
        $service = new TicketService(Database::getInstance());

        $ticket = $service->find($id);
        if ($ticket === null || (!$esTi && (int) $ticket['usuario_solicitante_id'] !== (int) $user['id'])) {
            throw new HttpException(404, 'El ticket solicitado no existe.');
        }

        $mensaje = is_string($_POST['mensaje'] ?? null) ? (string) $_POST['mensaje'] : '';
        try {
            $interna = $esTi && !empty($_POST['es_nota_interna']);
            $service->addComment($ticket, $user, $mensaje, $interna);
            Notifier::comentario($id, (int) $user['id'], trim($mensaje), $interna);
            Session::flash('success', !empty($_POST['es_nota_interna']) && $esTi ? 'Nota interna agregada.' : 'Comentario enviado.');
        } catch (ValidationException $e) {
            foreach ($e->errors() as $error) {
                Session::flash('danger', $error);
            }
            $this->rememberInput(['mensaje']);
        }
        redirect('/tickets/' . $id . '#comentarios');
    }

    /** @return array{0: int, 1: int, 2: int} página, total de páginas, offset */
    public static function paginate(int $total, int $perPage = self::PER_PAGE): array
    {
        $pages = max(1, (int) ceil($total / $perPage));
        $page = (int) ($_GET['p'] ?? 1);
        $page = min(max(1, $page), $pages);
        return [$page, $pages, ($page - 1) * $perPage];
    }
}
