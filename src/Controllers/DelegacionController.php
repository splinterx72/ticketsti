<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Config\Database;
use App\Core\Auth;
use App\Core\Roles;
use App\Core\Session;
use App\Core\ValidationException;
use App\Services\DelegacionService;

/**
 * Autorizaciones para levantar tickets a nombre de otra persona.
 *  - Mi cuenta: el jefe autoriza o quita (POST /cuenta/delegaciones).
 *  - Ficha del trabajador: supervisión de TI, si el jefe no ha activado su cuenta; Admin TI siempre.
 */
final class DelegacionController extends Controller
{
    public function storeMine(): void
    {
        $this->requireLogin();
        $this->verifyCsrf();
        $this->autorizar((int) Auth::id(), '/cuenta#delegaciones');
    }

    public function storeFor(int $id): void
    {
        $this->requireRole(Roles::SUPERVISION);
        $this->verifyCsrf();
        $this->autorizar($id, '/ti/usuarios/' . $id . '#delegaciones');
    }

    public function destroy(int $id): void
    {
        $this->requireLogin();
        $this->verifyCsrf();
        $volver = (string) ($_POST['volver'] ?? '');
        try {
            (new DelegacionService(Database::getInstance()))->revocar(Auth::user(), $id);
            Session::flash('success', 'Autorización retirada.');
        } catch (ValidationException $e) {
            $this->flashErrors($e);
        }
        redirect(preg_match('#^/ti/usuarios/\d+$#', $volver) === 1 ? $volver . '#delegaciones' : '/cuenta#delegaciones');
    }

    private function autorizar(int $jefeId, string $volver): void
    {
        try {
            (new DelegacionService(Database::getInstance()))->autorizar(
                Auth::user(),
                $jefeId,
                $this->input('ficha'),
                !empty($_POST['ver_todos']),
                $this->input('hasta')
            );
            Session::flash('success', 'Autorización guardada. Esa persona ya puede levantar tickets a nombre del jefe.');
        } catch (ValidationException $e) {
            $this->flashErrors($e);
            $this->rememberInput(['ficha', 'hasta']);
        }
        redirect($volver);
    }

    private function flashErrors(ValidationException $e): void
    {
        foreach ($e->errors() as $error) {
            Session::flash('danger', $error);
        }
    }
}
