<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Core\Auth;
use App\Core\HttpException;
use App\Core\PasswordPolicy;
use App\Core\Router;
use App\Core\Session;
use App\Services\Installer\Installer;
use App\Services\Installer\InstallerException;
use Throwable;

/**
 * Asistente web de instalación para hosting compartido (cPanel / NEUBOX).
 *
 * Pasos: 1 Requisitos y clave → 2 Base de datos → 3 Sitio → 4 Administrador
 *        → 5 Confirmar e instalar → Finalizado.
 *
 * Solo está disponible mientras NO exista config/config.php. El progreso se
 * guarda en la sesión; la contraseña del administrador se guarda ya cifrada.
 */
final class InstallerController extends Controller
{
    private const STATE = '_installer';
    private const DONE = '_install_done';
    private const MAX_KEY_ATTEMPTS = 5;
    private const KEY_LOCK_SECONDS = 600;

    private Installer $installer;

    public function __construct()
    {
        $this->installer = new Installer(ROOT_PATH);
    }

    // ------------------------------------------------------------------
    //  Paso 1 — Requisitos + clave de instalación
    // ------------------------------------------------------------------

    public function requirements(): void
    {
        $this->guardNotInstalled();

        $keyReady = $this->installer->ensureInstallKey();
        $baseUrl = Installer::detectBaseUrl(Router::basePath());
        $check = $this->installer->requirements($baseUrl);
        $state = $this->state();

        $this->render('requisitos', 1, [
            'title'       => 'Requisitos del servidor',
            'items'       => $check['items'],
            'canContinue' => $check['canContinue'],
            'keyReady'    => $keyReady,
            'keyOk'       => !empty($state['key_ok']),
            'rootPath'    => ROOT_PATH,
        ]);
    }

    public function verifyKey(): void
    {
        $this->guardNotInstalled();
        $this->verifyCsrf();

        $lockedUntil = (int) Session::get('_install_key_lock', 0);
        if ($lockedUntil > time()) {
            Session::flash('danger', 'Demasiados intentos con clave incorrecta. Espera ' . (int) ceil(($lockedUntil - time()) / 60) . ' minuto(s).');
            redirect('/instalacion');
        }

        if (!$this->installer->verifyInstallKey($this->input('clave'))) {
            $attempts = (int) Session::get('_install_key_attempts', 0) + 1;
            Session::set('_install_key_attempts', $attempts);
            if ($attempts >= self::MAX_KEY_ATTEMPTS) {
                Session::set('_install_key_lock', time() + self::KEY_LOCK_SECONDS);
                Session::set('_install_key_attempts', 0);
            }
            Session::flash('danger', 'La clave de instalación no coincide. Ábrela de nuevo en storage/install.key.');
            redirect('/instalacion');
        }

        Session::remove('_install_key_attempts');
        Session::regenerate();
        $this->updateState(['key_ok' => true]);
        Session::flash('success', 'Clave verificada.');
        redirect('/instalacion');
    }

    // ------------------------------------------------------------------
    //  Paso 2 — Base de datos
    // ------------------------------------------------------------------

    public function database(): void
    {
        $this->requireStep(2);
        $state = $this->state();
        $db = $state['db'] ?? [];
        $prefix = $this->installer->cpanelUser();

        $this->render('base_datos', 2, [
            'title'  => 'Base de datos',
            'db'     => [
                'host'     => $this->oldOr('host', $db['host'] ?? 'localhost'),
                'port'     => $this->oldOr('port', (string) ($db['port'] ?? 3306)),
                'database' => $this->oldOr('database', $db['database'] ?? ($prefix ? $prefix . '_' : '')),
                'username' => $this->oldOr('username', $db['username'] ?? ($prefix ? $prefix . '_' : '')),
            ],
            'hasPassword' => isset($db['password']) && $db['password'] !== '',
            'report' => $state['db_report'] ?? null,
            'prefix' => $prefix,
        ]);
    }

