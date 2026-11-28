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
use App\Services\FormatoService;
use App\Services\Mail\Notifier;
use App\Services\TicketService;

/**
 * Formatos con firmas (autorización de formateo):
 *  - ver y firmar en orden (usuario → responsable → TI), en su sesión o en
 *    persona en el dispositivo del técnico; o subir el papel firmado;
 *  - documento tamaño carta para imprimir o guardar como PDF.
 * Lo ven: quien ve el ticket, el usuario titular, su jefe inmediato y quienes firmaron.
 */
final class FormatoController extends Controller
{
    /** POST /tickets/{id}/formato: TI genera el formato de un ticket con equipo. */
    public function emitir(int $id): void
    {
        $this->requireModulo('formatos', Roles::TI);
        $this->verifyCsrf();
        $ticket = (new TicketService(Database::getInstance()))->find($id);
        if (!TicketController::acceso(Auth::user(), $ticket)[1]) {
            throw new HttpException(404, 'El ticket solicitado no existe.');
        }
        try {
            $fid = (new FormatoService(Database::getInstance()))->emitir($id, Auth::user());
            Notifier::formatoTurno($fid);
            Session::flash('success', 'Formato generado. Le avisamos al usuario para que lo firme.');
            redirect('/formatos/' . $fid);
        } catch (ValidationException $e) {
            $this->flashErrors($e);
            redirect('/tickets/' . $id);
        }
    }

    public function show(int $id): void
    {
        $this->requireModulo('formatos');
        [$svc, $f] = $this->cargar($id);
        $user = Auth::user();
        $turno = FormatoService::turno($f);
        $this->view('formatos/show', [
            'title'      => $f['folio'],
            'f'          => $f,
            'doc'        => $this->documento($svc, $f),
            'turno'      => $turno,
            'puede'      => $turno !== null && $svc->puedeFirmar($f, $turno, $user),
            'esTi'       => Roles::esTi($user['rol'] ?? null),
            'esTitular'  => (int) $user['id'] === (int) $f['usuario_id'],
            'esJefe'     => (string) $f['jefe_ficha'] !== '' && (string) ($user['ficha'] ?? '') === (string) $f['jefe_ficha'],
        ]);
    }

    /** GET /formatos/{id}/imprimir: hoja carta sin el resto de la página. */
    public function imprimir(int $id): void
    {
        $this->requireModulo('formatos');
        [$svc, $f] = $this->cargar($id);
        header('Cache-Control: private, no-store');
        $this->view('formatos/imprimir', ['title' => $f['folio'], 'f' => $f, 'doc' => $this->documento($svc, $f)], 200, null);
    }

    public function firmar(int $id): void
    {
        $this->requireModulo('formatos');
        $this->verifyCsrf();
        [$svc, $f] = $this->cargar($id);
        $rol = (string) ($_POST['rol'] ?? '');
        $motivos = $_POST['motivos'] ?? [];
        try {
            $svc->firmar($id, $rol, Auth::user(), [
                'png'           => self::firmaRecibida(),
                'password'      => (string) ($_POST['password'] ?? ''),
                'modo'          => $this->input('modo'),
                'motivos'       => is_array($motivos) ? $motivos : [],
                'ficha'         => $this->input('ficha'),
                'mismo_usuario' => !empty($_POST['mismo_usuario']),
                'motivo'        => $this->input('motivo'),
                'metodo'        => $this->input('metodo'),
            ]);
            Notifier::formatoTurno($id);
            $ahora = $svc->find($id);
            Session::flash('success', $ahora !== null && $ahora['estado'] === 'completo'
                ? 'Formato completo con las tres firmas. Ya puede realizarse el formateo.'
                : 'Firma registrada. Avisamos a quien sigue.');
        } catch (ValidationException $e) {
            $this->flashErrors($e);
        }
        redirect('/formatos/' . $id);
    }

