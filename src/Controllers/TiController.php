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
use App\Services\AsignadorService;
use App\Services\Catalog;
use App\Services\UbicacionFisicaService;
use App\Services\SlaService;
use App\Services\Mail\Notifier;
use App\Services\TicketService;

/**
 * Bandeja de TI: todos los tickets con filtros, asignación y gestión.
 */
final class TiController extends Controller
{
    private const ROLES = Roles::TI;
    private const PER_PAGE = 25;

    public function inbox(): void
    {
        $this->requireRole(self::ROLES);
        $me = (int) Auth::id();
        $pdo = Database::getInstance();

        $f = [
            'vista'  => (string) ($_GET['vista'] ?? 'abiertos'),
            'estado' => (int) ($_GET['estado'] ?? 0),
            'depto'  => (int) ($_GET['depto'] ?? 0),
            'tipo'   => (string) ($_GET['tipo'] ?? ''),
            'prioridad' => (string) ($_GET['prioridad'] ?? ''),
            'inst'   => (int) ($_GET['inst'] ?? 0),
            'ubic'   => (int) ($_GET['ubic'] ?? 0),
            'nivel'  => (int) ($_GET['nivel'] ?? 0),
            'q'      => trim((string) ($_GET['q'] ?? '')),
        ];
        if (!in_array($f['vista'], ['abiertos', 'mios', 'sin_asignar', 'sla', 'sin_instalacion', 'discrepancias', 'cerrados', 'todos'], true)) {
            $f['vista'] = 'abiertos';
        }
        if (!array_key_exists($f['tipo'], Catalog::TIPOS)) {
            $f['tipo'] = '';
        }
        if (!array_key_exists($f['prioridad'], Catalog::PRIORIDADES)) {
            $f['prioridad'] = '';
        }
        $f['q'] = mb_substr($f['q'], 0, 60);

        // Alcance (spec 7.3): cada quien ve las instalaciones donde está cargado.
        $alcance = new Alcance($pdo);
        $user = Auth::user();
        [$scopeSql, $scopeParams] = $alcance->filtroTicketsSql($user, 't');
        $misInstalaciones = $alcance->instalacionesDe($me);
        if ($f['inst'] > 0 && !in_array($f['inst'], $misInstalaciones, true)) {
            $f['inst'] = 0;
        }
        $where = [$scopeSql];
        $params = $scopeParams;
        switch ($f['vista']) {
            case 'abiertos':
                $where[] = 'es.es_cierre = 0';
                break;
            case 'mios':
                $where[] = 'es.es_cierre = 0 AND t.agente_asignado_id = ?';
                $params[] = $me;
                break;
            case 'sin_asignar':
                $where[] = 'es.es_cierre = 0 AND t.agente_asignado_id IS NULL';
                break;
            case 'sin_instalacion':
                $where[] = 'es.es_cierre = 0 AND t.instalacion_id IS NULL';
                break;
            case 'discrepancias':
                $where[] = 'es.es_cierre = 0 AND t.discrepancia_ubicacion = 1';
                break;
            case 'sla':
                $where[] = SlaService::sqlVencido();
                break;
            case 'cerrados':
                $where[] = 'es.es_cierre = 1';
                break;
        }
        if ($f['estado'] > 0) {
            $where[] = 't.estado_id = ?';
            $params[] = $f['estado'];
        }
        if ($f['depto'] > 0) {
            $where[] = 't.departamento_id = ?';
            $params[] = $f['depto'];
        }
        if ($f['tipo'] !== '') {
            $where[] = 't.tipo_ticket = ?';
            $params[] = $f['tipo'];
        }
        if ($f['prioridad'] !== '') {
            $where[] = 't.prioridad = ?';
            $params[] = $f['prioridad'];
        }
        if ($f['inst'] > 0) {
            $where[] = 't.instalacion_id = ?';
            $params[] = $f['inst'];
        }
        if ($f['ubic'] > 0) {
            $where[] = 't.reportada_ubicacion_fisica_id = ?';
            $params[] = $f['ubic'];
        }
        if ($f['nivel'] > 0) {
            $where[] = 't.reportada_nivel_id = ?';
            $params[] = $f['nivel'];
        }
        if ($f['q'] !== '') {
            $where[] = '(t.folio LIKE ? OR t.titulo LIKE ? OR s.nombre LIKE ? OR s.ficha = ? OR e.serie LIKE ? OR t.hostname_reportado LIKE ?)';
            $like = '%' . addcslashes($f['q'], '%_\\') . '%';
            array_push($params, $like, $like, $like, $f['q'], $like, $like);
        }
        $whereSql = implode(' AND ', $where);
        $from = 'FROM tickets t
                 JOIN estados es ON es.id = t.estado_id
                 JOIN usuarios s ON s.id = t.usuario_solicitante_id
                 JOIN departamentos d ON d.id = t.departamento_id
                 LEFT JOIN usuarios a ON a.id = t.agente_asignado_id
                 LEFT JOIN usuarios sa ON sa.id = t.sugerido_agente_id
                 LEFT JOIN equipos e ON e.id = t.equipo_id
                 LEFT JOIN instalaciones inst ON inst.id = t.instalacion_id';

        $count = $pdo->prepare("SELECT COUNT(*) {$from} WHERE {$whereSql}");
        $count->execute($params);
        $total = (int) $count->fetchColumn();
        [$page, $pages, $offset] = TicketController::paginate($total, self::PER_PAGE);

        $stmt = $pdo->prepare(
            "SELECT t.id, t.folio, t.titulo, t.tipo_ticket, t.prioridad, t.creado_en, t.actualizado_en, t.atendido_en, t.cerrado_en,
                    t.discrepancia_ubicacion, t.componente_afectado, t.agente_asignado_id,
                    t.sugerido_agente_id, sa.nombre AS sugerido_nombre,
                    es.nombre AS estado_nombre, es.color_hex AS estado_color, es.es_cierre,
                    s.nombre AS solicitante_nombre, s.ficha AS solicitante_ficha,
                    d.nombre AS departamento_nombre, a.nombre AS agente_nombre, e.serie,
                    inst.nombre AS instalacion_nombre
             {$from}
             WHERE {$whereSql}
             ORDER BY es.es_cierre ASC,
                      FIELD(t.prioridad, 'critica', 'alta', 'media', 'baja'),
                      t.actualizado_en DESC, t.id DESC
             LIMIT " . self::PER_PAGE . " OFFSET {$offset}"
        );
        $stmt->execute($params);

        $resumen = $pdo->prepare(
            "SELECT
                SUM(es.es_cierre = 0) AS abiertos,
                SUM(es.es_cierre = 0 AND t.agente_asignado_id IS NULL) AS sin_asignar,
                SUM(es.es_cierre = 0 AND t.agente_asignado_id = ?) AS mios,
                SUM(es.es_cierre = 0 AND t.discrepancia_ubicacion = 1) AS discrepancias,
                SUM(es.es_cierre = 0 AND t.prioridad IN ('alta', 'critica')) AS urgentes,
                SUM(es.es_cierre = 0 AND t.instalacion_id IS NULL) AS sin_instalacion,
                SUM(" . SlaService::sqlVencido() . ") AS sla
               FROM tickets t JOIN estados es ON es.id = t.estado_id
              WHERE {$scopeSql}"
        );
        $resumen->execute([$me, ...$scopeParams]);

        $instalaciones = [];
        if ($misInstalaciones !== []) {
            $st = $pdo->prepare('SELECT id, nombre FROM instalaciones WHERE id IN (' . implode(',', array_fill(0, count($misInstalaciones), '?')) . ') ORDER BY nombre');
            $st->execute($misInstalaciones);
            $instalaciones = $st->fetchAll();
        }

        $sla = new SlaService($pdo);
        $tickets = $stmt->fetchAll();
        foreach ($tickets as &$t) {
            $t['sla'] = $sla->estado($t);
        }
        unset($t);
        $filtroLugar = null;
        if ($f['ubic'] > 0) {
            $filtroLugar = (new UbicacionFisicaService($pdo))->etiqueta($f['ubic'], $f['nivel'] > 0 ? $f['nivel'] : null, null);
        }

        $this->view('ti/bandeja', [
            'title'    => 'Bandeja de TI',
            'tickets'  => $tickets,
            'filtroLugar' => $filtroLugar,
            'filtros'  => $f,
            'resumen'  => array_map('intval', $resumen->fetch() ?: []),
            'estados'  => Catalog::estados(),
            'departamentos' => Catalog::departamentos(),
            'instalaciones' => $instalaciones,
            'page'     => $page,
            'pages'    => $pages,
            'total'    => $total,
        ]);
    }

