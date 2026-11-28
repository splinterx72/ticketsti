<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Config\Database;
use App\Core\Auth;
use App\Core\HttpException;
use App\Core\Session;
use App\Core\ValidationException;
use App\Services\AsignacionService;
use App\Services\UbicacionFisicaService;

/**
 * Administración (solo admin_ti): instalaciones, plantilla general (tipos y
 * niveles), equivalencias GECA → instalación y supervisores por instalación.
 */
final class InstalacionController extends Controller
{
    public function index(): void
    {
        $this->requireRole(['admin_ti']);
        $pdo = Database::getInstance();
        $svc = new UbicacionFisicaService($pdo);

        $supervisores = $pdo->query(
            "SELECT si.instalacion_id, GROUP_CONCAT(DISTINCT u.nombre ORDER BY u.nombre SEPARATOR ', ') AS nombres
               FROM supervisor_instalaciones si JOIN usuarios u ON u.id = si.supervisor_id
              WHERE (si.desde IS NULL OR si.desde <= CURDATE()) AND (si.hasta IS NULL OR si.hasta >= CURDATE())
              GROUP BY si.instalacion_id"
        )->fetchAll(\PDO::FETCH_KEY_PAIR);

        $this->view('instalaciones/index', [
            'title'         => 'Instalaciones',
            'instalaciones' => $svc->instalaciones(),
            'supervisores'  => $supervisores,
            'tipos'         => $svc->tipos(),
            'niveles'       => $svc->niveles(),
            'inmuebles'     => $svc->inmueblesGeca(),
        ]);
    }

    public function store(): void
    {
        $this->requireRole(['admin_ti']);
        $this->verifyCsrf();
        try {
            $id = (new UbicacionFisicaService(Database::getInstance()))->guardarInstalacion(null, $this->input('nombre'));
            Session::flash('success', 'Instalación creada. Asígnale un supervisor.');
            redirect('/ti/instalaciones/' . $id);
        } catch (ValidationException $e) {
            $this->flashErrors($e);
            redirect('/ti/instalaciones');
        }
    }

    public function show(int $id): void
    {
        $this->requireRole(['admin_ti']);
        $pdo = Database::getInstance();
        $inst = $this->instalacion($id);
        $asig = new AsignacionService($pdo);

        $candidatos = $pdo->query(
            "SELECT id, nombre, ficha FROM usuarios WHERE rol IN ('supervisor', 'coordinador', 'admin_ti') AND activo = 1 AND registrado = 1 ORDER BY nombre"
        )->fetchAll();

        $this->view('instalaciones/show', [
            'title'        => $inst['nombre'],
            'instalacion'  => $inst,
            'supervisores' => $asig->vigentes('supervisor_instalaciones', [$id]),
            'agentes'      => $asig->vigentes('agente_instalaciones', [$id]),
            'ubicaciones'  => (new UbicacionFisicaService($pdo))->ubicacionesDe($id),
            'uso'          => (new UbicacionFisicaService($pdo))->uso($id),
            'candidatos'   => $candidatos,
        ]);
    }

    public function update(int $id): void
    {
        $this->requireRole(['admin_ti']);
        $this->verifyCsrf();
        $this->instalacion($id);
        try {
            (new UbicacionFisicaService(Database::getInstance()))
                ->guardarInstalacion($id, $this->input('nombre'), $this->input('activo') === '1', $this->input('ciudad'));
            Session::flash('success', 'Instalación actualizada.');
        } catch (ValidationException $e) {
            $this->flashErrors($e);
        }
        redirect('/ti/instalaciones/' . $id);
    }

    /** POST /ti/instalaciones/{id}/eliminar: borra la instalación aunque esté en uso. */
    public function destroy(int $id): void
    {
        $this->requireRole(['admin_ti']);
        $this->verifyCsrf();
        $inst = $this->instalacion($id);
        (new UbicacionFisicaService(Database::getInstance()))->eliminarInstalacion($id);
        Session::flash('success', 'Instalación "' . $inst['nombre'] . '" eliminada.');
        redirect('/ti/instalaciones');
    }

    public function addSupervisor(int $id): void
    {
        $this->requireRole(['admin_ti']);
        $this->verifyCsrf();
        $this->instalacion($id);
        try {
            (new AsignacionService(Database::getInstance()))->asignarSupervisor(
                Auth::user(),
                (int) $this->input('supervisor_id'),
                $id,
                $this->input('tipo', 'titular'),
                $this->input('desde') ?: null,
                $this->input('hasta') ?: null
            );
            Session::flash('success', 'Supervisor asignado.');
        } catch (ValidationException $e) {
            $this->flashErrors($e);
        }
        redirect('/ti/instalaciones/' . $id);
    }

    public function removeSupervisor(int $id): void
    {
        $this->requireRole(['admin_ti']);
        $this->verifyCsrf();
        $stmt = Database::getInstance()->prepare('SELECT instalacion_id FROM supervisor_instalaciones WHERE id = ?');
        $stmt->execute([$id]);
        $instalacionId = (int) $stmt->fetchColumn();
        try {
            (new AsignacionService(Database::getInstance()))->quitar(Auth::user(), 'supervisor_instalaciones', $id);
            Session::flash('success', 'Asignación terminada.');
        } catch (ValidationException $e) {
            $this->flashErrors($e);
        }
        redirect($instalacionId > 0 ? '/ti/instalaciones/' . $instalacionId : '/ti/instalaciones');
    }

    public function plantilla(): void
    {
        $this->requireRole(['admin_ti']);
        $this->verifyCsrf();
        $svc = new UbicacionFisicaService(Database::getInstance());
        try {
            if ($this->input('tipo') !== '') {
                $svc->guardarTipo($this->input('tipo'));
            }
            if ($this->input('nivel') !== '') {
                $svc->guardarNivel($this->input('nivel'));
            }
            Session::flash('success', 'Plantilla actualizada.');
        } catch (ValidationException $e) {
            $this->flashErrors($e);
        }
        redirect('/ti/instalaciones#plantilla');
    }

    /** Guarda en bloque las equivalencias inmueble GECA → instalación. */
    public function geca(): void
    {
        $this->requireRole(['admin_ti']);
        $this->verifyCsrf();
        $svc = new UbicacionFisicaService(Database::getInstance());
        // instalacion[<id de ubicaciones>] = instalación; solo llegan las filas cambiadas.
        $n = 0;
        foreach ((array) ($_POST['instalacion'] ?? []) as $ubicacionId => $inst) {
            $inmueble = $svc->inmueblePorId((int) $ubicacionId);
            if ($inmueble === null || !is_scalar($inst)) {
                continue;
            }
            $svc->mapearInmueble($inmueble, (int) $inst > 0 ? (int) $inst : null);
            $n++;
        }
        Session::flash('success', $n === 0 ? 'No había cambios que guardar.' : "Equivalencias guardadas ({$n} inmuebles).");
        redirect('/ti/instalaciones#geca');
    }

    /** @return array<string, mixed> */
    private function instalacion(int $id): array
    {
        $stmt = Database::getInstance()->prepare('SELECT * FROM instalaciones WHERE id = ?');
        $stmt->execute([$id]);
        $i = $stmt->fetch();
        if (!$i) {
            throw new HttpException(404, 'La instalación no existe.');
        }
        return $i;
    }

    private function flashErrors(ValidationException $e): void
    {
        foreach ($e->errors() as $error) {
            Session::flash('danger', $error);
        }
    }
}