    public function saveDatabase(): void
    {
        $this->requireStep(2);
        $this->verifyCsrf();

        $previous = $this->state()['db'] ?? [];
        $password = (string) ($_POST['password'] ?? '');
        if ($password === '' && !empty($_POST['keep_password']) && isset($previous['password'])) {
            $password = (string) $previous['password'];
        }

        $db = [
            'host'     => $this->input('host'),
            'port'     => (int) $this->input('port', '3306'),
            'database' => $this->input('database'),
            'username' => $this->input('username'),
            'password' => $password,
        ];

        $errors = [];
        if ($db['host'] === '' || preg_match('/^[A-Za-z0-9.\-_:\[\]]{1,255}$/', $db['host']) !== 1) {
            $errors[] = 'Captura un host válido (en NEUBOX normalmente es "localhost").';
        }
        if ($db['port'] < 1 || $db['port'] > 65535) {
            $errors[] = 'El puerto debe estar entre 1 y 65535 (normalmente 3306).';
        }
        if (preg_match('/^[A-Za-z0-9_$]{1,64}$/', $db['database']) !== 1) {
            $errors[] = 'El nombre de la base solo admite letras, números y guion bajo (ej. usuario_mesaayuda).';
        }
        if ($db['username'] === '' || mb_strlen($db['username']) > 80) {
            $errors[] = 'Captura el usuario de MySQL (ej. usuario_tickets).';
        }
        if ($db['password'] === '') {
            $errors[] = 'Captura la contraseña del usuario de MySQL.';
        }

        if ($errors === []) {
            try {
                $report = $this->installer->inspectDatabase($db);
                if ($report['mode'] === 'partial') {
                    $errors[] = 'La base contiene solo algunas tablas del sistema (' . implode(', ', $report['existing'])
                        . '). El instalador no borra datos: elimínalas en phpMyAdmin o usa una base vacía.';
                } else {
                    $this->updateState(['db' => $db, 'db_report' => $report]);
                    Session::flash('success', 'Conexión exitosa con ' . $report['server'] . ' ' . $report['version'] . '.');
                    redirect('/instalacion/sitio');
                }
            } catch (InstallerException $e) {
                $errors[] = $e->getMessage();
            }
        }

        foreach ($errors as $error) {
            Session::flash('danger', $error);
        }
        $this->rememberInput(['host', 'port', 'database', 'username']);
        redirect('/instalacion/base-datos');
    }

    // ------------------------------------------------------------------
    //  Paso 3 — Sitio
    // ------------------------------------------------------------------

    public function site(): void
    {
        $this->requireStep(3);
        $site = $this->state()['site'] ?? [];

        $this->render('sitio', 3, [
            'title'     => 'Datos del sitio',
            'site'      => [
                'name'     => $this->oldOr('name', $site['name'] ?? 'Mesa de Ayuda TI'),
                'base_url' => $this->oldOr('base_url', $site['base_url'] ?? Installer::detectBaseUrl(Router::basePath())),
                'timezone' => $this->oldOr('timezone', $site['timezone'] ?? 'America/Mexico_City'),
                'force_https' => (bool) ($site['force_https'] ?? Installer::isHttps()),
            ],
            'timezones' => Installer::TIMEZONES,
            'isHttps'   => Installer::isHttps(),
        ]);
    }

    public function saveSite(): void
    {
        $this->requireStep(3);
        $this->verifyCsrf();

        $site = [
            'name'        => $this->input('name'),
            'base_url'    => rtrim($this->input('base_url'), '/'),
            'timezone'    => $this->input('timezone'),
            'force_https' => !empty($_POST['force_https']) && Installer::isHttps(),
        ];

        $errors = [];
        if ($site['name'] === '' || mb_strlen($site['name']) > 80) {
            $errors[] = 'El nombre del sistema es obligatorio (máx. 80 caracteres).';
        }
        $scheme = strtolower((string) parse_url($site['base_url'], PHP_URL_SCHEME));
        if (!filter_var($site['base_url'], FILTER_VALIDATE_URL) || !in_array($scheme, ['http', 'https'], true)
            || parse_url($site['base_url'], PHP_URL_QUERY) !== null || parse_url($site['base_url'], PHP_URL_FRAGMENT) !== null) {
            $errors[] = 'La URL del sistema debe ser una dirección completa, ej. https://tickets.tudominio.com';
        }
        if (!array_key_exists($site['timezone'], Installer::TIMEZONES)) {
            $errors[] = 'Selecciona una zona horaria de la lista.';
        }

        if ($errors !== []) {
            foreach ($errors as $error) {
                Session::flash('danger', $error);
            }
            $this->rememberInput(['name', 'base_url', 'timezone']);
            redirect('/instalacion/sitio');
        }

        $this->updateState(['site' => $site]);
        redirect('/instalacion/administrador');
    }

