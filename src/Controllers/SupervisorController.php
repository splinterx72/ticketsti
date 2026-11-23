<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Config\Database;
use App\Core\Auth;
use App\Core\HttpException;
use App\Core\Roles;
use App\Core\Session;
use App\Core\ValidationException;
use App\Services\AdminLocalService;
use App\Services\Alcance;
use App\Services\AsignacionService;
use App\Services\TareaService;
use App\Services\UbicacionFisicaService;

/**
 * Funciones del supervisor sobre SUS instalaciones: ubicaciones físicas
 * (con niveles, alas e indicaciones) y agentes (asignación y préstamos).
 * El admin TI puede operar sobre cualquier instalación.
 */
final class SupervisorController extends Controller
{
    public function panel(): void
    {
        $this->requireRole(Roles::SUPERVISION);
        $this->view('supervisor/panel', ['title' => 'Supervisión'] + $this->panelData());
    }

    public function ubicaciones(): void
    {
        $this->requireRole(Roles::SUPERVISION);
        $pdo = Database::getInstance();
        $mias = $this->misInstalaciones();
        $svc = new UbicacionFisicaService($pdo);

        $seleccion = (int) ($_GET['instalacion'] ?? ($mias[0]['id'] ?? 0));
        if ($seleccion > 0 && !in_array($seleccion, array_column($mias, 'id'), true)) {
            throw new HttpException(403, 'Esa instalación no está a tu cargo.');
        }

        $this->view('supervisor/ubicaciones', [
            'title'         => 'Ubicaciones',
            'instalaciones' => $mias,
            'seleccion'     => $seleccion,
            'ubicaciones'   => $seleccion > 0 ? $svc->ubicacionesDe($seleccion) : [],
            'tipos'         => $svc->tipos(),
            'niveles'       => $svc->niveles(),
            'alas'          => array_diff_key(UbicacionFisicaService::ALAS, ['na' => true]),
            'edicion'       => null,
        ]);
    }

    public function editUbicacion(int $id): void
    {
        $this->requireRole(Roles::SUPERVISION);
        $svc = new UbicacionFisicaService(Database::getInstance());
        $u = $svc->ubicacion($id);
        if ($u === null) {
            throw new HttpException(404, 'La ubicación no existe.');
        }
        $this->exigirMia((int) $u['instalacion_id']);
        $mias = $this->misInstalaciones();

        $this->view('supervisor/ubicaciones', [
            'title'         => 'Editar ' . $u['nombre'],
            'instalaciones' => $mias,
            'seleccion'     => (int) $u['instalacion_id'],
            'ubicaciones'   => $svc->ubicacionesDe((int) $u['instalacion_id']),
            'tipos'         => $svc->tipos(),
            'niveles'       => $svc->niveles(),
            'alas'          => array_diff_key(UbicacionFisicaService::ALAS, ['na' => true]),
            'edicion'       => $u,
        ]);
    }

    public function storeUbicacion(): void
    {
        $this->requireRole(Roles::SUPERVISION);
        $this->verifyCsrf();
        $instalacionId = (int) $this->input('instalacion_id');
        $this->exigirMia($instalacionId);
        $this->guardarUbicacion($instalacionId, null);
    }

    public function updateUbicacion(int $id): void
    {
        $this->requireRole(Roles::SUPERVISION);
        $this->verifyCsrf();
        $u = (new UbicacionFisicaService(Database::getInstance()))->ubicacion($id);
        if ($u === null) {
            throw new HttpException(404, 'La ubicación no existe.');
        }
        $this->exigirMia((int) $u['instalacion_id']);
        $this->guardarUbicacion((int) $u['instalacion_id'], $id);
    }

    public function agentes(): void
    {
        $this->requireRole(Roles::SUPERVISION);
        $pdo = Database::getInstance();
        $mias = $this->misInstalaciones();
        $ids = array_column($mias, 'id');
        $asig = new AsignacionService($pdo);

        $candidatos = $pdo->query(
            "SELECT id, nombre, ficha, rol FROM usuarios
              WHERE rol IN ('agente_ti', 'supervisor', 'admin_ti') AND activo = 1 AND registrado = 1 ORDER BY nombre"
        )->fetchAll();

        $this->view('supervisor/agentes', [
            'title'         => 'Agentes',
            'instalaciones' => $mias,
            'todas'         => (new UbicacionFisicaService($pdo))->instalaciones(true),
            'asignaciones'  => $asig->vigentes('agente_instalaciones', $ids),
            'salientes'     => $asig->prestamosSalientes($ids),
            'candidatos'    => $candidatos,
            'misAgentes'    => (new Alcance($pdo))->agentesDe((int) Auth::id()),
        ]);
    }

    public function storeAgente(): void
    {
        $this->requireRole(Roles::SUPERVISION);
        $this->verifyCsrf();
        try {
            (new AsignacionService(Database::getInstance()))->asignarAgente(
                Auth::user(),
                (int) $this->input('agente_id'),
                (int) $this->input('instalacion_id')
            );
            Session::flash('success', 'Agente asignado a la instalación.');
        } catch (ValidationException $e) {
            $this->flashErrors($e);
        }
        redirect('/supervisor/agentes');
    }

    public function storePrestamo(): void
    {
        $this->requireRole(Roles::SUPERVISION);
        $this->verifyCsrf();
        try {
            (new AsignacionService(Database::getInstance()))->prestarAgente(
                Auth::user(),
                (int) $this->input('agente_id'),
                (int) $this->input('instalacion_id'),
                $this->input('desde') ?: null,
                $this->input('hasta') ?: null
            );
            Session::flash('success', 'Préstamo registrado. El agente regresará solo al terminar la fecha.');
        } catch (ValidationException $e) {
            $this->flashErrors($e);
        }
        redirect('/supervisor/agentes');
    }

