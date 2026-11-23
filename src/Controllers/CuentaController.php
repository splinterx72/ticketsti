<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Core\Roles;
use App\Config\Database;
use App\Core\Auth;
use App\Core\PasswordPolicy;
use App\Core\Session;
use App\Services\Catalog;
use PDOException;

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
            'SELECT ficha, nombre, jefe_nombre, email, telefono_ext, ubicacion_habitual_id, rol FROM usuarios WHERE id = ?'
        );
        $stmt->execute([(int) Auth::id()]);

        $this->view('cuenta/index', [
            'title'       => 'Mi cuenta',
            'cuenta'      => $stmt->fetch(),
            'ubicaciones' => Catalog::ubicaciones(),
            'minPassword' => Roles::esTi(Auth::user()['rol'] ?? null) ? PasswordPolicy::MIN_ADMIN : PasswordPolicy::MIN_USER,
        ]);
    }

    public function update(): void
    {
        $this->requireLogin();
        $this->verifyCsrf();
        $id = (int) Auth::id();

        $email = mb_strtolower($this->input('email'));
        $ext = $this->input('telefono_ext');
        $ubicacionId = (int) $this->input('ubicacion_habitual_id');

        $errors = [];
        if (!filter_var($email, FILTER_VALIDATE_EMAIL) || mb_strlen($email) > 150) {
            $errors[] = 'Captura un correo electrónico válido.';
        }
        if ($ext === '' || preg_match('/^[0-9 +()\/-]{2,30}$/', $ext) !== 1) {
            $errors[] = 'Captura tu teléfono o extensión (solo números, espacios, +, -, /).';
        }
        if ($ubicacionId <= 0 || !Catalog::ubicacionExiste($ubicacionId)) {
            $errors[] = 'Selecciona tu ubicación habitual.';
        }
        if ($errors === []) {
            try {
                Database::getInstance()
                    ->prepare('UPDATE usuarios SET email = ?, telefono_ext = ?, ubicacion_habitual_id = ? WHERE id = ?')
                    ->execute([$email, $ext, $ubicacionId, $id]);
                Session::flash('success', 'Datos actualizados.');
                redirect('/cuenta');
            } catch (PDOException $e) {
                if ((int) ($e->errorInfo[1] ?? 0) !== 1062) {
                    throw $e;
                }
                $errors[] = 'Ese correo ya está registrado en otra cuenta.';
            }
        }
        foreach ($errors as $error) {
            Session::flash('danger', $error);
        }
        $this->rememberInput(['email', 'telefono_ext', 'ubicacion_habitual_id']);
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
