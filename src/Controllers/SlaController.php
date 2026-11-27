<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Config\Database;
use App\Core\Auth;
use App\Core\Session;
use App\Core\ValidationException;
use App\Services\SlaService;

/**
 * Gestión › Tiempos de atención (SLA): horas de respuesta y de resolución por prioridad.
 */
final class SlaController extends Controller
{
    public function index(): void
    {
        $this->requireRole(['admin_ti']);
        $this->view('sla/index', [
            'title'  => 'Tiempos de atención',
            'config' => (new SlaService(Database::getInstance()))->config(),
        ]);
    }

    public function save(): void
    {
        $this->requireRole(['admin_ti']);
        $this->verifyCsrf();
        try {
            (new SlaService(Database::getInstance()))->guardar((array) ($_POST['sla'] ?? []), Auth::user());
            Session::flash('success', 'Tiempos de atención guardados. Aplican a todos los tickets abiertos desde este momento.');
        } catch (ValidationException $e) {
            foreach ($e->errors() as $error) {
                Session::flash('danger', $error);
            }
        }
        redirect('/ti/sla');
    }
}
