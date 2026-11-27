<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Config\Database;
use App\Core\Auth;
use App\Core\Session;
use App\Core\ValidationException;
use App\Services\Mail\CorreoAjustes;

/**
 * Gestión › Correo y Remedy (solo Admin TI): correo de la mesa institucional,
 * copias fijas de cada ticket nuevo y vista de qué instalaciones envían a Remedy.
 */
final class CorreoController extends Controller
{
    public function index(): void
    {
        $this->requireRole(['admin_ti']);
        $pdo = Database::getInstance();
        $ajustes = new CorreoAjustes($pdo);
        $this->view('correo/index', [
            'title'         => 'Correo y Remedy',
            'remedy'        => $ajustes->remedy(),
            'copias'        => $ajustes->copias(),
            'driver'        => (string) config('mail.driver', 'off'),
            'instalaciones' => $pdo->query(
                'SELECT i.id, i.nombre, i.remedy_activo, z.nombre AS zona FROM instalaciones i LEFT JOIN zonas z ON z.id = i.zona_id WHERE i.activo = 1 ORDER BY z.nombre, i.nombre'
            )->fetchAll(),
        ]);
    }

    public function save(): void
    {
        $this->requireRole(['admin_ti']);
        $this->verifyCsrf();
        try {
            (new CorreoAjustes(Database::getInstance()))->guardar($this->input('remedy'), (string) ($_POST['copias'] ?? ''), (int) Auth::id());
            Session::flash('success', 'Correo de Remedy y copias guardados.');
        } catch (ValidationException $e) {
            foreach ($e->errors() as $error) {
                Session::flash('danger', $error);
            }
        }
        redirect('/ti/correo');
    }
}