    public function manage(int $id): void
    {
        $this->requireRole(self::ROLES);
        $this->verifyCsrf();
        $service = new TicketService(Database::getInstance());
        $ticket = $service->find($id);
        if (!TicketController::acceso(Auth::user(), $ticket)[1]) {
            throw new HttpException(404, 'El ticket solicitado no existe.');
        }

        $input = [];
        foreach (['estado_id', 'prioridad', 'departamento_id', 'agente_asignado_id', 'folio_remedy'] as $k) {
            $input[$k] = $this->input($k);
        }

        try {
            $n = $service->manage($ticket, Auth::user(), $input);
            if ($n > 0) {
                Notifier::ticketActualizado(
                    $id,
                    (int) Auth::id(),
                    (int) $input['estado_id'] !== (int) $ticket['estado_id'],
                    (int) $input['agente_asignado_id'] !== (int) ($ticket['agente_asignado_id'] ?? 0)
                );
            }
            Session::flash($n > 0 ? 'success' : 'info', $n > 0 ? 'Ticket actualizado.' : 'No hubo cambios.');
        } catch (ValidationException $e) {
            foreach ($e->errors() as $error) {
                Session::flash('danger', $error);
            }
        }
        redirect('/tickets/' . $id);
    }

    /**
     * Acepta la sugerencia de asignación con un clic. Solo se asigna a quien
     * siga siendo candidato hoy (vigente, disponible y con la especialidad).
     */
    public function assignSuggested(int $id): void
    {
        $this->requireRole(self::ROLES);
        $this->verifyCsrf();
        $service = new TicketService(Database::getInstance());
        $ticket = $service->find($id);
        if (!TicketController::acceso(Auth::user(), $ticket)[1]) {
            throw new HttpException(404, 'El ticket solicitado no existe.');
        }
        $back = (string) ($_POST['volver'] ?? '') === 'bandeja' ? '/ti/tickets' : '/tickets/' . $id;
        $agenteId = (int) $this->input('agente_id');
        $candidato = null;
        foreach ((new AsignadorService(Database::getInstance()))->candidatos($id) as $c) {
            if ($c['id'] === $agenteId) {
                $candidato = $c;
            }
        }
        if ($ticket['agente_asignado_id'] !== null) {
            Session::flash('info', 'El ticket ya tiene agente asignado.');
            redirect($back);
        }
        if ($candidato === null) {
            Session::flash('warning', 'Ese agente ya no está disponible para este ticket; revisa la sugerencia actualizada.');
            redirect('/tickets/' . $id);
        }
        $service->manage($ticket, Auth::user(), [
            'estado_id'          => (string) $ticket['estado_id'],
            'prioridad'          => (string) $ticket['prioridad'],
            'departamento_id'    => (string) $ticket['departamento_id'],
            'agente_asignado_id' => (string) $agenteId,
        ]);
        Notifier::ticketActualizado($id, (int) Auth::id(), false, true);
        Session::flash('success', "Ticket {$ticket['folio']} asignado a {$candidato['nombre']}.");
        redirect($back);
    }

