<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Config\Database;
use App\Core\Auth;
use App\Core\HttpException;
use App\Core\Roles;
use App\Core\Session;
use App\Core\ValidationException;
use App\Services\Alcance;
use App\Services\ArchivoService;
use App\Services\Mail\Notifier;
use App\Services\TareaService;

/**
 * Tareas: el supervisor asigna con fecha de entrega y evidencias; cada
 * agente entrega lo suyo; el supervisor acepta o devuelve.
 */
final class TareaController extends Controller
{
    private const CAMPOS = ['titulo', 'instrucciones', 'prioridad', 'entrega_en', 'meta_unidad', 'meta_descripcion', 'meta_cantidad'];

    public function index(): void
    {
        $this->requireModulo('tareas', Roles::TI);
        $user = Auth::user();
        $svc = new TareaService(Database::getInstance());
        $esSupervisor = in_array($user['rol'], Roles::SUPERVISION, true);

        $this->view('tareas/index', [
            'title'        => 'Tareas',
            'esSupervisor' => $esSupervisor,
            'asignadas'    => $esSupervisor ? $svc->asignadasPor($user) : [],
            'mias'         => $svc->deAgente((int) $user['id']),
        ]);
    }

    public function create(): void
    {
        $this->requireModulo('tareas', Roles::SUPERVISION);
        $this->view('tareas/form', [
            'title'   => 'Nueva tarea',
            'conReportes' => (new TareaService(Database::getInstance()))->conReportes(),
            'conLista'    => (new TareaService(Database::getInstance()))->conLista(),
            'agentes' => (new Alcance(Database::getInstance()))->agentesDe((int) Auth::id()),
        ]);
    }

    public function store(): void
    {
        $this->requireModulo('tareas', Roles::SUPERVISION);
        if (empty($_POST) && (int) ($_SERVER['CONTENT_LENGTH'] ?? 0) > 0) {
            Session::flash('danger', 'Los archivos exceden el tamaño máximo permitido por el servidor.');
            redirect('/tareas/nueva');
        }
        $this->verifyCsrf();

        $in = [];
        foreach (self::CAMPOS as $c) {
            $in[$c] = $c === 'instrucciones' && is_string($_POST['instrucciones'] ?? null) ? (string) $_POST['instrucciones'] : $this->input($c);
        }
        $in['agentes'] = array_map('strval', (array) ($_POST['agentes'] ?? []));
        $in['equipos_series'] = is_string($_POST['equipos_series'] ?? null) ? (string) $_POST['equipos_series'] : '';
        $in['evidencias'] = [];
        foreach ((array) ($_POST['evid_tipo'] ?? []) as $i => $tipo) {
            $in['evidencias'][] = ['tipo' => (string) $tipo, 'descripcion' => (string) (($_POST['evid_desc'] ?? [])[$i] ?? '')];
        }

        $archivos = ArchivoService::enProduccion();
        $guardados = [];
        try {
            foreach (self::subidas('referencias') as $up) {
                $guardados[] = $archivos->guardar($up, 'referencia');
            }
            $id = (new TareaService(Database::getInstance()))->crear(Auth::user(), $in, $guardados);
        } catch (ValidationException $e) {
            $archivos->descartar($guardados);
            foreach ($e->errors() as $error) {
                Session::flash('danger', $error);
            }
            $this->rememberInput([...self::CAMPOS, 'equipos_series']);
            redirect('/tareas/nueva');
        }
        Notifier::tareaAsignada($id);
        Session::flash('success', 'Tarea asignada. Los agentes recibirán un correo.');
        redirect('/tareas/' . $id);
    }

    public function show(int $id): void
    {
        $this->requireModulo('tareas', Roles::TI);
        $user = Auth::user();
        $t = (new TareaService(Database::getInstance()))->detalle($id);
        if ($t === null) {
            throw new HttpException(404, 'La tarea no existe.');
        }
        $esRevisor = (int) $t['supervisor_id'] === (int) $user['id'] || $user['rol'] === 'admin_ti';
        $mia = null;
        foreach ($t['asignaciones'] as $a) {
            if ((int) $a['agente_id'] === (int) $user['id']) {
                $mia = $a;
            }
        }
        if (!$esRevisor && $mia === null) {
            throw new HttpException(404, 'La tarea no existe.');
        }

        $this->view('tareas/show', [
            'title'     => $t['folio'],
            'tarea'     => $t,
            'esRevisor' => $esRevisor,
            'mia'       => $mia,
            'categorias' => (new \App\Services\CategoriaService(Database::getInstance()))->todas(true),
        ]);
    }

    public function iniciar(int $id): void
    {
        $this->accion($id, 'iniciar');
    }

    public function entregar(int $id): void
    {
        $this->accion($id, 'entregar');
    }

    public function aceptar(int $id): void
    {
        $this->accion($id, 'aceptar');
    }

    public function devolver(int $id): void
    {
        $this->accion($id, 'devolver');
    }

