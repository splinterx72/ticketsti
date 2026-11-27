<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Core\Roles;
use App\Config\Database;
use App\Core\Auth;
use App\Core\HttpException;
use App\Core\Session;
use App\Core\ValidationException;
use App\Services\Alcance;
use App\Services\ArchivoService;
use App\Services\Catalog;
use App\Services\GarantiaService;
use App\Services\Mail\Notifier;
use App\Services\TicketService;
use App\Services\UbicacionFisicaService;

/**
 * Portal del trabajador: mis tickets, nuevo ticket, detalle y comentarios.
 * El detalle también lo usa TI (con panel de gestión y notas internas).
 */
final class TicketController extends Controller
{
    private const PER_PAGE = 15;

    private const FORM_FIELDS = [
        'tipo_ticket', 'prioridad', 'titulo', 'descripcion', 'equipo_id',
        'componente_afectado', 'hostname_reportado',
        'reportada_instalacion_id', 'reportada_ubicacion_fisica_id', 'reportada_nivel_id', 'reportada_ala', 'reportada_referencia',
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

        $pdo = Database::getInstance();
        $fisicas = new UbicacionFisicaService($pdo);
        $stmt = $pdo->prepare(
            'SELECT habitual_ubicacion_fisica_id AS ubicacion_fisica_id, habitual_nivel_id AS nivel_id, habitual_ala AS ala
               FROM usuarios WHERE id = ?'
        );
        $stmt->execute([(int) $user['id']]);
        $habitual = ($stmt->fetch() ?: []) + ['referencia' => ''];

        $instalacionGeca = [];
        foreach ($equipos as $eq) {
            $instalacionGeca[(int) $eq['id']] = $fisicas->instalacionDeInmuebleGeca($eq['g_inmueble'] ?? null);
        }

        $this->view('tickets/create', [
            'title'       => 'Nuevo ticket',
            'equipos'     => $equipos,
            'catalogo'    => $fisicas->catalogo(),
            'hayCatalogo' => $fisicas->hayCatalogo(),
            'habitual'    => $habitual,
            'instalacionGeca' => $instalacionGeca,
            'tipos'       => array_intersect_key(Catalog::TIPOS, array_flip(Catalog::TIPOS_USUARIO)),
            'prioridades' => Auth::hasRole(Roles::TI)
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

        $archivos = self::archivosTicket();
        $guardados = [];
        try {
            $guardados = $archivos->guardarVarios(self::subidas('adjuntos'), 'adjuntos', 5);
            $result = (new TicketService(Database::getInstance()))->create(Auth::user(), $input, $guardados);
        } catch (ValidationException $e) {
            $archivos->descartar($guardados);
            foreach ($e->errors() as $error) {
                Session::flash('danger', $error);
            }
            $this->rememberInput(self::FORM_FIELDS);
            redirect('/tickets/nuevo');
        }

        Notifier::ticketCreado($result['id']);
        if ($result['asignado_a'] !== null) {
            Notifier::ticketActualizado($result['id'], 0, false, true);   // aviso al agente asignado automáticamente
        }
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
        $service = new TicketService(Database::getInstance());

        $ticket = $service->find($id);
        [$puedeVer, $esTi] = self::acceso($user, $ticket);
        // 404 (no 403) para no revelar que el folio existe.
        if (!$puedeVer) {
            throw new HttpException(404, 'El ticket solicitado no existe.');
        }

        $this->view('tickets/show', [
            'title'        => $ticket['folio'],
            'ticket'       => $ticket,
            'comentarios'  => $service->comentarios($id, $esTi),
            'adjuntos'     => $service->archivosDe($id, $esTi),
            'garantias'    => $ticket['equipo_id'] !== null
                ? (new GarantiaService(Database::getInstance()))->activasDeEquipo((int) $ticket['equipo_id'])
                : [],
            'esTi'         => $esTi,
            'sla'          => $esTi ? (new \App\Services\SlaService(Database::getInstance()))->estado($ticket) : null,
            'estados'      => $esTi ? Catalog::estados() : [],
            'departamentos' => $esTi ? Catalog::departamentos() : [],
            'agentes'      => $esTi ? Catalog::agentes() : [],
            // Sugerencia en vivo (disponibilidad de hoy) mientras no haya agente.
            'candidatos'   => $esTi && $ticket['agente_asignado_id'] === null && (int) $ticket['es_cierre'] === 0
                ? array_slice((new \App\Services\AsignadorService(Database::getInstance()))->candidatos($id), 0, 3)
                : [],
        ]);
    }

    public function comment(int $id): void
    {
        $this->requireLogin();
        $this->verifyCsrf();
        $user = Auth::user();
        $service = new TicketService(Database::getInstance());

        $ticket = $service->find($id);
        [$puedeVer, $esTi] = self::acceso($user, $ticket);
        if (!$puedeVer) {
            throw new HttpException(404, 'El ticket solicitado no existe.');
        }

        $mensaje = is_string($_POST['mensaje'] ?? null) ? (string) $_POST['mensaje'] : '';
        $archivos = self::archivosTicket();
        $guardados = [];
        try {
            $interna = $esTi && !empty($_POST['es_nota_interna']);
            $guardados = $archivos->guardarVarios(self::subidas('adjuntos'), 'adjuntos', 5);
            $service->addComment($ticket, $user, $mensaje, $interna, $guardados);
            Notifier::comentario($id, (int) $user['id'], trim($mensaje), $interna);
            Session::flash('success', !empty($_POST['es_nota_interna']) && $esTi ? 'Nota interna agregada.' : 'Comentario enviado.');
        } catch (ValidationException $e) {
            $archivos->descartar($guardados);
            foreach ($e->errors() as $error) {
                Session::flash('danger', $error);
            }
            $this->rememberInput(['mensaje']);
        }
        redirect('/tickets/' . $id . '#comentarios');
    }

    /** GET /tickets/archivo/{id}: solo quien puede ver el ticket; los de notas internas, solo TI que lo gestiona. */
    public function archivo(int $id): void
    {
        $this->requireLogin();
        $service = new TicketService(Database::getInstance());
        $a = $service->archivo($id);
        $ticket = $a !== null ? $service->find((int) $a['ticket_id']) : null;
        [$puedeVer, $esTi] = self::acceso(Auth::user(), $ticket);
        if ($a === null || !$puedeVer || ((int) $a['interno'] === 1 && !$esTi)) {
            throw new HttpException(404, 'El archivo no existe.');
        }
        self::archivosTicket()->enviar($a);
    }

    private static function archivosTicket(): ArchivoService
    {
        return new ArchivoService(STORAGE_PATH . '/tickets');
    }

    /**
     * [puede verlo, lo gestiona como TI]. El personal de TI solo gestiona
     * tickets de su alcance (instalación, asignados o sin instalación); fuera
     * de él, si es el solicitante, lo ve como cualquier trabajador.
     *
     * @param array<string, mixed>      $user
     * @param array<string, mixed>|null $ticket
     * @return array{0: bool, 1: bool}
     */
    public static function acceso(array $user, ?array $ticket): array
    {
        if ($ticket === null) {
            return [false, false];
        }
        $alcance = new Alcance(Database::getInstance());
        $comoTi = Roles::esTi($user['rol'] ?? null)
            && $alcance->puedeVerTicket($user, ['usuario_solicitante_id' => 0] + $ticket);
        return [$comoTi || $alcance->puedeVerTicket($user, $ticket), $comoTi];
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
