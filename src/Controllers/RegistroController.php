<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Config\Database;
use App\Core\Auth;
use App\Core\PasswordPolicy;
use App\Core\Session;
use App\Core\ValidationException;
use App\Services\Mail\Notifier;
use App\Services\UbicacionFisicaService;
use App\Services\VerificacionEmailService;

/**
 * Auto-registro por ficha (CLAUDE.md, sección 3.B).
 *
 * Paso 1: el trabajador captura su ficha; debe existir en usuarios (cargada
 *         desde el GECA) con registrado = 0.
 * Paso 2: confirma nombre y jefe, captura correo de la empresa, extensión,
 *         contraseña y ubicación física actual.
 * Paso 3: abre el enlace enviado a su correo; entonces la cuenta pasa a
 *         registrado = 1 y se avisa al supervisor de su instalación.
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
        $fisicas = new UbicacionFisicaService(Database::getInstance());

        $this->view('auth/registro_completar', [
            'title'       => 'Completa tu registro',
            'authUser'    => null,
            'pendiente'   => $user,
            'catalogo'    => $fisicas->catalogo(),
            'hayCatalogo' => $fisicas->hayCatalogo(),
            'sugerida'    => $this->ubicacionSugerida((int) $user['id']),
            'dominios'    => VerificacionEmailService::dominios(),
            'minPassword' => PasswordPolicy::MIN_USER,
        ], 200, 'layouts/guest');
    }

    public function completar(): void
    {
        $this->verifyCsrf();
        $user = $this->pendingUser();
        $pdo = Database::getInstance();
        $verificacion = new VerificacionEmailService($pdo);
        $fisicas = new UbicacionFisicaService($pdo);

        $email = mb_strtolower($this->input('email'));
        $ext = $this->input('telefono_ext');
        $password = (string) ($_POST['password'] ?? '');
        $confirm = (string) ($_POST['password_confirm'] ?? '');

        $errors = [];
        if (empty($_POST['confirmo'])) {
            $errors[] = 'Confirma que el nombre y jefe mostrados son correctos.';
        }
        if (mb_strlen($email) > 150 || !$verificacion->dominioPermitido($email)) {
            $errors[] = 'Captura tu correo de la empresa (@' . implode(', @', VerificacionEmailService::dominios()) . ').';
        }
        if ($ext === '' || preg_match('/^[0-9 +()\/-]{2,30}$/', $ext) !== 1) {
            $errors[] = 'Captura tu teléfono o extensión (solo números, espacios, +, -, /).';
        }
        $celular = VerificacionEmailService::celular($this->input('celular'));
        if ($celular === null) {
            $errors[] = 'El celular debe tener al menos 10 dígitos (solo números, espacios, +, -, paréntesis).';
        }
        $fisica = null;
        if ($fisicas->hayCatalogo() || (int) $this->input('habitual_ubicacion_fisica_id') > 0) {
            try {
                $fisica = $fisicas->validar($_POST, 'habitual');
            } catch (ValidationException $e) {
                $errors = array_merge($errors, $e->errors());
            }
        }
        $errors = array_merge($errors, PasswordPolicy::errors($password, [(string) $user['ficha'], $email, (string) $user['nombre']]));
        if (!hash_equals($password, $confirm)) {
            $errors[] = 'La confirmación no coincide con la contraseña.';
        }
        if ($errors === [] && $verificacion->correoOcupado($email, (int) $user['id'])) {
            $errors[] = 'Ese correo ya está registrado en otra cuenta.';
        }

        if ($errors !== []) {
            foreach ($errors as $error) {
                Session::flash('danger', $error);
            }
            $this->rememberInput(['email', 'telefono_ext', 'celular', 'habitual_instalacion_id', 'habitual_ubicacion_fisica_id', 'habitual_nivel_id', 'habitual_ala', 'habitual_referencia']);
            redirect('/registro/completar');
        }

        // La cuenta queda pendiente (registrado = 0) y el correo solo viaja en el
        // enlace: se guarda en la cuenta hasta que su dueño lo verifica.
        $token = $verificacion->prepararRegistro((int) $user['id'], $email, $ext, $password, $fisica, (string) $celular);

        Session::remove(self::SESSION_KEY);
        if ($token === null) {
            Session::flash('info', 'Esta cuenta ya fue activada. Inicia sesión.');
            redirect('/login');
        }

        Session::set(VerificacionController::PENDIENTE, (int) $user['id']);
        $error = Notifier::enlaceVerificacion($email, (string) $user['nombre'], $token);
        if ($error !== null) {
            Session::flash('danger', $error);
        }
        redirect('/verificar-correo');
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

    /**
     * Sugerencia de ubicación: la física real de su equipo principal o, si
     * no hay, la instalación equivalente de su inmueble GECA.
     *
     * @return array<string, mixed>
     */
    private function ubicacionSugerida(int $userId): array
    {
        $pdo = Database::getInstance();
        $stmt = $pdo->prepare(
            'SELECT e.real_ubicacion_fisica_id, e.real_nivel_id, e.real_ala, ug.inmueble
               FROM equipos e LEFT JOIN ubicaciones ug ON ug.id = e.ubicacion_geca_id
              WHERE e.usuario_id = ?
              ORDER BY e.es_principal DESC, e.vigente_en_geca DESC, e.id
              LIMIT 1'
        );
        $stmt->execute([$userId]);
        $eq = $stmt->fetch();
        if (!$eq) {
            return [];
        }
        if ($eq['real_ubicacion_fisica_id'] !== null) {
            return ['ubicacion_fisica_id' => $eq['real_ubicacion_fisica_id'], 'nivel_id' => $eq['real_nivel_id'], 'ala' => $eq['real_ala']];
        }
        $inst = (new UbicacionFisicaService($pdo))->instalacionDeInmuebleGeca($eq['inmueble']);
        return $inst !== null ? ['instalacion_id' => $inst] : [];
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
