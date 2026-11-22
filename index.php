<?php

declare(strict_types=1);

/**
 * Front Controller — Mesa de Ayuda TI / Control de Resguardos GECA
 *
 * Todas las peticiones amigables llegan aquí gracias al .htaccess.
 */

use App\Config\Database;
use App\Controllers\AuthController;
use App\Controllers\CuentaController;
use App\Controllers\DashboardController;
use App\Controllers\EquipoController;
use App\Controllers\GarantiaController;
use App\Controllers\GecaController;
use App\Controllers\InstallController;
use App\Controllers\InstallerController;
use App\Controllers\InventarioController;
use App\Controllers\RegistroController;
use App\Controllers\ReporteController;
use App\Controllers\TicketController;
use App\Controllers\TiController;
use App\Controllers\UsuarioController;
use App\Core\Autoloader;
use App\Core\Auth;
use App\Core\Csrf;
use App\Core\HttpException;
use App\Core\Router;
use App\Core\Session;
use App\Core\View;

// Solo desarrollo local (php -S localhost:8080 index.php): sirve /assets tal cual.
if (PHP_SAPI === 'cli-server') {
    $staticPath = (string) parse_url((string) ($_SERVER['REQUEST_URI'] ?? '/'), PHP_URL_PATH);
    if (str_starts_with($staticPath, '/assets/') && is_file(__DIR__ . $staticPath)) {
        return false;
    }
}

define('ROOT_PATH', __DIR__);
define('STORAGE_PATH', ROOT_PATH . '/storage');

// --- Versión mínima de PHP ---------------------------------------------------
if (PHP_VERSION_ID < 80300) {
    http_response_code(500);
    header('Content-Type: text/plain; charset=UTF-8');
    echo 'Se requiere PHP 8.3 o superior. Selecciónalo en cPanel > "MultiPHP Manager".';
    exit;
}

// --- Configuración ------------------------------------------------------------
// Sin config/config.php el sistema entra en MODO INSTALACIÓN: solo responde el
// asistente /instalacion y se usan los valores por defecto de config.example.php.
$configFile = ROOT_PATH . '/config/config.php';
$installMode = !is_file($configFile);
/** @var array<string, mixed> $config */
$config = require ($installMode ? ROOT_PATH . '/config/config.example.php' : $configFile);
if ($installMode) {
    $config['app']['env'] = 'production';
    $config['session']['secure'] = null;
}
$GLOBALS['__app_config'] = $config;
$GLOBALS['__install_mode'] = $installMode;

$isProduction = ($config['app']['env'] ?? 'production') === 'production';
$timezone = (string) ($config['app']['timezone'] ?? 'America/Mexico_City');

date_default_timezone_set($timezone);
mb_internal_encoding('UTF-8');

// --- Errores: nunca al navegador en producción --------------------------------
error_reporting(E_ALL);
ini_set('display_errors', $isProduction ? '0' : '1');
ini_set('log_errors', '1');
if (is_dir(STORAGE_PATH . '/logs') && is_writable(STORAGE_PATH . '/logs')) {
    ini_set('error_log', STORAGE_PATH . '/logs/php-error.log');
}
header_remove('X-Powered-By');

// --- Autoload sin Composer ----------------------------------------------------
require ROOT_PATH . '/src/Core/Autoloader.php';
Autoloader::register('App', ROOT_PATH . '/src');
require ROOT_PATH . '/src/Core/helpers.php';

// --- Servicios base -----------------------------------------------------------
if (!$installMode) {
    Database::configure((array) ($config['db'] ?? []), $timezone);
}
Session::start((array) ($config['session'] ?? []), STORAGE_PATH . '/sessions');
View::setPath(ROOT_PATH . '/src/Views');

// --- Rutas --------------------------------------------------------------------
$router = new Router();

// Asistente de instalación (se auto-bloquea en cuanto existe config/config.php).
$router->get('/instalacion', [InstallerController::class, 'requirements']);
$router->post('/instalacion/clave', [InstallerController::class, 'verifyKey']);
$router->get('/instalacion/base-datos', [InstallerController::class, 'database']);
$router->post('/instalacion/base-datos', [InstallerController::class, 'saveDatabase']);
$router->get('/instalacion/sitio', [InstallerController::class, 'site']);
$router->post('/instalacion/sitio', [InstallerController::class, 'saveSite']);
$router->get('/instalacion/administrador', [InstallerController::class, 'admin']);
$router->post('/instalacion/administrador', [InstallerController::class, 'saveAdmin']);
$router->get('/instalacion/confirmar', [InstallerController::class, 'confirm']);
$router->post('/instalacion/ejecutar', [InstallerController::class, 'run']);
$router->get('/instalacion/finalizado', [InstallerController::class, 'finished']);
$router->post('/instalacion/config', [InstallerController::class, 'downloadConfig']);

