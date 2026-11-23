<?php

declare(strict_types=1);

/**
 * Tarea diaria (recordatorios de contraseña de admin local y de tareas).
 *
 * cPanel › Cron Jobs › Common Settings: "Once Per Day", comando:
 *   /usr/local/bin/php /home/USUARIO/public_html/bin/cron.php
 * (ajusta la ruta a donde subiste el sistema). Solo se ejecuta por consola;
 * la carpeta bin/ está bloqueada para acceso web.
 */

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit('Solo por consola.');
}

define('ROOT_PATH', dirname(__DIR__));
define('STORAGE_PATH', ROOT_PATH . '/storage');

$configFile = ROOT_PATH . '/config/config.php';
if (!is_file($configFile)) {
    fwrite(STDERR, "Falta config/config.php: el sistema no está instalado.\n");
    exit(1);
}
$config = require $configFile;
$GLOBALS['__app_config'] = $config;
date_default_timezone_set((string) ($config['app']['timezone'] ?? 'America/Mexico_City'));
mb_internal_encoding('UTF-8');
if (is_dir(STORAGE_PATH . '/logs') && is_writable(STORAGE_PATH . '/logs')) {
    ini_set('error_log', STORAGE_PATH . '/logs/php-error.log');
}

require ROOT_PATH . '/src/Core/Autoloader.php';
App\Core\Autoloader::register('App', ROOT_PATH . '/src');
require ROOT_PATH . '/src/Core/helpers.php';

App\Config\Database::configure((array) $config['db'], (string) ($config['app']['timezone'] ?? 'America/Mexico_City'));

try {
    $resultado = (new App\Services\Recordatorios(App\Config\Database::getInstance()))->ejecutar(date('Y-m-d'));
    $cola = count(App\Services\Mail\Notifier::cola());
    echo date('Y-m-d H:i') . " · recordatorios: {$resultado['enviados']} · correos en cola: {$cola}\n";
} catch (Throwable $e) {
    error_log('[Cron] ' . $e->getMessage());
    fwrite(STDERR, 'Error: ' . $e->getMessage() . "\n");
    exit(1);
}
// Los correos encolados se envían al terminar (Notifier::flush en shutdown).
