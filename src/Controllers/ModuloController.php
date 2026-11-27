<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Config\Database;
use App\Core\Session;
use App\Core\ValidationException;
use App\Services\ModuloService;

/**
 * Gestión › Módulos (solo Admin TI): encender o apagar módulos y elegir los roles que los usan.
 */
final class ModuloController extends Controller
{
    public function index(): void
    {
        $this->requireRole(['admin_ti']);
        $this->view('modulos/index', [
            'title'   => 'Módulos',
            'modulos' => (new ModuloService(Database::getInstance()))->todos(),
        ]);
    }

    public function save(): void
    {
        $this->requireRole(['admin_ti']);
        $this->verifyCsrf();
        $svc = new ModuloService(Database::getInstance());
        $activos = (array) ($_POST['activo'] ?? []);
        $roles = (array) ($_POST['roles'] ?? []);
        try {
            foreach ($svc->todos() as $m) {
                $r = $roles[$m['clave']] ?? [];
                $svc->guardar($m['clave'], !empty($activos[$m['clave']]), is_array($r) ? array_values(array_filter($r, 'is_string')) : []);
            }
            Session::flash('success', 'Módulos guardados.');
        } catch (ValidationException $e) {
            foreach ($e->errors() as $error) {
                Session::flash('danger', $error);
            }
        }
        redirect('/ti/modulos');
    }
}