    public function removeAsignacion(int $id): void
    {
        $this->requireRole(Roles::SUPERVISION);
        $this->verifyCsrf();
        try {
            (new AsignacionService(Database::getInstance()))->quitar(Auth::user(), 'agente_instalaciones', $id);
            Session::flash('success', 'Asignación terminada.');
        } catch (ValidationException $e) {
            $this->flashErrors($e);
        }
        redirect('/supervisor/agentes');
    }

    // ------------------------------------------------------------------

    /** @return array<string, mixed> datos del panel: semáforo, tareas por revisar y tickets */
    private function panelData(): array
    {
        $pdo = Database::getInstance();
        $user = Auth::user();
        $mias = $this->misInstalaciones();
        $alcance = new Alcance($pdo);

        // Semáforo de contraseña de admin local de sus agentes (spec 7.5).
        $agentes = $alcance->agentesDe((int) $user['id']);
        $semaforo = [];
        if ($agentes !== []) {
            $stmt = $pdo->query(
                'SELECT id, nombre, ficha, admin_local_cambiado_en FROM usuarios WHERE id IN ('
                . implode(',', array_map('intval', array_column($agentes, 'id'))) . ') ORDER BY nombre'
            );
            $prestados = array_column($agentes, 'prestado', 'id');
            foreach ($stmt->fetchAll() as $r) {
                $dias = AdminLocalService::diasRestantes($r['admin_local_cambiado_en']);
                $semaforo[] = $r + [
                    'dias' => $dias, 'vence' => AdminLocalService::vence($r['admin_local_cambiado_en']),
                    'color' => AdminLocalService::semaforo($dias), 'prestado' => (bool) ($prestados[(int) $r['id']] ?? false),
                ];
            }
            usort($semaforo, static fn (array $a, array $b): int => ($a['dias'] ?? -9999) <=> ($b['dias'] ?? -9999));
        }

        // Tickets abiertos por instalación.
        $porInstalacion = [];
        if ($mias !== []) {
            $ids = implode(',', array_map('intval', array_column($mias, 'id')));
            $porInstalacion = $pdo->query(
                "SELECT i.id, i.nombre,
                        COALESCE(SUM(es.es_cierre = 0), 0) AS abiertos,
                        COALESCE(SUM(es.es_cierre = 0 AND t.agente_asignado_id IS NULL), 0) AS sin_asignar,
                        COALESCE(SUM(es.es_cierre = 0 AND t.discrepancia_ubicacion = 1), 0) AS discrepancias
                   FROM instalaciones i
                   LEFT JOIN tickets t ON t.instalacion_id = i.id
                   LEFT JOIN estados es ON es.id = t.estado_id
                  WHERE i.id IN ({$ids})
                  GROUP BY i.id, i.nombre ORDER BY i.nombre"
            )->fetchAll();
        }

        $tareas = array_values(array_filter(
            (new TareaService($pdo))->asignadasPor($user),
            static fn (array $t): bool => (int) $t['aceptadas'] < (int) $t['total']
        ));

        return [
            'instalaciones'  => $mias,
            'agentes'        => $agentes,
            'semaforo'       => $semaforo,
            'porInstalacion' => $porInstalacion,
            'tareas'         => array_slice($tareas, 0, 8),
        ];
    }

    private function guardarUbicacion(int $instalacionId, ?int $id): void
    {
        $in = [
            'nombre'       => $this->input('nombre'),
            'tipo_id'      => $this->input('tipo_id'),
            'indicaciones' => is_string($_POST['indicaciones'] ?? null) ? (string) $_POST['indicaciones'] : '',
            'niveles'      => array_map('intval', (array) ($_POST['niveles'] ?? [])),
            'alas'         => array_map('strval', (array) ($_POST['alas'] ?? [])),
            'activo'       => isset($_POST['activo_presente']) ? $this->input('activo') === '1' : true,
        ];
        try {
            (new UbicacionFisicaService(Database::getInstance()))->guardarUbicacion($instalacionId, $id, $in);
            Session::flash('success', $id === null ? 'Ubicación creada.' : 'Ubicación actualizada.');
            redirect('/supervisor/ubicaciones?instalacion=' . $instalacionId);
        } catch (ValidationException $e) {
            $this->flashErrors($e);
            $this->rememberInput(['nombre', 'tipo_id', 'indicaciones']);
            redirect($id === null ? '/supervisor/ubicaciones?instalacion=' . $instalacionId : '/supervisor/ubicaciones/' . $id . '/editar');
        }
    }

    /** @return list<array{id: int, nombre: string}> */
    private function misInstalaciones(): array
    {
        $pdo = Database::getInstance();
        $ids = (new Alcance($pdo))->instalacionesSupervisadas((int) Auth::id());
        if ($ids === []) {
            return [];
        }
        $stmt = $pdo->prepare('SELECT id, nombre FROM instalaciones WHERE id IN (' . implode(',', array_fill(0, count($ids), '?')) . ') ORDER BY nombre');
        $stmt->execute($ids);
        return array_map(static fn (array $r): array => ['id' => (int) $r['id'], 'nombre' => (string) $r['nombre']], $stmt->fetchAll());
    }

    private function exigirMia(int $instalacionId): void
    {
        if (!in_array($instalacionId, array_column($this->misInstalaciones(), 'id'), true)) {
            throw new HttpException(403, 'Esa instalación no está a tu cargo.');
        }
    }

    private function flashErrors(ValidationException $e): void
    {
        foreach ($e->errors() as $error) {
            Session::flash('danger', $error);
        }
    }
}
