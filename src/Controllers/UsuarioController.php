<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Core\Roles;
use App\Config\Database;
use App\Core\Auth;
use App\Core\HttpException;
use App\Core\Session;
use App\Core\ValidationException;
use App\Services\CuentaService;
use App\Services\TrabajadorService;
use App\Services\Mail\Mailer;
use App\Services\VerificacionEmailService;
use App\Services\Mail\Notifier;

/**
 * Administración de usuarios (solo admin_ti): roles, activación y
 * restablecimiento de contraseña. Los trabajadores nacen del GECA; aquí solo
 * se crean cuentas de administración y de prueba (CuentaService). Nada se borra.
 */
final class UsuarioController extends Controller
{
    private const PER_PAGE = 30;
    private const ROLES = Roles::LABELS;

    public function index(): void
    {
        $this->requireRole(['admin_ti']);
        $pdo = Database::getInstance();

        $q = mb_substr(trim((string) ($_GET['q'] ?? '')), 0, 60);
        $rol = (string) ($_GET['rol'] ?? '');
        $estado = (string) ($_GET['estado'] ?? 'registrados');
        if (!array_key_exists($rol, self::ROLES)) {
            $rol = '';
        }
        if (!in_array($estado, ['registrados', 'sin_activar', 'inactivos', 'todos'], true)) {
            $estado = 'registrados';
        }

        $where = match ($estado) {
            'registrados' => ['u.registrado = 1', 'u.activo = 1'],
            'sin_activar' => ['u.registrado = 0', 'u.activo = 1'],
            'inactivos'   => ['u.activo = 0'],
            default       => ['1 = 1'],
        };
        $params = [];
        if ($rol !== '') {
            $where[] = 'u.rol = ?';
            $params[] = $rol;
        }
        if ($q !== '') {
            $like = '%' . addcslashes($q, '%_\\') . '%';
            $where[] = '(u.ficha = ? OR u.nombre LIKE ? OR u.email LIKE ?)';
            array_push($params, $q, $like, $like);
        }
        $whereSql = implode(' AND ', $where);

        $count = $pdo->prepare("SELECT COUNT(*) FROM usuarios u WHERE {$whereSql}");
        $count->execute($params);
        $total = (int) $count->fetchColumn();
        [$page, $pages, $offset] = TicketController::paginate($total, self::PER_PAGE);

        $stmt = $pdo->prepare(
            "SELECT u.id, u.ficha, u.nombre,
                    COALESCE(u.email, (SELECT v.email FROM verificaciones_email v WHERE v.usuario_id = u.id ORDER BY v.id DESC LIMIT 1)) AS email,
                    u.telefono_ext, u.rol, u.tipo_cuenta, u.registrado, u.activo, u.actualizado_en,
                    u.email_verificado_en, (u.password_hash IS NOT NULL) AS tiene_password,
                    (SELECT COUNT(*) FROM equipos e WHERE e.usuario_id = u.id AND e.vigente_en_geca = 1) AS equipos
               FROM usuarios u WHERE {$whereSql}
              ORDER BY FIELD(u.rol, 'admin_ti', 'supervisor', 'agente_ti', 'usuario'), u.nombre
              LIMIT " . self::PER_PAGE . " OFFSET {$offset}"
        );
        $stmt->execute($params);

        $this->view('usuarios/index', [
            'title'    => 'Usuarios',
            'usuarios' => $stmt->fetchAll(),
            'roles'    => self::ROLES,
            'q'        => $q,
            'rol'      => $rol,
            'estado'   => $estado,
            'page'     => $page,
            'pages'    => $pages,
            'total'    => $total,
            'mailDriver' => (string) config('mail.driver', 'off'),
        ]);
    }

