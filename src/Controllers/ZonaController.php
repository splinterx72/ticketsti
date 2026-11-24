<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Config\Database;
use App\Core\Auth;
use App\Core\Session;
use App\Core\ValidationException;
use App\Services\AsignacionService;
use App\Services\ZonaService;

/**
 * Gestión › Zonas (solo Admin TI): zonas, sus instalaciones y coordinadores.
 */
final class ZonaController extends Controller
{
    public function index(): void
    {
        $this->requireRole(['admin_ti']);
        $pdo = Database::getInstance();
        $this->view('zonas/index', [
            'title'        => 'Zonas',
            'zonas'        => (new ZonaService($pdo))->zonas(),
            'instalaciones' => $pdo->query('SELECT id, nombre, zona_id FROM instalaciones WHERE activo = 1 ORDER BY nombre')->fetchAll(),
            'candidatos'   => $pdo->query(
                "SELECT id, nombre, ficha FROM usuarios WHERE rol IN ('coordinador', 'admin_ti') AND activo = 1 AND registrado = 1 ORDER BY nombre"
            )->fetchAll(),
        ]);
    }

    public function store(): void
    {
        $this->requireRole(['admin_ti']);
        $this->verifyCsrf();
        $this->intentar(fn () => (new ZonaService(Database::getInstance()))->guardarZona(null, $this->input('nombre')), 'Zona creada.');
    }

    public function update(int $id): void
    {
        $this->requireRole(['admin_ti']);
        $this->verifyCsrf();
        $this->intentar(fn () => (new ZonaService(Database::getInstance()))->guardarZona($id, $this->input('nombre'), $this->input('activo') !== '0'), 'Zona actualizada.');
    }

    /** Asigna (o quita) la zona de varias instalaciones a la vez. */
    public function instalaciones(): void
    {
        $this->requireRole(['admin_ti']);
        $this->verifyCsrf();
        $svc = new ZonaService(Database::getInstance());
        foreach ((array) ($_POST['zona'] ?? []) as $instalacionId => $zonaId) {
            if (is_numeric($instalacionId) && is_scalar($zonaId)) {
                $svc->asignarZona((int) $instalacionId, (int) $zonaId > 0 ? (int) $zonaId : null);
            }
        }
        Session::flash('success', 'Zonas de las instalaciones guardadas.');
        redirect('/ti/zonas');
    }

    public function addCoordinador(int $id): void
    {
        $this->requireRole(['admin_ti']);
        $this->verifyCsrf();
        $this->intentar(fn () => (new AsignacionService(Database::getInstance()))->asignarCoordinador(
            Auth::user(), (int) $this->input('coordinador_id'), $id, $this->input('tipo'),
            $this->input('desde') ?: null, $this->input('hasta') ?: null
        ), 'Coordinador asignado.');
    }

    public function removeCoordinador(int $id): void
    {
        $this->requireRole(['admin_ti']);
        $this->verifyCsrf();
        $this->intentar(fn () => (new AsignacionService(Database::getInstance()))->quitar(Auth::user(), 'coordinador_zonas', $id), 'Asignación terminada.');
    }

    private function intentar(callable $fn, string $ok): never
    {
        try {
            $fn();
            Session::flash('success', $ok);
        } catch (ValidationException $e) {
            foreach ($e->errors() as $error) {
                Session::flash('danger', $error);
            }
        }
        redirect('/ti/zonas');
    }
}
