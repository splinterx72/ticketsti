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
use App\Services\InventarioService;
use App\Services\AsignacionService;
use App\Services\AsignadorService;
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

        $inmuebles = $seleccion > 0 ? $svc->inmueblesGeca() : [];
        $this->view('supervisor/ubicaciones', [
            'title'         => 'Ubicaciones',
            'instalaciones' => $mias,
            'seleccion'     => $seleccion,
            'inmueblesMios' => array_values(array_filter($inmuebles, static fn (array $i): bool => $i['instalacion_id'] === $seleccion)),
            'inmueblesLibres' => array_values(array_filter($inmuebles, static fn (array $i): bool => $i['instalacion_id'] === null)),
            'ubicaciones'   => $seleccion > 0 ? $svc->ubicacionesDe($seleccion) : [],
            'tipos'         => $svc->tipos(),
            'niveles'       => $svc->niveles(),
            'alas'          => array_diff_key(UbicacionFisicaService::ALAS, ['na' => true]),
            'edicion'       => null,
        ]);
    }

    /** Relaciona (o quita) un inmueble del GECA con una de sus instalaciones. */
    public function storeInmueble(): void
    {
        $this->requireRole(Roles::SUPERVISION);
        $this->verifyCsrf();
        $inst = (int) $this->input('instalacion');
        $quitar = $this->input('quitar') === '1';
        try {
            (new UbicacionFisicaService(Database::getInstance()))->mapearInmueblePor(Auth::user(), $this->input('inmueble'), $quitar ? null : $inst);
            Session::flash('success', $quitar ? 'Inmueble quitado de la instalación.' : 'Inmueble relacionado: sus equipos ya cuentan en esta instalación.');
        } catch (ValidationException $e) {
            $this->flashErrors($e);
        }
        redirect('/supervisor/ubicaciones?instalacion=' . $inst . '#inmuebles');
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
        $asignador = new AsignadorService($pdo);

        $candidatos = $pdo->query(
            "SELECT id, nombre, ficha, rol FROM usuarios
              WHERE rol IN (" . Roles::sqlIn(Roles::TI) . ") AND activo = 1 AND registrado = 1 ORDER BY nombre"
        )->fetchAll();

        $this->view('supervisor/agentes', [
            'title'         => 'Agentes',
            'instalaciones' => $mias,
            'todas'         => (new UbicacionFisicaService($pdo))->instalaciones(true),
            'asignaciones'  => $asig->vigentes('agente_instalaciones', $ids),
            'salientes'     => $asig->prestamosSalientes($ids),
            'candidatos'    => $candidatos,
            'misAgentes'    => $misAgentes = (new Alcance($pdo))->agentesDe((int) Auth::id()),
            'disponibilidad' => array_combine(
                array_column($misAgentes, 'id'),
                array_map(static fn (array $a): array => $asignador->estadoDe($a['id']) + ['especialidades' => $asignador->especialidadesDe($a['id'])], $misAgentes)
            ),
            'departamentos' => \App\Services\Catalog::departamentos(),
            'modos'         => $this->modosAsignacion($ids),
        ]);
    }

    /** POST /supervisor/agentes/{id}/disponibilidad: estado, fecha y especialidades de un agente propio. */
    public function saveDisponibilidad(int $id): void
    {
        $this->requireRole(Roles::SUPERVISION);
        $this->verifyCsrf();
        $asignador = new AsignadorService(Database::getInstance());
        if (!$asignador->puedeGestionar(Auth::user(), $id)) {
            throw new HttpException(404, 'El agente no existe.');
        }
        try {
            $asignador->guardarEstado($id, $this->input('estado'), $this->input('hasta'), (int) Auth::id());
            $deps = $_POST['especialidades'] ?? [];
            $asignador->guardarEspecialidades($id, is_array($deps) ? array_values(array_filter($deps, 'is_string')) : []);
            Session::flash('success', 'Disponibilidad y especialidades guardadas.');
        } catch (ValidationException $e) {
            $this->flashErrors($e);
        }
        redirect('/supervisor/agentes#disponibilidad');
    }

    /** POST /supervisor/instalaciones/{id}/asignacion: modo manual | sugerida | automatica. */
    public function saveModoAsignacion(int $id): void
    {
        $this->requireRole(Roles::SUPERVISION);
        $this->verifyCsrf();
        if (!in_array($id, (new Alcance(Database::getInstance()))->instalacionesSupervisadas((int) Auth::id()), true)) {
            throw new HttpException(404, 'La instalación no existe.');
        }
        try {
            (new AsignadorService(Database::getInstance()))->guardarModo($id, $this->input('modo'));
            Session::flash('success', 'Modo de asignación actualizado.');
        } catch (ValidationException $e) {
            $this->flashErrors($e);
        }
        redirect('/supervisor/agentes#disponibilidad');
    }

    /** POST /supervisor/instalaciones/{id}/remedy: la instalación envía (o no) sus tickets nuevos a Remedy. */
    public function saveRemedy(int $id): void
    {
        $this->requireRole(Roles::SUPERVISION);
        $this->verifyCsrf();
        if (!in_array($id, (new Alcance(Database::getInstance()))->instalacionesSupervisadas((int) Auth::id()), true)) {
            throw new HttpException(404, 'La instalación no existe.');
        }
        $activo = $this->input('remedy_activo') === '1';
        (new \App\Services\Mail\CorreoAjustes(Database::getInstance()))->cambiarRemedyInstalacion($id, $activo);
        Session::flash('success', $activo ? 'Los tickets nuevos de la instalación se enviarán a Remedy.' : 'Los tickets nuevos de la instalación ya no se enviarán a Remedy.');
        redirect($this->input('volver') === 'correo' && Auth::hasRole(['admin_ti']) ? '/ti/correo' : '/supervisor/agentes#disponibilidad');
    }

    /**
     * @param list<int> $ids
     * @return array<int, array{modo: string, remedy: bool}>
     */
    private function modosAsignacion(array $ids): array
    {
        if ($ids === []) {
            return [];
        }
        $stmt = Database::getInstance()->prepare('SELECT id, modo_asignacion, remedy_activo FROM instalaciones WHERE id IN (' . implode(',', array_fill(0, count($ids), '?')) . ')');
        $stmt->execute($ids);
        $out = [];
        foreach ($stmt->fetchAll() as $r) {
            $out[(int) $r['id']] = ['modo' => (string) $r['modo_asignacion'], 'remedy' => (int) $r['remedy_activo'] === 1];
        }
        return $out;
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

    /** Coordinador: supervisores de las instalaciones de su zona. */
    public function supervisores(): void
    {
        $this->requireRole(Roles::COORDINACION);
        $pdo = Database::getInstance();
        $mias = $this->misInstalaciones();
        $this->view('supervisor/supervisores', [
            'title'         => 'Supervisores de mi zona',
            'instalaciones' => $mias,
            'asignaciones'  => (new AsignacionService($pdo))->vigentes('supervisor_instalaciones', array_column($mias, 'id')),
            'candidatos'    => $pdo->query(
                "SELECT id, nombre, ficha FROM usuarios WHERE rol IN ('supervisor', 'coordinador') AND activo = 1 AND registrado = 1 ORDER BY nombre"
            )->fetchAll(),
        ]);
    }

    public function storeSupervisor(): void
    {
        $this->requireRole(Roles::COORDINACION);
        $this->verifyCsrf();
        try {
            (new AsignacionService(Database::getInstance()))->asignarSupervisor(
                Auth::user(), (int) $this->input('supervisor_id'), (int) $this->input('instalacion_id'),
                $this->input('tipo'), $this->input('desde') ?: null, $this->input('hasta') ?: null
            );
            Session::flash('success', 'Supervisor asignado.');
        } catch (ValidationException $e) {
            $this->flashErrors($e);
        }
        redirect('/supervisor/supervisores');
    }

    public function removeSupervisor(int $id): void
    {
        $this->requireRole(Roles::COORDINACION);
        $this->verifyCsrf();
        try {
            (new AsignacionService(Database::getInstance()))->quitar(Auth::user(), 'supervisor_instalaciones', $id);
            Session::flash('success', 'Asignación terminada.');
        } catch (ValidationException $e) {
            $this->flashErrors($e);
        }
        redirect('/supervisor/supervisores');
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

        // Equipos y técnicos por instalación.
        $ids = array_map('intval', array_column($mias, 'id'));
        $equipos = (new InventarioService($pdo))->resumenPorInstalacion($ids);
        $tecnicos = [];
        if ($ids !== []) {
            $hoy = date('Y-m-d');
            $stmt = $pdo->prepare(
                "SELECT x.instalacion_id, u.id, u.nombre, x.tipo, x.hasta,
                        (SELECT COUNT(*) FROM tickets t JOIN estados es ON es.id = t.estado_id
                          WHERE t.agente_asignado_id = u.id AND es.es_cierre = 0) AS abiertos
                   FROM agente_instalaciones x JOIN usuarios u ON u.id = x.agente_id
                  WHERE x.instalacion_id IN (" . implode(',', $ids) . ") AND u.activo = 1
                    AND (x.desde IS NULL OR x.desde <= ?) AND (x.hasta IS NULL OR x.hasta >= ?)
                  ORDER BY u.nombre"
            );
            $stmt->execute([$hoy, $hoy]);
            foreach ($stmt->fetchAll() as $r) {
                $tecnicos[(int) $r['instalacion_id']][] = $r;
            }
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
            'panel'          => (new \App\Services\TableroService($pdo))->panel($ids),
            'esCoordinador'  => ($user['rol'] ?? '') === 'coordinador',
            'equipos'        => $equipos,
            'tecnicos'       => $tecnicos,
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