    public function update(int $id): void
    {
        $this->requireRole(['admin_ti']);
        $this->verifyCsrf();
        $pdo = Database::getInstance();
        $target = $this->findUser($id);

        $rol = $this->input('rol');
        $activo = $this->input('activo') === '1' ? 1 : 0;
        if (!array_key_exists($rol, self::ROLES)) {
            Session::flash('danger', 'Rol inválido.');
            redirect($this->back());
        }
        if ($id === (int) Auth::id() && ($rol !== $target['rol'] || $activo !== (int) $target['activo'])) {
            Session::flash('danger', 'No puedes cambiar tu propio rol ni desactivar tu propia cuenta.');
            redirect($this->back());
        }
        if (Roles::esTi($rol) && (int) $target['registrado'] !== 1) {
            Session::flash('danger', 'Solo puedes dar rol de TI a cuentas que ya fueron activadas por el trabajador.');
            redirect($this->back());
        }
        if ($target['rol'] === 'admin_ti' && ($rol !== 'admin_ti' || $activo === 0)) {
            $admins = (int) $pdo->query("SELECT COUNT(*) FROM usuarios WHERE rol = 'admin_ti' AND activo = 1 AND registrado = 1")->fetchColumn();
            if ($admins <= 1) {
                Session::flash('danger', 'Debe quedar al menos un Admin TI activo.');
                redirect($this->back());
            }
        }

        $pdo->prepare('UPDATE usuarios SET rol = ?, activo = ? WHERE id = ?')->execute([$rol, $activo, $id]);
        Session::flash('success', "Usuario {$target['nombre']} actualizado: " . self::ROLES[$rol] . ($activo ? '' : ' (desactivado)') . '.');
        redirect($this->back());
    }

    public function resetPassword(int $id): void
    {
        $this->requireRole(['admin_ti']);
        $this->verifyCsrf();
        $target = $this->findUser($id);
        if ((int) $target['registrado'] !== 1) {
            Session::flash('warning', 'Esa ficha aún no activa su cuenta; puede hacerlo ella misma desde "Activa tu cuenta".');
            redirect($this->back());
        }
        if ($id === (int) Auth::id()) {
            Session::flash('warning', 'Para cambiar tu contraseña usa "Mi cuenta".');
            redirect($this->back());
        }

        $temporal = self::temporaryPassword();
        Database::getInstance()->prepare('UPDATE usuarios SET password_hash = ? WHERE id = ?')
            ->execute([password_hash($temporal, PASSWORD_DEFAULT), $id]);

        Session::flash('warning', "Contraseña temporal de {$target['nombre']} (ficha {$target['ficha']}): {$temporal} — compártela en persona y pídele que la cambie en \"Mi cuenta\". No se volverá a mostrar.");
        redirect($this->back());
    }

    /**
     * Respaldo cuando el correo de verificación no llega (spec 7.6 bis): el
     * admin confirma el correo en persona. Activa la cuenta si ya capturó
     * contraseña en el registro.
     */
    public function verifyEmail(int $id): void
    {
        $this->requireRole(['admin_ti']);
        $this->verifyCsrf();
        $stmt = Database::getInstance()->prepare('SELECT nombre, email, password_hash FROM usuarios WHERE id = ?');
        $stmt->execute([$id]);
        $u = $stmt->fetch();
        if (!$u) {
            throw new HttpException(404, 'El usuario no existe.');
        }
        $verificacion = new VerificacionEmailService(Database::getInstance());
        // En un registro pendiente el correo capturado vive solo en el enlace.
        $u['email'] = $u['email'] ?? $verificacion->correoPendiente($id);
        if ($u['email'] === null || $u['password_hash'] === null) {
            Session::flash('warning', 'Esa ficha aún no completa su registro (correo y contraseña).');
            redirect($this->back());
        }
        if ($verificacion->correoOcupado((string) $u['email'], $id)) {
            Session::flash('danger', "El correo {$u['email']} ya lo usa otra cuenta activa.");
            redirect($this->back());
        }
        Database::getInstance()->prepare('UPDATE usuarios SET email = ?, email_verificado_en = NOW(), registrado = 1 WHERE id = ?')->execute([$u['email'], $id]);
        Session::flash('success', "Correo {$u['email']} marcado como verificado para {$u['nombre']}.");
        redirect($this->back());
    }

    private const CAMPOS_CUENTA = ['ficha', 'nombre', 'email', 'rol', 'tipo_cuenta'];

    /** Ficha del trabajador: la ve todo TI; las acciones de cuenta, solo el Admin TI. */
    public function show(int $id): void
    {
        $this->requireRole(Roles::TI);
        $ficha = (new TrabajadorService(Database::getInstance()))->ficha($id, Auth::user());
        if ($ficha === null) {
            throw new HttpException(404, 'El trabajador no existe.');
        }
        $this->view('usuarios/show', [
            'title'    => $ficha['usuario']['nombre'],
            'f'        => $ficha,
            'roles'    => self::ROLES,
            'tipos'    => ['geca' => 'GECA (trabajador)'] + CuentaService::TIPOS,
            'esAdmin'  => (Auth::user()['rol'] ?? '') === 'admin_ti',
        ]);
    }

