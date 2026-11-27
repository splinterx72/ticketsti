<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Config\Database;
use App\Core\Auth;
use App\Core\HttpException;
use App\Core\Roles;
use App\Core\Session;
use App\Core\ValidationException;
use App\Services\BitLockerService;

/**
 * BitLocker por equipo: el resguardante y el TI de la instalación capturan el
 * identificador (y opcionalmente la llave cifrada); ver la llave pide la contraseña.
 */
final class BitLockerController extends Controller
{
    /** GET /bitlocker: mis equipos y, para TI, el buscador por identificador, serie o ficha. */
    public function index(): void
    {
        $this->requireModulo('bitlocker');
        $user = Auth::user();
        $svc = new BitLockerService(Database::getInstance());
        $esTi = Roles::esTi($user['rol'] ?? null);
        $q = trim((string) ($_GET['q'] ?? ''));
        $this->view('bitlocker/index', [
            'title'      => 'BitLocker',
            'esTi'       => $esTi,
            'q'          => mb_substr($q, 0, 60),
            'resultados' => $esTi && $q !== '' ? $svc->buscar($user, $q) : null,
            'misEquipos' => $svc->misEquipos((int) $user['id']),
        ]);
    }

    public function show(int $id): void
    {
        $this->requireModulo('bitlocker');
        $this->pantalla($id, null, null);
    }

    public function store(int $id): void
    {
        $this->requireModulo('bitlocker');
        $this->verifyCsrf();
        try {
            self::servicio()->guardar($id, Auth::user(), [
                'unidad' => $this->input('unidad'), 'identificador' => $this->input('identificador'),
                'llave' => $this->input('llave'), 'nota' => $this->input('nota'),
            ]);
            Session::flash('success', 'Datos de BitLocker guardados.');
        } catch (ValidationException $e) {
            foreach ($e->errors() as $error) {
                Session::flash('danger', $error);
            }
            $this->rememberInput(['unidad', 'identificador', 'nota']);
        }
        redirect('/equipos/' . $id . '/bitlocker');
    }

    /** Muestra la llave una sola vez en esta respuesta (no se guarda en sesión). */
    public function reveal(int $id): void
    {
        $this->requireModulo('bitlocker');
        $this->verifyCsrf();
        $registro = $id;
        $stmt = Database::getInstance()->prepare('SELECT equipo_id FROM equipo_bitlocker WHERE id = ?');
        $stmt->execute([$registro]);
        $id = (int) $stmt->fetchColumn();
        if ($id === 0) {
            throw new HttpException(404, 'El registro no existe.');
        }
        try {
            $llave = self::servicio()->mostrar($registro, Auth::user(), (string) ($_POST['password'] ?? ''), $this->input('motivo'));
            header('Cache-Control: no-store');
            $this->pantalla($id, $registro, $llave);
        } catch (ValidationException $e) {
            foreach ($e->errors() as $error) {
                Session::flash('danger', $error);
            }
            redirect('/equipos/' . $id . '/bitlocker');
        }
    }

    private function pantalla(int $equipoId, ?int $revelado, ?string $llave): void
    {
        $svc = self::servicio();
        $registros = $svc->deEquipo($equipoId, Auth::user());
        if ($registros === null) {
            throw new HttpException(404, 'El equipo no existe.');
        }
        $stmt = Database::getInstance()->prepare(
            'SELECT e.id, e.serie, e.tipo_componente, e.marca, e.modelo, e.usuario_id, u.nombre AS resguardante, u.ficha
               FROM equipos e LEFT JOIN usuarios u ON u.id = e.usuario_id WHERE e.id = ?'
        );
        $stmt->execute([$equipoId]);
        $equipo = $stmt->fetch();
        $esTi = Roles::esTi(Auth::user()['rol'] ?? null);
        $this->view('bitlocker/show', [
            'title'     => 'BitLocker · ' . ($equipo['serie'] ?? ''),
            'equipo'    => $equipo,
            'registros' => $registros,
            'revelado'  => $revelado,
            'llave'     => $llave,
            'bitacora'  => $esTi ? $svc->bitacora($equipoId) : [],
            'esTi'      => $esTi,
        ]);
    }

    private static function servicio(): BitLockerService
    {
        return new BitLockerService(Database::getInstance());
    }
}
