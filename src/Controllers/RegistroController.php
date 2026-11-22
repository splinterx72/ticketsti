<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Config\Database;
use App\Core\Auth;
use App\Core\PasswordPolicy;
use App\Core\Session;
use App\Services\Catalog;
use PDOException;

/**
 * Auto-registro por ficha (CLAUDE.md, sección 3.B).
 *
 * Paso 1: el trabajador captura su ficha; debe existir en usuarios (cargada
 *         desde el GECA) con registrado = 0.
 * Paso 2: confirma nombre y jefe, captura correo, extensión, contraseña y
 *         ubicación habitual. La cuenta pasa a registrado = 1.
 */
final class RegistroController extends Controller
{
    private const SESSION_KEY = '_registro';
    private const TTL = 900;              // 15 min para completar el paso 2
    private const MAX_LOOKUPS = 10;       // búsquedas de ficha por ventana
    private const LOOKUP_WINDOW = 600;

    public function showFicha(): void
    {
        if (Auth::check()) {
            redirect('/');
        }
        $this->view('auth/registro_ficha', ['title' => 'Activar mi cuenta', 'authUser' => null], 200, 'layouts/guest');
    }

    public function lookupFicha(): void
    {
        $this->verifyCsrf();
        if ($this->tooManyLookups()) {
            Session::flash('danger', 'Demasiados intentos. Espera unos minutos e inténtalo de nuevo.');
            redirect('/registro');
        }

        $ficha = strtoupper((string) preg_replace('/\s+/', '', $this->input('ficha')));
        if (preg_match('/^[A-Z0-9-]{1,20}$/', $ficha) !== 1) {
            Session::flash('danger', 'Captura una ficha válida (solo letras, números o guion).');
            redirect('/registro');
        }

        $stmt = Database::getInstance()->prepare(
            'SELECT id, registrado, activo FROM usuarios WHERE ficha = ? LIMIT 1'
        );
        $stmt->execute([$ficha]);
        $user = $stmt->fetch();

        if (!$user) {
            $this->rememberInput(['ficha']);
            Session::flash('warning', 'Tu ficha no aparece en el padrón del GECA. Si acabas de recibir equipo, espera a la carga del lunes o contacta a Soporte TI.');
            redirect('/registro');
        }
        if ((int) $user['activo'] !== 1) {
            Session::flash('danger', 'Tu cuenta está desactivada. Contacta a Soporte TI.');
            redirect('/registro');
        }
        if ((int) $user['registrado'] === 1) {
            Session::flash('info', 'Esta ficha ya tiene una cuenta activa. Inicia sesión con tu ficha o correo.');
            redirect('/login');
        }

        Session::set(self::SESSION_KEY, ['id' => (int) $user['id'], 'exp' => time() + self::TTL]);
        redirect('/registro/completar');
    }

    public function showCompletar(): void
    {
        $user = $this->pendingUser();
        $ubicacionSugerida = $this->ubicacionSugerida((int) $user['id']);

        $this->view('auth/registro_completar', [
            'title'       => 'Completa tu registro',
            'authUser'    => null,
            'pendiente'   => $user,
            'ubicaciones' => Catalog::ubicaciones(),
            'ubicacionSugerida' => $ubicacionSugerida,
            'minPassword' => PasswordPolicy::MIN_USER,
        ], 200, 'layouts/guest');
    }