    // ------------------------------------------------------------------
    //  Paso 4 — Administrador
    // ------------------------------------------------------------------

    public function admin(): void
    {
        $this->requireStep(4);
        $admin = $this->state()['admin'] ?? [];

        $this->render('administrador', 4, [
            'title' => 'Cuenta de administrador',
            'admin' => [
                'ficha'  => $this->oldOr('ficha', $admin['ficha'] ?? ''),
                'nombre' => $this->oldOr('nombre', $admin['nombre'] ?? ''),
                'email'  => $this->oldOr('email', $admin['email'] ?? ''),
            ],
            'hasPassword' => isset($admin['password_hash']),
        ]);
    }

    public function saveAdmin(): void
    {
        $this->requireStep(4);
        $this->verifyCsrf();

        $previous = $this->state()['admin'] ?? [];
        $ficha = strtoupper($this->input('ficha'));
        $nombre = (string) preg_replace('/\s+/u', ' ', $this->input('nombre'));
        $email = mb_strtolower($this->input('email'));
        $password = (string) ($_POST['password'] ?? '');
        $confirm = (string) ($_POST['password_confirm'] ?? '');
        $keep = $password === '' && $confirm === '' && isset($previous['password_hash']);

        $errors = [];
        if (preg_match('/^[A-Z0-9-]{1,20}$/', $ficha) !== 1) {
            $errors[] = 'La ficha es obligatoria: letras, números o guion, máximo 20 caracteres.';
        }
        if (mb_strlen($nombre) < 3 || mb_strlen($nombre) > 150) {
            $errors[] = 'Captura el nombre completo (3 a 150 caracteres).';
        }
        if (!filter_var($email, FILTER_VALIDATE_EMAIL) || mb_strlen($email) > 150) {
            $errors[] = 'Captura un correo electrónico válido.';
        }
        if (!$keep) {
            $errors = array_merge($errors, PasswordPolicy::errors($password, [$ficha, $email, $nombre], PasswordPolicy::MIN_ADMIN));
            if (!hash_equals($password, $confirm)) {
                $errors[] = 'La confirmación no coincide con la contraseña.';
            }
        }

        if ($errors !== []) {
            foreach ($errors as $error) {
                Session::flash('danger', $error);
            }
            $this->rememberInput(['ficha', 'nombre', 'email']);
            redirect('/instalacion/administrador');
        }

        $this->updateState(['admin' => [
            'ficha'         => $ficha,
            'nombre'        => $nombre,
            'email'         => $email,
            'password_hash' => $keep ? (string) $previous['password_hash'] : password_hash($password, PASSWORD_DEFAULT),
        ]]);
        redirect('/instalacion/confirmar');
    }

    // ------------------------------------------------------------------
    //  Paso 5 — Confirmar e instalar
    // ------------------------------------------------------------------

    public function confirm(): void
    {
        $this->requireStep(5);
        $state = $this->state();

        $this->render('confirmar', 5, [
            'title'  => 'Confirmar instalación',
            'db'     => $state['db'],
            'report' => $state['db_report'],
            'site'   => $state['site'],
            'admin'  => $state['admin'],
        ]);
    }