    /** POST /ti/mi-estado: el agente marca su propia disponibilidad desde el encabezado. */
    /** Solo supervisión cambia su propio estado; al agente se lo cambia su supervisor. */
    public function myStatus(): void
    {
        $this->requireRole(Roles::SUPERVISION);
        $this->verifyCsrf();
        try {
            (new AsignadorService(Database::getInstance()))->guardarEstado((int) Auth::id(), $this->input('estado'), $this->input('hasta'), (int) Auth::id());
            Session::flash('success', 'Disponibilidad actualizada.');
        } catch (ValidationException $e) {
            foreach ($e->errors() as $error) {
                Session::flash('danger', $error);
            }
        }
        $back = (string) ($_SERVER['HTTP_REFERER'] ?? '');
        $path = (string) parse_url($back, PHP_URL_PATH);
        redirect($path !== '' && str_starts_with($path, '/') && !str_starts_with($path, '//') ? \App\Core\Router::currentPath($path) : '/');
    }

    /** Atajo "Tomar ticket": se asigna al agente actual y pasa a En proceso si estaba Abierto. */
    public function take(int $id): void
    {
        $this->requireRole(self::ROLES);
        $this->verifyCsrf();
        $service = new TicketService(Database::getInstance());
        $ticket = $service->find($id);
        if (!TicketController::acceso(Auth::user(), $ticket)[1]) {
            throw new HttpException(404, 'El ticket solicitado no existe.');
        }

        $abierto = Catalog::estadoIdPorNombre(Catalog::ESTADO_INICIAL);
        $estado = (int) $ticket['estado_id'] === $abierto
            ? Catalog::estadoIdPorNombre(Catalog::ESTADO_EN_PROCESO)
            : (int) $ticket['estado_id'];

        $service->manage($ticket, Auth::user(), [
            'estado_id'          => (string) $estado,
            'prioridad'          => (string) $ticket['prioridad'],
            'departamento_id'    => (string) $ticket['departamento_id'],
            'agente_asignado_id' => (string) Auth::id(),
        ]);
        Notifier::ticketActualizado($id, (int) Auth::id(), $estado !== (int) $ticket['estado_id'], false);
        Session::flash('success', "Tomaste el ticket {$ticket['folio']}.");
        $back = (string) ($_POST['volver'] ?? '');
        redirect($back === 'bandeja' ? '/ti/tickets' : '/tickets/' . $id);
    }
}
