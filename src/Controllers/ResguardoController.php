<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Config\Database;
use App\Core\Auth;
use App\Core\HttpException;
use App\Core\Roles;
use App\Core\Session;
use App\Core\ValidationException;
use App\Services\ArchivoService;
use App\Services\ResguardoService;

/**
 * Módulo `resguardo`: recibir los equipos que deja un trabajador (con lo que
 * trae), su estado en TI y la lista de equipos disponibles para asignar.
 */
final class ResguardoController extends Controller
{
    /** GET /resguardo: equipos en resguardo con filtros. */
    public function index(): void
    {
        $this->requireModulo('resguardo', Roles::TI);
        $this->lista('resguardo/index', 'Resguardo de equipos', (string) ($_GET['estado'] ?? 'abiertos'));
    }

    /** GET /resguardo/disponibles: solo equipos listos para asignar. */
    public function disponibles(): void
    {
        $this->requireModulo('resguardo', Roles::TI);
        $this->lista('resguardo/disponibles', 'Equipos disponibles', 'disponible');
    }

    /** GET /resguardo/recibir?q=ficha o serie */
    public function recibir(): void
    {
        $this->modulo();
        $svc = $this->svc();
        $q = mb_substr(trim((string) ($_GET['q'] ?? '')), 0, 40);
        $this->view('resguardo/recibir', [
            'title'         => 'Recibir equipo',
            'q'             => $q,
            'candidatos'    => $svc->candidatos($q),
            'instalaciones' => Database::getInstance()->query('SELECT id, nombre FROM instalaciones WHERE activo = 1 ORDER BY nombre')->fetchAll(),
        ]);
    }

    /** POST /resguardo/recibir */
    public function store(): void
    {
        $this->modulo();
        if (empty($_POST) && (int) ($_SERVER['CONTENT_LENGTH'] ?? 0) > 0) {
            Session::flash('danger', 'El archivo excede el tamaño máximo permitido por el servidor.');
            redirect('/resguardo/recibir');
        }
        $this->verifyCsrf();
        $archivos = self::archivos();
        $acta = null;
        try {
            $subidas = self::subidas('acta');
            if ($subidas !== []) {
                $acta = $archivos->guardar($subidas[0], 'actas');
                if (!in_array(strtolower(pathinfo($acta['ruta'], PATHINFO_EXTENSION)), ['pdf', 'jpg', 'jpeg', 'png', 'webp'], true)) {
                    throw new ValidationException(['El acta debe ser PDF o imagen (JPG, PNG).']);
                }
            }
            $id = $this->svc()->recibir(Auth::user(), [
                'usuario_id'     => $this->input('usuario_id'),
                'motivo'         => $this->input('motivo'),
                'instalacion_id' => $this->input('instalacion_id'),
                'observaciones'  => is_string($_POST['observaciones'] ?? null) ? (string) $_POST['observaciones'] : '',
                'equipos'        => is_array($_POST['equipos'] ?? null) ? $_POST['equipos'] : [],
                'condicion'      => is_array($_POST['condicion'] ?? null) ? $_POST['condicion'] : [],
                'accesorios'     => is_array($_POST['accesorios'] ?? null) ? $_POST['accesorios'] : [],
                'obs'            => is_array($_POST['obs'] ?? null) ? $_POST['obs'] : [],
            ], $acta);
        } catch (ValidationException $e) {
            if ($acta !== null) {
                $archivos->descartar([$acta]);
            }
            foreach ($e->errors() as $error) {
                Session::flash('danger', $error);
            }
            redirect('/resguardo/recibir?q=' . rawurlencode($this->input('q')));
        }
        Session::flash('success', 'Recepción registrada. Los equipos quedan "En resguardo TI"; márcalos como disponibles cuando estén listos.');
        redirect('/resguardo/' . $id);
    }

    /** GET /resguardo/{id}: una recepción con lo que se dejó. */
    public function show(int $id): void
    {
        $this->modulo();
        $rc = $this->svc()->recepcion($id);
        if ($rc === null || !$this->svc()->enAlcance(Auth::user(), $rc['instalacion_id'] !== null ? (int) $rc['instalacion_id'] : null)) {
            throw new HttpException(404, 'La recepción no existe.');
        }
        $this->view('resguardo/show', ['title' => $rc['folio'], 'rc' => $rc]);
    }

