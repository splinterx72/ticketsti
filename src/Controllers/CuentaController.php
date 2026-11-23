<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Core\Roles;
use App\Config\Database;
use App\Core\Auth;
use App\Core\PasswordPolicy;
use App\Core\Session;
use App\Core\ValidationException;
use App\Services\Mail\Notifier;
use App\Services\UbicacionFisicaService;
use App\Services\VerificacionEmailService;

/**
 * "Mi cuenta": datos de contacto, ubicación habitual y cambio de contraseña.
 * Nombre, ficha y jefe vienen del GECA y no se editan aquí.
 */
final class CuentaController extends Controller
{
    public function show(): void
    {
        $this->requireLogin();
        $stmt = Database::getInstance()->prepare(
            'SELECT ficha, nombre, jefe_nombre, email, telefono_ext, rol,
                    habitual_ubicacion_fisica_id AS ubicacion_fisica_id, habitual_nivel_id AS nivel_id,
                    habitual_ala AS ala, habitual_referencia AS referencia
               FROM usuarios WHERE id = ?'
        );
        $stmt->execute([(int) Auth::id()]);
        $fisicas = new UbicacionFisicaService(Database::getInstance());

        $this->view('cuenta/index', [
            'title'       => 'Mi cuenta',
            'cuenta'      => $stmt->fetch(),
            'catalogo'    => $fisicas->catalogo(),
            'hayCatalogo' => $fisicas->hayCatalogo(),
            'dominios'    => VerificacionEmailService::dominios(),
            'minPassword' => Roles::esTi(Auth::user()['rol'] ?? null) ? PasswordPolicy::MIN_ADMIN : PasswordPolicy::MIN_USER,
        ]);
    }

    public function update(): void
    {
        $this->requireLogin();
        $this->verifyCsrf();
        $user = Auth::user();
        $id = (int) $user['id'];
        $pdo = Database::getInstance();
        $verificacion = new VerificacionEmailService($pdo);
        $fisicas = new UbicacionFisicaService($pdo);

        $email = mb_strtolower($this->input('email'));
        $ext = $this->input('telefono_ext');

        $errors = [];
        if (mb_strlen($email) > 150 || !$verificacion->dominioPermitido($email)) {
            $errors[] = 'Usa tu correo de la empresa (@' . implode(', @', VerificacionEmailService::dominios()) . ').';
        }
        if ($ext === '' || preg_match('/^[0-9 +()\/-]{2,30}$/', $ext) !== 1) {
            $errors[] = 'Captura tu teléfono o extensión (solo números, espacios, +, -, /).';
        }
        $fisica = null;
        if ($fisicas->hayCatalogo() || (int) $this->input('habitual_ubicacion_fisica_id') > 0) {
            try {
                $fisica = $fisicas->validar($_POST, 'habitual');
            } catch (ValidationException $e) {
                $errors = array_merge($errors, $e->errors());
            }
        }
        $cambiaCorreo = $errors === [] && strcasecmp($email, (string) $user['email']) !== 0;
        if ($cambiaCorreo) {
            if ($verificacion->correoOcupado($email, $id)) {
                $errors[] = 'Ese correo ya está registrado en otra cuenta.';
            } elseif (!$verificacion->puedeReenviar($id)) {
                $errors[] = 'Ya enviamos varios enlaces en la última hora; inténtalo más tarde.';
            }
        }

        if ($errors !== []) {
            foreach ($errors as $error) {
                Session::flash('danger', $error);
            }
            $this->rememberInput(['email', 'telefono_ext', 'habitual_instalacion_id', 'habitual_ubicacion_fisica_id', 'habitual_nivel_id', 'habitual_ala', 'habitual_referencia']);
            redirect('/cuenta');
        }

        $pdo->prepare(
            'UPDATE usuarios SET telefono_ext = ?, habitual_ubicacion_fisica_id = ?, habitual_nivel_id = ?, habitual_ala = ?, habitual_referencia = ?
              WHERE id = ?'
        )->execute([$ext, $fisica['ubicacion_fisica_id'] ?? null, $fisica['nivel_id'] ?? null, $fisica['ala'] ?? null, $fisica['referencia'] ?? null, $id]);

        if ($cambiaCorreo) {
            // El correo nuevo se aplica solo cuando se confirma desde el buzón.
            $error = Notifier::enlaceVerificacion($email, (string) $user['nombre'], $verificacion->emitir($id, $email));
            Session::flash($error === null ? 'info' : 'danger', $error ?? "Datos guardados. Para cambiar tu correo, abre el enlace que enviamos a {$email}; mientras tanto seguimos usando {$user['email']}.");
        } else {
            Session::flash('success', 'Datos actualizados.');
        }
        redirect('/cuenta');
    }

    public function password(): void
    {
        $this->requireLogin();
        $this->verifyCsrf();
        $user = Auth::user();
        $pdo = Database::getInstance();

        $actual = (string) ($_POST['password_actual'] ?? '');
        $nueva = (string) ($_POST['password'] ?? '');
        $confirm = (string) ($_POST['password_confirm'] ?? '');

        $stmt = $pdo->prepare('SELECT password_hash FROM usuarios WHERE id = ?');
        $stmt->execute([(int) $user['id']]);
        $hash = (string) $stmt->fetchColumn();

        $errors = [];
        if (!password_verify($actual, $hash)) {
            $errors[] = 'La contraseña actual no es correcta.';
        }
        $min = Roles::esTi($user['rol']) ? PasswordPolicy::MIN_ADMIN : PasswordPolicy::MIN_USER;
        $errors = array_merge($errors, PasswordPolicy::errors($nueva, [(string) $user['ficha'], (string) $user['email'], (string) $user['nombre']], $min));
        if (!hash_equals($nueva, $confirm)) {
            $errors[] = 'La confirmación no coincide con la nueva contraseña.';
        }
        if ($errors === [] && hash_equals($actual, $nueva)) {
            $errors[] = 'La nueva contraseña debe ser distinta a la actual.';
        }

        if ($errors !== []) {
            foreach ($errors as $error) {
                Session::flash('danger', $error);
            }
            redirect('/cuenta#password');
        }

        $pdo->prepare('UPDATE usuarios SET password_hash = ? WHERE id = ?')
            ->execute([password_hash($nueva, PASSWORD_DEFAULT), (int) $user['id']]);
        Session::regenerate();
        Session::flash('success', 'Contraseña actualizada.');
        redirect('/cuenta');
    }
}