    public function changeType(int $id): void
    {
        $this->requireRole(['admin_ti']);
        $this->verifyCsrf();
        if ($id === (int) Auth::id()) {
            Session::flash('danger', 'No puedes cambiar el tipo de tu propia cuenta.');
            redirect('/ti/usuarios/' . $id);
        }
        try {
            (new CuentaService(Database::getInstance()))->cambiarTipo($id, $this->input('tipo_cuenta'), Auth::user());
            Session::flash('success', 'Tipo de cuenta actualizado.');
        } catch (ValidationException $e) {
            Session::flash('danger', implode(' ', $e->errors()));
        }
        redirect('/ti/usuarios/' . $id);
    }

    /** Formulario: crear cuenta de administración o de prueba. */
    public function create(): void
    {
        $this->requireRole(['admin_ti']);
        $this->view('usuarios/crear', ['title' => 'Crear cuenta', 'roles' => self::ROLES, 'tipos' => CuentaService::TIPOS]);
    }

    public function store(): void
    {
        $this->requireRole(['admin_ti']);
        $this->verifyCsrf();
        $in = [];
        foreach (self::CAMPOS_CUENTA as $c) {
            $in[$c] = $this->input($c);
        }
        $in['password'] = (string) ($_POST['password'] ?? '');
        $in['password_confirm'] = (string) ($_POST['password_confirm'] ?? '');
        try {
            (new CuentaService(Database::getInstance()))->crear($in, Auth::user());
        } catch (ValidationException $e) {
            foreach ($e->errors() as $error) {
                Session::flash('danger', $error);
            }
            $this->rememberInput(self::CAMPOS_CUENTA);
            redirect('/ti/usuarios/nueva');
        }
        Session::flash('success', 'Cuenta ' . strtoupper($in['ficha']) . ' creada. Ya puede entrar con su ficha o su correo.');
        redirect('/ti/usuarios?estado=todos&q=' . rawurlencode(strtoupper($in['ficha'])));
    }

    public function testMail(): void
    {
        $this->requireRole(['admin_ti']);
        $this->verifyCsrf();
        $email = Mailer::cleanAddress((string) (Auth::user()['email'] ?? ''));
        if ($email === '') {
            Session::flash('danger', 'Tu cuenta no tiene un correo válido.');
            redirect('/ti/usuarios');
        }
        $error = Notifier::sendTest($email);
        Session::flash($error === null ? 'success' : 'danger', $error === null
            ? "Correo de prueba enviado a {$email}. Revisa también la carpeta de spam."
            : 'No se pudo enviar el correo de prueba: ' . $error);
        redirect('/ti/usuarios');
    }

    /** @return array<string, mixed> */
    private function findUser(int $id): array
    {
        $stmt = Database::getInstance()->prepare('SELECT id, ficha, nombre, rol, activo, registrado FROM usuarios WHERE id = ?');
        $stmt->execute([$id]);
        $u = $stmt->fetch();
        if (!$u) {
            throw new HttpException(404, 'El usuario no existe.');
        }
        return $u;
    }

    private function back(): string
    {
        $ficha = (string) ($_POST['volver_ficha'] ?? '');
        if (preg_match('/^[1-9][0-9]{0,9}$/', $ficha) === 1) {
            return '/ti/usuarios/' . $ficha;
        }
        $qs = (string) ($_POST['volver'] ?? '');
        return '/ti/usuarios' . (preg_match('/^[A-Za-z0-9_=&%+.-]*$/', $qs) === 1 && $qs !== '' ? '?' . $qs : '');
    }

    /** 12 caracteres legibles que cumplen la política de contraseñas. */
    private static function temporaryPassword(): string
    {
        $sets = ['ABCDEFGHJKLMNPQRSTUVWXYZ', 'abcdefghijkmnpqrstuvwxyz', '23456789', '#$%*+?'];
        $chars = [];
        foreach ($sets as $set) {
            $chars[] = $set[random_int(0, strlen($set) - 1)];
        }
        $all = implode('', $sets);
        while (count($chars) < 12) {
            $chars[] = $all[random_int(0, strlen($all) - 1)];
        }
        for ($i = count($chars) - 1; $i > 0; $i--) {
            $j = random_int(0, $i);
            [$chars[$i], $chars[$j]] = [$chars[$j], $chars[$i]];
        }
        return implode('', $chars);
    }
}