    /** GET /resguardo/{id}/acta: descarga el acta escaneada. */
    public function acta(int $id): void
    {
        $this->modulo();
        $rc = $this->svc()->recepcion($id);
        if ($rc === null || $rc['acta_ruta'] === null || !$this->svc()->enAlcance(Auth::user(), $rc['instalacion_id'] !== null ? (int) $rc['instalacion_id'] : null)) {
            throw new HttpException(404, 'El archivo no existe.');
        }
        self::archivos()->enviar(['ruta' => $rc['acta_ruta'], 'nombre_original' => $rc['acta_nombre'], 'mime' => $rc['acta_mime']]);
    }

    /** POST /resguardo/equipo/{id}/estado */
    public function estado(int $id): void
    {
        $this->modulo();
        $this->verifyCsrf();
        $item = $this->itemEnAlcance($id);
        try {
            $this->svc()->cambiarEstado($id, $this->input('estado'), Auth::user());
            Session::flash('success', 'Estado del equipo actualizado.');
        } catch (ValidationException $e) {
            foreach ($e->errors() as $error) {
                Session::flash('danger', $error);
            }
        }
        $this->volver((int) $item['recepcion_id']);
    }

    /** POST /resguardo/equipo/{id}/asignar */
    public function asignar(int $id): void
    {
        $this->modulo();
        $this->verifyCsrf();
        $item = $this->itemEnAlcance($id);
        try {
            $this->svc()->asignar($id, $this->input('ficha'), $this->input('nota'), Auth::user());
            Session::flash('success', 'Equipo asignado. Queda "pendiente en GECA" hasta que la carga semanal lo traiga a nombre del trabajador.');
        } catch (ValidationException $e) {
            foreach ($e->errors() as $error) {
                Session::flash('danger', $error);
            }
        }
        $this->volver((int) $item['recepcion_id']);
    }

    // ------------------------------------------------------------------

    private function lista(string $vista, string $titulo, string $estado): void
    {
        $this->modulo();
        $svc = $this->svc();
        $filtros = [
            'estado'      => $estado,
            'instalacion' => (int) ($_GET['inst'] ?? 0),
            'tipo'        => mb_substr(trim((string) ($_GET['tipo'] ?? '')), 0, 30),
            'q'           => mb_substr(trim((string) ($_GET['q'] ?? '')), 0, 60),
        ];
        $this->view($vista, [
            'title'         => $titulo,
            'filtros'       => $filtros,
            'items'         => $svc->lista(Auth::user(), $filtros),
            'tipos'         => $svc->tipos(),
            'instalaciones' => Database::getInstance()->query('SELECT id, nombre FROM instalaciones WHERE activo = 1 ORDER BY nombre')->fetchAll(),
        ]);
    }

    /** @return array<string, mixed> */
    private function itemEnAlcance(int $id): array
    {
        $item = $this->svc()->item($id);
        if ($item === null || !$this->svc()->enAlcance(Auth::user(), $item['instalacion_id'] !== null ? (int) $item['instalacion_id'] : null)) {
            throw new HttpException(404, 'El registro no existe.');
        }
        return $item;
    }

    /** Regresa a la lista de donde vino (disponibles, resguardo) o a la recepción. */
    private function volver(int $recepcionId): never
    {
        $v = $this->input('volver');
        redirect(match ($v) {
            'disponibles' => '/resguardo/disponibles',
            'lista'       => '/resguardo',
            default       => '/resguardo/' . $recepcionId,
        });
    }

    private function modulo(): void
    {
        $this->requireModulo('resguardo', Roles::TI);
        if (!$this->svc()->disponible()) {
            throw new HttpException(503, 'Falta importar la migración 2026-12-05_resguardo.sql. El Admin TI puede verla en Gestión › Diagnóstico.');
        }
    }

    private function svc(): ResguardoService
    {
        return new ResguardoService(Database::getInstance());
    }

    private static function archivos(): ArchivoService
    {
        return new ArchivoService(STORAGE_PATH . '/resguardo');
    }
}