    /**
     * Firma dibujada como data URL. Llega como archivo PNG (`firma_archivo`) o, de
     * respaldo, como base64 sin el prefijo "data:" (que bloquea ModSecurity).
     */
    private static function firmaRecibida(): string
    {
        $f = $_FILES['firma_archivo'] ?? null;
        if (is_array($f) && ($f['error'] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_OK
            && is_uploaded_file((string) $f['tmp_name']) && (int) $f['size'] <= 300 * 1024) {
            $bin = (string) file_get_contents((string) $f['tmp_name']);
            return 'data:image/png;base64,' . base64_encode($bin);
        }
        $b64 = trim((string) ($_POST['firma'] ?? ''));
        if ($b64 === '') {
            return '';
        }
        return str_starts_with($b64, 'data:') ? $b64 : 'data:image/png;base64,' . $b64;
    }

    /** POST /formatos/{id}/autografa: TI sube el formato firmado en papel. */
    public function autografa(int $id): void
    {
        $this->requireModulo('formatos', Roles::TI);
        $this->verifyCsrf();
        [$svc, $f] = $this->cargar($id);
        $archivos = new ArchivoService(STORAGE_PATH . '/formatos');
        $guardado = null;
        try {
            $subidas = self::subidas('escaneo');
            if ($subidas === []) {
                throw new ValidationException(['Sube el formato firmado (PDF o foto).']);
            }
            $guardado = $archivos->guardar($subidas[0], 'escaneos');
            if (!in_array(strtolower(pathinfo($guardado['ruta'], PATHINFO_EXTENSION)), ['pdf', 'jpg', 'jpeg', 'png', 'webp'], true)) {
                throw new ValidationException(['El escaneo debe ser PDF o imagen (JPG, PNG).']);
            }
            $motivos = $_POST['motivos'] ?? [];
            $svc->autografa($id, Auth::user(), [
                'archivo'       => $guardado['ruta'],
                'password'      => (string) ($_POST['password'] ?? ''),
                'ficha'         => $this->input('ficha'),
                'mismo_usuario' => !empty($_POST['mismo_usuario']),
                'motivo'        => $this->input('motivo'),
                'metodo'        => $this->input('metodo'),
                'motivos'       => is_array($motivos) ? $motivos : [],
            ]);
            Session::flash('success', 'Formato firmado en papel registrado; quedó completo.');
        } catch (ValidationException $e) {
            if ($guardado !== null) {
                $archivos->descartar([$guardado]);
            }
            $this->flashErrors($e);
        }
        redirect('/formatos/' . $id);
    }

    /** GET /formatos/{id}/escaneo: descarga el papel firmado. */
    public function escaneo(int $id): void
    {
        $this->requireModulo('formatos');
        [, $f] = $this->cargar($id);
        if (empty($f['archivo_autografo'])) {
            throw new HttpException(404, 'Este formato no tiene escaneo.');
        }
        $ext = strtolower(pathinfo((string) $f['archivo_autografo'], PATHINFO_EXTENSION));
        (new ArchivoService(STORAGE_PATH . '/formatos'))->enviar([
            'ruta' => (string) $f['archivo_autografo'], 'nombre_original' => $f['folio'] . '-firmado.' . $ext,
            'mime' => $ext === 'pdf' ? 'application/pdf' : ($ext === 'png' ? 'image/png' : ($ext === 'webp' ? 'image/webp' : 'image/jpeg')),
        ]);
    }

    public function cancelar(int $id): void
    {
        $this->requireModulo('formatos', Roles::TI);
        $this->verifyCsrf();
        [$svc] = $this->cargar($id);
        try {
            $svc->cancelar($id, Auth::user(), $this->input('motivo_cancelacion'));
            Session::flash('success', 'Formato cancelado.');
        } catch (ValidationException $e) {
            $this->flashErrors($e);
        }
        redirect('/formatos/' . $id);
    }

    /** POST /formatos/{id}/inventario: TI captura el número de inventario del equipo. */
    public function inventario(int $id): void
    {
        $this->requireModulo('formatos', Roles::TI);
        $this->verifyCsrf();
        [$svc, $f] = $this->cargar($id);
        try {
            if ($f['equipo_id'] === null || FormatoService::turno($f) === null) {
                throw new ValidationException(['El inventario solo se captura mientras el formato está en firma.']);
            }
            $svc->guardarInventario((int) $f['equipo_id'], $this->input('inventario'), Auth::user());
            Session::flash('success', 'Número de inventario guardado en el equipo.');
        } catch (ValidationException $e) {
            $this->flashErrors($e);
        }
        redirect('/formatos/' . $id);
    }

    /** GET /formatos/verificar?folio=&codigo= */
    public function verificar(): void
    {
        $this->requireLogin();
        $folio = trim((string) ($_GET['folio'] ?? ''));
        $codigo = trim((string) ($_GET['codigo'] ?? ''));
        $f = $folio !== '' && $codigo !== '' ? (new FormatoService(Database::getInstance()))->verificar($folio, $codigo) : null;
        $this->view('formatos/verificar', [
            'title' => 'Verificar formato', 'folio' => mb_substr($folio, 0, 30), 'codigo' => mb_substr($codigo, 0, 12),
            'buscado' => $folio !== '' || $codigo !== '', 'f' => $f,
            'firmas' => $f !== null ? (new FormatoService(Database::getInstance()))->firmas((int) $f['id']) : [],
        ]);
    }

    /** @return array{0: FormatoService, 1: array<string, mixed>} */
    private function cargar(int $id): array
    {
        $svc = new FormatoService(Database::getInstance());
        $f = $svc->find($id);
        $user = Auth::user();
        $ve = false;
        if ($f !== null) {
            $ticket = $f['ticket_id'] !== null ? (new TicketService(Database::getInstance()))->find((int) $f['ticket_id']) : null;
            $ve = (int) $user['id'] === (int) $f['usuario_id']
                || ((string) $f['jefe_ficha'] !== '' && (string) ($user['ficha'] ?? '') === (string) $f['jefe_ficha'])
                || in_array((int) $user['id'], array_map(static fn (array $x): int => (int) $x['firmante_id'], $svc->firmas($id)), true)
                || ($ticket !== null && TicketController::acceso($user, $ticket)[0])
                || ($user['rol'] ?? '') === 'admin_ti';
        }
        if (!$ve) {
            throw new HttpException(404, 'El formato no existe.');
        }
        return [$svc, $f];
    }

    /**
     * Datos listos para la hoja: datos del documento, firmas con su imagen y logos (data URI).
     *
     * @param array<string, mixed> $f
     * @return array<string, mixed>
     */
    private function documento(FormatoService $svc, array $f): array
    {
        $firmas = $svc->firmas((int) $f['id']);
        foreach ($firmas as $rol => $x) {
            $firmas[$rol]['src'] = self::dataUri($x['imagen'] ? STORAGE_PATH . '/' . $x['imagen'] : null);
        }
        return [
            'd'      => $svc->datos($f),
            'firmas' => $firmas,
            'logos'  => [
                'izq' => self::dataUri($f['logo_izq'] ? STORAGE_PATH . '/' . $f['logo_izq'] : null),
                'der' => self::dataUri($f['logo_der'] ? STORAGE_PATH . '/' . $f['logo_der'] : null),
                'pie' => self::dataUri($f['logo_pie'] ? STORAGE_PATH . '/' . $f['logo_pie'] : null),
            ],
        ];
    }

    private static function dataUri(?string $path): ?string
    {
        if ($path === null || str_contains($path, '..') || !is_file($path)) {
            return null;
        }
        $info = @getimagesize($path);
        $mime = is_array($info) ? (string) $info['mime'] : '';
        if (!in_array($mime, ['image/png', 'image/jpeg', 'image/webp'], true)) {
            return null;
        }
        return 'data:' . $mime . ';base64,' . base64_encode((string) file_get_contents($path));
    }

    private function flashErrors(ValidationException $e): void
    {
        foreach ($e->errors() as $error) {
            Session::flash('danger', $error);
        }
    }
}
