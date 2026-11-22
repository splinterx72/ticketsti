<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Config\Database;
use App\Core\Auth;
use App\Core\HttpException;
use App\Core\Session;
use Throwable;

/**
 * Acciones del trabajador sobre sus equipos.
 * es_principal lo decide el usuario y la carga GECA lo preserva.
 */
final class EquipoController extends Controller
{
    public function setPrincipal(int $id): void
    {
        $this->requireLogin();
        $this->verifyCsrf();
        $userId = (int) Auth::id();
        $pdo = Database::getInstance();

        $stmt = $pdo->prepare('SELECT serie FROM equipos WHERE id = ? AND usuario_id = ?');
        $stmt->execute([$id, $userId]);
        $serie = $stmt->fetchColumn();
        if ($serie === false) {
            throw new HttpException(404, 'El equipo no existe o no está asignado a tu ficha.');
        }

        $pdo->beginTransaction();
        try {
            $pdo->prepare('UPDATE equipos SET es_principal = 0 WHERE usuario_id = ? AND id <> ?')->execute([$userId, $id]);
            $pdo->prepare('UPDATE equipos SET es_principal = 1 WHERE id = ?')->execute([$id]);
            $pdo->commit();
        } catch (Throwable $e) {
            $pdo->rollBack();
            throw $e;
        }

        Session::flash('success', "El equipo {$serie} es ahora tu equipo principal.");
        redirect('/');
    }
}
