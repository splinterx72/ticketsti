<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Config\Database;
use App\Core\Auth;
use App\Core\Session;
use App\Services\Mail\Notifier;
use App\Services\VerificacionEmailService;

/**
 * Verificación del correo de la empresa (spec 7.6 bis).
 *
 * Atiende a dos tipos de persona:
 *  - Registro nuevo sin sesión (su id va en la sesión, clave PENDIENTE).
 *  - Cuenta existente con sesión cuyo correo aún no está verificado.
 */
final class VerificacionController extends Controller
{
    public const PENDIENTE = '_registro_pendiente';

    /** "Revisa tu correo": reenviar o corregir el correo. */
    public function show(): void
    {
        $persona = $this->persona();
        if ($persona === null) {
            redirect(Auth::check() ? '/' : '/login');
        }
        $svc = new VerificacionEmailService(Database::getInstance());

        $this->view('auth/verificacion_pendiente', [
            'title'     => 'Verifica tu correo',
            'authUser'  => null,
            'persona'   => $persona,
            'enviadoA'  => $svc->correoPendiente((int) $persona['id']),
            'dominios'  => VerificacionEmailService::dominios(),
            'dominioOk' => $svc->dominioPermitido((string) ($persona['email'] ?? '')),
        ], 200, 'layouts/guest');
    }

    /** Envía (o reenvía) el enlace, opcionalmente a un correo corregido. */
    public function send(): void
    {
        $this->verifyCsrf();
        $persona = $this->persona();
        if ($persona === null) {
            redirect('/login');
        }
        $pdo = Database::getInstance();
        $svc = new VerificacionEmailService($pdo);
        $id = (int) $persona['id'];

        $email = mb_strtolower($this->input('email', (string) ($svc->correoPendiente($id) ?? $persona['email'])));
        if (mb_strlen($email) > 150 || !$svc->dominioPermitido($email)) {
            Session::flash('danger', 'Usa tu correo de la empresa (@' . implode(', @', VerificacionEmailService::dominios()) . ').');
            redirect('/verificar-correo');
        }
        if ($svc->correoOcupado($email, $id)) {
            Session::flash('danger', 'Ese correo ya está registrado en otra cuenta.');
            redirect('/verificar-correo');
        }
        if (!$svc->puedeReenviar($id)) {
            Session::flash('warning', 'Ya enviamos varios enlaces en la última hora. Revisa tu bandeja (y la carpeta de spam) o espera un poco.');
            redirect('/verificar-correo');
        }

        $error = Notifier::enlaceVerificacion($email, (string) $persona['nombre'], $svc->emitir($id, $email));
        Session::flash($error === null ? 'success' : 'danger', $error ?? "Te enviamos un enlace a {$email}. Ábrelo desde tu buzón para continuar.");
        redirect('/verificar-correo');
    }

    /**
     * Enlace recibido por correo: GET /verificar/{token}. Solo muestra la
     * confirmación (no gasta el enlace): los escáneres de correo de la empresa
     * abren los enlaces antes que la persona.
     */
    public function confirm(string $token): void
    {
        $svc = new VerificacionEmailService(Database::getInstance());
        $enlace = $svc->consultar($token);
        if ($enlace === null) {
            Session::flash('danger', 'El enlace no es válido, ya se usó o venció (dura 24 horas). Solicita uno nuevo.');
            redirect($this->persona() !== null ? '/verificar-correo' : '/login');
        }
        if ($enlace['estado'] === 'verificado') {
            Session::flash('success', 'Tu correo ya está verificado.' . (Auth::check() ? '' : ' Inicia sesión.'));
            redirect(Auth::check() ? '/' : '/login');
        }

        $this->view('auth/verificacion_confirmar', [
            'title'    => 'Confirma tu correo',
            'authUser' => null,
            'enlace'   => $enlace,
            'token'    => $token,
        ], 200, 'layouts/guest');
    }

    /** POST /verificar/{token}: verifica con la contraseña de la cuenta. */
    public function verify(string $token): void
    {
        $this->verifyCsrf();
        $svc = new VerificacionEmailService(Database::getInstance());
        $enlace = $svc->consultar($token);
        if ($enlace === null || $enlace['estado'] !== 'valido') {
            redirect('/verificar/' . rawurlencode($token));
        }
        $idDelToken = $enlace['usuario_id'];
        $nuevo = $svc->esRegistroNuevo($idDelToken);

        $id = $svc->verificar($token, (string) ($_POST['password'] ?? ''));
        if ($id === null) {
            Session::flash('danger', 'La contraseña no coincide con la de esa cuenta (o el correo ya lo usa otra cuenta).');
            redirect('/verificar/' . rawurlencode($token));
        }

        Session::remove(self::PENDIENTE);
        Auth::login($id);
        if ($nuevo) {
            Notifier::usuarioRegistrado($id);
        }
        Session::flash('success', $nuevo
            ? '¡Correo verificado y cuenta activada! Ya puedes levantar tickets de soporte.'
            : 'Correo verificado. Gracias.');
        redirect('/');
    }

    /** @return array<string, mixed>|null */
    private function persona(): ?array
    {
        if (Auth::check()) {
            $u = Auth::user();
            return $u['email_verificado_en'] === null || isset($_SESSION[self::PENDIENTE]) ? $u : null;
        }
        $pendiente = Session::get(self::PENDIENTE);
        if (!is_int($pendiente)) {
            return null;
        }
        $stmt = Database::getInstance()->prepare(
            'SELECT id, ficha, nombre, email FROM usuarios WHERE id = ? AND registrado = 0 AND activo = 1'
        );
        $stmt->execute([$pendiente]);
        return $stmt->fetch() ?: null;
    }
}