if (!$installMode) {
    $router->get('/', [DashboardController::class, 'index']);

    $router->get('/login', [AuthController::class, 'showLogin']);
    $router->post('/login', [AuthController::class, 'login']);
    $router->post('/logout', [AuthController::class, 'logout']);

    // Alta del primer admin cuando config.php se creó a mano (sin asistente).
    $router->get('/instalar', [InstallController::class, 'show']);
    $router->post('/instalar', [InstallController::class, 'store']);

    // Auto-registro por ficha.
    $router->get('/registro', [RegistroController::class, 'showFicha']);
    $router->post('/registro', [RegistroController::class, 'lookupFicha']);
    $router->get('/registro/completar', [RegistroController::class, 'showCompletar']);
    $router->post('/registro/completar', [RegistroController::class, 'completar']);

    // Portal del trabajador.
    $router->get('/tickets', [TicketController::class, 'index']);
    $router->get('/tickets/nuevo', [TicketController::class, 'create']);
    $router->post('/tickets', [TicketController::class, 'store']);
    $router->get('/tickets/{id}', [TicketController::class, 'show']);
    $router->post('/tickets/{id}/comentarios', [TicketController::class, 'comment']);
    $router->post('/equipos/{id}/principal', [EquipoController::class, 'setPrincipal']);

    // Bandeja de TI.
    $router->get('/ti/tickets', [TiController::class, 'inbox']);
    $router->post('/tickets/{id}/gestion', [TiController::class, 'manage']);
    $router->post('/tickets/{id}/tomar', [TiController::class, 'take']);

    // Mi cuenta.
    $router->get('/cuenta', [CuentaController::class, 'show']);
    $router->post('/cuenta', [CuentaController::class, 'update']);
    $router->post('/cuenta/password', [CuentaController::class, 'password']);

    // Inventario y garantías (TI).
    $router->get('/ti/equipos', [InventarioController::class, 'index']);
    $router->get('/ti/equipos/{id}', [InventarioController::class, 'show']);
    $router->get('/ti/garantias', [GarantiaController::class, 'index']);
    $router->get('/ti/garantias/nueva', [GarantiaController::class, 'create']);
    $router->post('/ti/garantias', [GarantiaController::class, 'store']);
    $router->get('/ti/garantias/{id}/editar', [GarantiaController::class, 'edit']);
    $router->post('/ti/garantias/{id}', [GarantiaController::class, 'update']);
    $router->post('/ti/garantias/{id}/eliminar', [GarantiaController::class, 'destroy']);

    // Reportes y tablero de agentes (TI).
    $router->get('/ti/reportes', [ReporteController::class, 'index']);
    $router->get('/ti/reportes/exportar', [ReporteController::class, 'export']);

    // Administración de usuarios (admin_ti).
    $router->get('/ti/usuarios', [UsuarioController::class, 'index']);
    $router->post('/ti/usuarios/correo-prueba', [UsuarioController::class, 'testMail']);
    $router->post('/ti/usuarios/{id}', [UsuarioController::class, 'update']);
    $router->post('/ti/usuarios/{id}/restablecer', [UsuarioController::class, 'resetPassword']);

    $router->get('/geca', [GecaController::class, 'index']);
    $router->post('/geca/cargar', [GecaController::class, 'upload']);
    $router->get('/geca/cargas/{id}', [GecaController::class, 'show']);
    $router->get('/geca/plantilla', [GecaController::class, 'template']);
}

// --- Despacho con manejo centralizado de errores ------------------------------
try {
    View::share('appName', (string) ($config['app']['name'] ?? 'Mesa de Ayuda TI'));
    View::share('flash', Session::pullFlash());
    $old = Session::get('_old', []);
    Session::remove('_old');
    View::share('old', is_array($old) ? $old : []);
    View::share('csrfToken', Csrf::token());

    // En modo instalación cualquier otra ruta lleva al asistente.
    $currentPath = Router::currentPath((string) ($_SERVER['REQUEST_URI'] ?? '/'));
    if ($installMode && !str_starts_with($currentPath, '/instalacion')) {
        redirect('/instalacion');
    }

    $router->dispatch((string) ($_SERVER['REQUEST_METHOD'] ?? 'GET'), (string) ($_SERVER['REQUEST_URI'] ?? '/'));
} catch (HttpException $e) {
    renderError($e->status(), $e->getMessage());
} catch (Throwable $e) {
    error_log(sprintf('[%s] %s en %s:%d', $e::class, $e->getMessage(), $e->getFile(), $e->getLine()));
    renderError(500, $isProduction ? 'Ocurrió un error inesperado. Intenta de nuevo más tarde.' : $e->getMessage());
}

function renderError(int $status, string $message): void
{
    if (ob_get_level() > 0) {
        ob_clean();
    }
    http_response_code($status);
    header('Content-Type: text/html; charset=UTF-8');

    $titles = [
        400 => 'Solicitud inválida',
        403 => 'Acceso denegado',
        404 => 'Página no encontrada',
        405 => 'Método no permitido',
        500 => 'Error del servidor',
    ];

    try {
        // Evita consultar la BD si el error fue de conexión o no hay instalación.
        $user = ($status === 500 || !empty($GLOBALS['__install_mode'])) ? null : Auth::user();
    } catch (Throwable) {
        $user = null;
    }

    try {
        echo View::render('errors/error', [
            'status'  => $status,
            'title'   => $titles[$status] ?? 'Error',
            'message' => $message,
            'authUser' => $user,
        ]);
    } catch (Throwable) {
        echo '<h1>' . $status . '</h1><p>' . htmlspecialchars($message, ENT_QUOTES, 'UTF-8') . '</p>';
    }
}
