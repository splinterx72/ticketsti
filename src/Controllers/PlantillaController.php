<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Config\Database;
use App\Core\Auth;
use App\Core\HttpException;
use App\Core\Roles;
use App\Core\Session;
use App\Core\ValidationException;
use App\Services\PlantillaService;
use App\Services\TicketService;

/**
 * Gestión › Plantillas de correo: las ve y usa todo TI con el módulo
 * "plantillas"; las crean y editan supervisores, coordinadores y Admin TI.
 */
final class PlantillaController extends Controller
{
    private const CAMPOS = ['nombre', 'asunto', 'cuerpo', 'activa'];

    public function index(): void
    {
        $this->requireModulo('plantillas', Roles::TI);
        $this->view('plantillas/index', [
            'title'      => 'Plantillas de correo',
            'plantillas' => (new PlantillaService(Database::getInstance()))->todas(),
            'gestiona'   => PlantillaService::puedeGestionar(Auth::user()),
        ]);
    }

    public function create(): void
    {
        $this->gestor();
        $this->view('plantillas/form', ['title' => 'Nueva plantilla', 'p' => ['id' => null, 'nombre' => '', 'asunto' => '', 'cuerpo' => '', 'activa' => 1]]);
    }

    public function store(): void
    {
        $this->gestor();
        $this->verifyCsrf();
        $this->guardar(null);
    }

    public function edit(int $id): void
    {
        $this->gestor();
        $p = (new PlantillaService(Database::getInstance()))->find($id) ?? throw new HttpException(404, 'La plantilla no existe.');
        $this->view('plantillas/form', ['title' => 'Editar plantilla', 'p' => $p]);
    }

    public function update(int $id): void
    {
        $this->gestor();
        $this->verifyCsrf();
        if ((new PlantillaService(Database::getInstance()))->find($id) === null) {
            throw new HttpException(404, 'La plantilla no existe.');
        }
        $this->guardar($id);
    }

    public function destroy(int $id): void
    {
        $this->gestor();
        $this->verifyCsrf();
        (new PlantillaService(Database::getInstance()))->eliminar($id);
        Session::flash('success', 'Plantilla eliminada.');
        redirect('/ti/plantillas');
    }

    /**
     * POST /ti/plantillas/enviar: correo a un trabajador (desde su ficha) o al
     * solicitante de un ticket que el agente gestiona.
     */
    public function send(): void
    {
        $this->requireModulo('plantillas', Roles::TI);
        $this->verifyCsrf();
        $pdo = Database::getInstance();
        $ticketId = (int) $this->input('ticket_id') ?: null;
        $destinatario = (int) $this->input('destinatario_id');
        if ($ticketId !== null) {
            $ticket = (new TicketService($pdo))->find($ticketId);
            if (!TicketController::acceso(Auth::user(), $ticket)[1]) {
                throw new HttpException(404, 'El ticket solicitado no existe.');
            }
            $destinatario = (int) $ticket['usuario_solicitante_id'];
            $volver = '/tickets/' . $ticketId . '#comentarios';
        } else {
            $volver = '/ti/usuarios/' . $destinatario;
        }
        $plantilla = (int) $this->input('plantilla_id') ?: null;
        try {
            (new PlantillaService($pdo))->enviar(Auth::user(), $destinatario, $ticketId, $this->input('asunto'), (string) ($_POST['cuerpo'] ?? ''), $plantilla);
            Session::flash('success', 'Correo enviado.');
        } catch (ValidationException $e) {
            foreach ($e->errors() as $error) {
                Session::flash('danger', $error);
            }
        }
        redirect($volver);
    }

    private function gestor(): void
    {
        $this->requireModulo('plantillas', Roles::SUPERVISION);
    }

    private function guardar(?int $id): void
    {
        try {
            $in = [];
            foreach (self::CAMPOS as $c) {
                $in[$c] = (string) ($_POST[$c] ?? '');
            }
            (new PlantillaService(Database::getInstance()))->guardar($id, $in, Auth::user());
            Session::flash('success', 'Plantilla guardada.');
            redirect('/ti/plantillas');
        } catch (ValidationException $e) {
            foreach ($e->errors() as $error) {
                Session::flash('danger', $error);
            }
            $this->rememberInput(self::CAMPOS);
            redirect($id === null ? '/ti/plantillas/nueva' : '/ti/plantillas/' . $id . '/editar');
        }
    }
}