    public function run(): void
    {
        $this->requireStep(5);
        $this->verifyCsrf();
        $state = $this->state();

        try {
            $result = $this->installer->install([
                'db'    => $state['db'],
                'site'  => $state['site'],
                'admin' => $state['admin'],
            ]);
        } catch (InstallerException $e) {
            Session::flash('danger', $e->getMessage());
            redirect('/instalacion/confirmar');
        } catch (Throwable $e) {
            error_log('[Installer] ' . $e::class . ': ' . $e->getMessage() . ' en ' . $e->getFile() . ':' . $e->getLine());
            Session::flash('danger', 'Ocurrió un error inesperado durante la instalación: ' . $e->getMessage());
            redirect('/instalacion/confirmar');
        }

        Session::set(self::DONE, [
            'log'            => $result['log'],
            'config_written' => $result['config_written'],
            'config_content' => $result['config_written'] ? null : $result['config_content'],
            'base_url'       => $state['site']['base_url'],
            'ficha'          => $state['admin']['ficha'],
            'email'          => $state['admin']['email'],
            'app_name'       => $state['site']['name'],
        ]);
        Session::remove(self::STATE);
        Auth::login($result['admin_id']);
        redirect('/instalacion/finalizado');
    }

    // ------------------------------------------------------------------
    //  Finalizado (funciona también ya instalado)
    // ------------------------------------------------------------------

    public function finished(): void
    {
        $done = Session::get(self::DONE);
        if (!is_array($done)) {
            redirect($this->installer->isInstalled() ? '/' : '/instalacion');
        }

        $installed = $this->installer->isInstalled();
        if ($installed) {
            // Ya no se necesita: config.php existe (escrito o subido a mano).
            Session::remove(self::DONE);
        }

        $this->view('install/wizard/finalizado', [
            'title'     => 'Instalación completada',
            'done'      => $done,
            'installed' => $installed,
            'step'      => 6,
            'appName'   => (string) $done['app_name'],
            'authUser'  => null,
        ], 200, 'layouts/installer');
    }

    /** Descarga de config.php cuando el servidor no permitió escribirlo. */
    public function downloadConfig(): void
    {
        $this->verifyCsrf();
        $done = Session::get(self::DONE);
        if (!is_array($done) || !is_string($done['config_content'] ?? null) || $this->installer->isInstalled()) {
            throw new HttpException(404, 'No hay un archivo de configuración pendiente de descargar.');
        }
        header('Content-Type: application/x-httpd-php; charset=UTF-8');
        header('Content-Disposition: attachment; filename="config.php"');
        header('Cache-Control: no-store, private');
        header('X-Content-Type-Options: nosniff');
        echo $done['config_content'];
    }

    // ------------------------------------------------------------------
    //  Utilidades internas
    // ------------------------------------------------------------------

    /** @return array<string, mixed> */
    private function state(): array
    {
        $state = Session::get(self::STATE, []);
        return is_array($state) ? $state : [];
    }

    /** @param array<string, mixed> $changes */
    private function updateState(array $changes): void
    {
        Session::set(self::STATE, array_merge($this->state(), $changes));
    }

    /** Primer paso pendiente según lo capturado hasta ahora. */
    private function currentStep(): int
    {
        $s = $this->state();
        return match (true) {
            empty($s['key_ok'])        => 1,
            empty($s['db_report'])     => 2,
            empty($s['site'])          => 3,
            empty($s['admin'])         => 4,
            default                    => 5,
        };
    }

    private function requireStep(int $step): void
    {
        $this->guardNotInstalled();
        $current = $this->currentStep();
        if ($step > $current) {
            $paths = [1 => '/instalacion', 2 => '/instalacion/base-datos', 3 => '/instalacion/sitio', 4 => '/instalacion/administrador', 5 => '/instalacion/confirmar'];
            if ($current === 1) {
                Session::flash('warning', 'Primero captura la clave de instalación.');
            }
            redirect($paths[$current]);
        }
    }

    private function guardNotInstalled(): void
    {
        if ($this->installer->isInstalled()) {
            throw new HttpException(404, 'El sistema ya está instalado.');
        }
    }

    private function oldOr(string $key, string $fallback): string
    {
        $old = \App\Core\View::shared('old');
        return is_array($old) && isset($old[$key]) && is_string($old[$key]) ? $old[$key] : $fallback;
    }

    /** @param array<string, mixed> $data */
    private function render(string $view, int $step, array $data): void
    {
        $this->view('install/wizard/' . $view, array_merge($data, [
            'step'        => $step,
            'maxStep'     => $this->currentStep(),
            'authUser'    => null,
        ]), 200, 'layouts/installer');
    }
}