    /**
     * POST /tareas/asignacion/{id}/intervenir: el agente marca equipos de la
     * lista; si eligió "Sí", se genera un ticket por cada equipo.
     */
    public function intervenir(int $id): void
    {
        $this->requireModulo('tareas', Roles::TI);
        $this->verifyCsrf();
        $svc = new TareaService(Database::getInstance());
        $asig = $svc->asignacion($id);
        if ($asig === null) {
            throw new HttpException(404, 'La tarea no existe.');
        }
        $ticket = $this->input('generar_ticket');
        try {
            if (!in_array($ticket, ['si', 'no'], true)) {
                throw new ValidationException(['Indica si quieres generar un ticket por cada equipo.']);
            }
            $r = $svc->intervenir(
                $id,
                Auth::user(),
                is_array($_POST['equipos'] ?? null) ? array_values(array_filter($_POST['equipos'], 'is_string')) : [],
                is_string($_POST['comentario'] ?? null) ? (string) $_POST['comentario'] : '',
                $ticket === 'si',
                (int) $this->input('categoria_id')
            );
            foreach ($r['tickets'] as $t) {
                Notifier::ticketCreado($t['id']);
            }
            if ($r['tickets'] !== []) {
                Session::flash('success', 'Se generaron ' . count($r['tickets']) . ' ticket(s): ' . implode(', ', array_column($r['tickets'], 'folio')) . '.');
            } else {
                Session::flash('success', 'Equipos marcados como intervenidos.');
            }
            if ($r['completa']) {
                Notifier::tareaCompleta($id);
                Session::flash('info', 'La lista de equipos llegó al 100 %; avisamos al supervisor.');
            }
        } catch (ValidationException $e) {
            foreach ($e->errors() as $error) {
                Session::flash('danger', $error);
            }
        }
        redirect('/tareas/' . (int) $asig['tarea_id'] . '#lista');
    }

    /** POST /tareas/asignacion/{id}/reportar: agrega un reporte a la hoja del agente. */
    public function reportar(int $id): void
    {
        $this->requireModulo('tareas', Roles::TI);
        if (empty($_POST) && (int) ($_SERVER['CONTENT_LENGTH'] ?? 0) > 0) {
            Session::flash('danger', 'Los archivos exceden el tamaño máximo permitido por el servidor.');
            redirect('/tareas');
        }
        $this->verifyCsrf();
        $svc = new TareaService(Database::getInstance());
        $asig = $svc->asignacion($id);
        if ($asig === null) {
            throw new HttpException(404, 'La tarea no existe.');
        }
        $archivos = ArchivoService::enProduccion();
        $guardados = [];
        try {
            foreach (self::subidas('evidencias') as $up) {
                $guardados[] = $archivos->guardar($up, 'evidencias');
            }
            $r = $svc->reportar(
                $id,
                Auth::user(),
                is_string($_POST['texto'] ?? null) ? (string) $_POST['texto'] : '',
                (int) $this->input('cantidad'),
                $guardados
            );
            if ($r['completa']) {
                Notifier::tareaCompleta($id);
                Session::flash('success', '¡Llegaste al 100 %! Avisamos a tu supervisor; ya puedes hacer el cierre.');
            } else {
                Session::flash('success', 'Reporte agregado' . ($r['porcentaje'] !== null ? " · avance {$r['porcentaje']} %." : '.'));
            }
        } catch (ValidationException $e) {
            $archivos->descartar($guardados);
            foreach ($e->errors() as $error) {
                Session::flash('danger', $error);
            }
        }
        redirect('/tareas/' . (int) $asig['tarea_id'] . '#hoja');
    }

    public function archivo(int $id): void
    {
        $this->requireModulo('tareas', Roles::TI);
        $svc = new TareaService(Database::getInstance());
        $a = $svc->archivo($id);
        if ($a === null || !$svc->puedeDescargar(Auth::user(), $a)) {
            throw new HttpException(404, 'El archivo no existe.');
        }
        ArchivoService::enProduccion()->enviar($a);
    }

    // ------------------------------------------------------------------

    private function accion(int $asignacionId, string $accion): void
    {
        $this->requireModulo('tareas', Roles::TI);
        if (empty($_POST) && (int) ($_SERVER['CONTENT_LENGTH'] ?? 0) > 0) {
            Session::flash('danger', 'Los archivos exceden el tamaño máximo permitido por el servidor.');
            redirect('/tareas');
        }
        $this->verifyCsrf();
        $svc = new TareaService(Database::getInstance());
        $asig = $svc->asignacion($asignacionId);
        if ($asig === null) {
            throw new HttpException(404, 'La tarea no existe.');
        }
        $comentario = is_string($_POST['comentario'] ?? null) ? (string) $_POST['comentario'] : '';

        $archivos = ArchivoService::enProduccion();
        $guardados = [];
        try {
            if ($accion === 'entregar') {
                foreach (self::subidas('evidencias') as $up) {
                    $guardados[] = $archivos->guardar($up, 'evidencias');
                }
            }
            $svc->cambiarEstado($asignacionId, Auth::user(), $accion, $comentario, $guardados);
        } catch (ValidationException $e) {
            $archivos->descartar($guardados);
            foreach ($e->errors() as $error) {
                Session::flash('danger', $error);
            }
            redirect('/tareas/' . (int) $asig['tarea_id']);
        }

        match ($accion) {
            'entregar' => Notifier::tareaEntregada($asignacionId),
            'aceptar', 'devolver' => Notifier::tareaRevisada($asignacionId),
            default => null,
        };
        $mensajes = [
            'iniciar' => 'Tarea en proceso.', 'entregar' => 'Cierre enviado al supervisor.',
            'aceptar' => 'Entrega aceptada.', 'devolver' => 'Entrega devuelta con tus comentarios.',
        ];
        Session::flash('success', $mensajes[$accion]);
        redirect('/tareas/' . (int) $asig['tarea_id']);
    }
}