    public function completar(): void
    {
        $this->verifyCsrf();
        $user = $this->pendingUser();

        $email = mb_strtolower($this->input('email'));
        $ext = $this->input('telefono_ext');
        $ubicacionId = (int) $this->input('ubicacion_habitual_id');
        $password = (string) ($_POST['password'] ?? '');
        $confirm = (string) ($_POST['password_confirm'] ?? '');

        $errors = [];
        if (empty($_POST['confirmo'])) {
            $errors[] = 'Confirma que el nombre y jefe mostrados son correctos.';
        }
        if (!filter_var($email, FILTER_VALIDATE_EMAIL) || mb_strlen($email) > 150) {
            $errors[] = 'Captura un correo electrónico válido.';
        }
        if ($ext === '' || preg_match('/^[0-9 +()\/-]{2,30}$/', $ext) !== 1) {
            $errors[] = 'Captura tu teléfono o extensión (solo números, espacios, +, -, /).';
        }
        if ($ubicacionId <= 0 || !Catalog::ubicacionExiste($ubicacionId)) {
            $errors[] = 'Selecciona tu ubicación habitual.';
        }
        $errors = array_merge($errors, PasswordPolicy::errors($password, [(string) $user['ficha'], $email, (string) $user['nombre']]));
        if (!hash_equals($password, $confirm)) {
            $errors[] = 'La confirmación no coincide con la contraseña.';
        }

        $pdo = Database::getInstance();
        if ($errors === []) {
            $stmt = $pdo->prepare('SELECT 1 FROM usuarios WHERE email = ? AND id <> ?');
            $stmt->execute([$email, (int) $user['id']]);
            if ($stmt->fetchColumn()) {
                $errors[] = 'Ese correo ya está registrado en otra cuenta.';
            }
        }

        if ($errors !== []) {
            foreach ($errors as $error) {
                Session::flash('danger', $error);
            }
            $this->rememberInput(['email', 'telefono_ext', 'ubicacion_habitual_id']);
            redirect('/registro/completar');
        }

        try {
            // "AND registrado = 0" evita activar dos veces la misma ficha en paralelo.
            $stmt = $pdo->prepare(
                'UPDATE usuarios
                    SET email = ?, telefono_ext = ?, password_hash = ?, ubicacion_habitual_id = ?, registrado = 1
                  WHERE id = ? AND registrado = 0 AND activo = 1'
            );
            $stmt->execute([$email, $ext, password_hash($password, PASSWORD_DEFAULT), $ubicacionId, (int) $user['id']]);
        } catch (PDOException $e) {
            if ((int) ($e->errorInfo[1] ?? 0) === 1062) {
                Session::flash('danger', 'Ese correo ya está registrado en otra cuenta.');
                $this->rememberInput(['email', 'telefono_ext', 'ubicacion_habitual_id']);
                redirect('/registro/completar');
            }
            throw $e;
        }

        Session::remove(self::SESSION_KEY);
        if ($stmt->rowCount() !== 1) {
            Session::flash('info', 'Esta cuenta ya fue activada. Inicia sesión.');
            redirect('/login');
        }

        Auth::login((int) $user['id']);
        Session::flash('success', '¡Cuenta activada! Ya puedes levantar tickets de soporte.');
        redirect('/');
    }

    /** @return array<string, mixed> */
    private function pendingUser(): array
    {
        $pending = Session::get(self::SESSION_KEY);
        if (!is_array($pending) || (int) ($pending['exp'] ?? 0) < time()) {
            Session::remove(self::SESSION_KEY);
            Session::flash('warning', 'Captura tu ficha para comenzar el registro.');
            redirect('/registro');
        }

        $stmt = Database::getInstance()->prepare(
            'SELECT id, ficha, nombre, jefe_nombre, registrado, activo FROM usuarios WHERE id = ?'
        );
        $stmt->execute([(int) $pending['id']]);
        $user = $stmt->fetch();
        if (!$user || (int) $user['registrado'] === 1 || (int) $user['activo'] !== 1) {
            Session::remove(self::SESSION_KEY);
            redirect('/registro');
        }
        return $user;
    }

    /** Ubicación GECA de su equipo principal (o el primero) como sugerencia. */
    private function ubicacionSugerida(int $userId): ?int
    {
        $stmt = Database::getInstance()->prepare(
            'SELECT COALESCE(ubicacion_real_id, ubicacion_geca_id)
               FROM equipos
              WHERE usuario_id = ? AND COALESCE(ubicacion_real_id, ubicacion_geca_id) IS NOT NULL
              ORDER BY es_principal DESC, vigente_en_geca DESC, id
              LIMIT 1'
        );
        $stmt->execute([$userId]);
        $id = $stmt->fetchColumn();
        return $id !== false ? (int) $id : null;
    }

    private function tooManyLookups(): bool
    {
        $now = time();
        $hits = array_values(array_filter(
            (array) Session::get('_registro_hits', []),
            static fn (mixed $t): bool => is_int($t) && $t > $now - self::LOOKUP_WINDOW
        ));
        $hits[] = $now;
        Session::set('_registro_hits', $hits);
        return count($hits) > self::MAX_LOOKUPS;
    }
}
