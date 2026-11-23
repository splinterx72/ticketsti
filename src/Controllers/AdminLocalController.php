<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Config\Database;
use App\Core\Auth;
use App\Core\Roles;
use App\Core\Session;
use App\Core\ValidationException;
use App\Services\AdminLocalService;

/**
 * Registro del cambio de la contraseña de admin local (spec 7.5).
 * Es la única pantalla disponible para un usuario de TI bloqueado.
 */
final class AdminLocalController extends Controller
{
    public function show(): void
    {
        $this->exigirTi();
        $user = Auth::user();
        $dias = AdminLocalService::diasRestantes($user['admin_local_cambiado_en']);

        $this->view('admin_local/registrar', [
            'title'     => 'Contraseña de admin local',
            'dias'      => $dias,
            'vence'     => AdminLocalService::vence($user['admin_local_cambiado_en']),
            'semaforo'  => AdminLocalService::semaforo($dias),
            'bloqueado' => AdminLocalService::bloqueado($user),
            'cambiado'  => $user['admin_local_cambiado_en'],
        ], 200, AdminLocalService::bloqueado($user) ? 'layouts/guest' : 'layouts/main');
    }

    public function store(): void
    {
        $this->exigirTi();
        $this->verifyCsrf();
        if (empty($_POST['confirmo'])) {
            Session::flash('danger', 'Confirma que ya cambiaste tu contraseña de admin local.');
            redirect('/admin-local');
        }
        try {
            (new AdminLocalService(Database::getInstance()))->registrar((int) Auth::id(), $this->input('fecha'));
        } catch (ValidationException $e) {
            foreach ($e->errors() as $error) {
                Session::flash('danger', $error);
            }
            redirect('/admin-local');
        }
        Auth::refrescar();
        $vence = AdminLocalService::vence($this->input('fecha'));
        Session::flash('success', 'Cambio registrado. La próxima vez vence el ' . date('d/m/Y', (int) strtotime((string) $vence)) . '.');
        redirect('/');
    }

    /** Sesión de TI con correo verificado (sin aplicar el propio bloqueo). */
    private function exigirTi(): void
    {
        if (!Auth::check()) {
            redirect('/login');
        }
        if (Auth::requiereVerificacion()) {
            redirect('/verificar-correo');
        }
        if (!Roles::esTi(Auth::user()['rol'] ?? null)) {
            redirect('/');
        }
    }
}
