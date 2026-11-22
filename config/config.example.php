<?php

declare(strict_types=1);

/**
 * Configuración de la aplicación.
 *
 * 1. Copia este archivo como  config/config.php  (desde el Administrador de
 *    Archivos de cPanel: clic derecho > Copy, o súbelo ya renombrado).
 * 2. Llena las credenciales de la base de datos creada con el asistente
 *    "MySQL® Databases" de cPanel. Recuerda que cPanel antepone tu usuario:
 *    base  -> usuario_mesaayuda
 *    user  -> usuario_tickets
 * 3. config/config.php NUNCA se versiona (.gitignore) y está bloqueado por web.
 */
return [
    'app' => [
        'name'     => 'Mesa de Ayuda TI',
        // URL pública SIN diagonal final. Ej: https://tickets.tudominio.com
        // o https://tudominio.com/tickets si vive en una subcarpeta.
        'base_url' => 'https://tickets.tudominio.com',
        // 'production' oculta errores al navegador y los manda a storage/logs.
        'env'      => 'production',
        'timezone' => 'America/Mexico_City',
        // Permite /instalar para crear el primer admin_ti. Se bloquea solo en
        // cuanto exista un admin_ti, pero conviene ponerlo en false después.
        'install_enabled' => true,
    ],

    'db' => [
        'host'     => 'localhost',          // En cPanel casi siempre es localhost
        'port'     => 3306,
        'database' => 'usuario_mesaayuda',  // Con prefijo de cPanel
        'username' => 'usuario_tickets',    // Con prefijo de cPanel
        'password' => 'CAMBIA_ESTA_CONTRASENA',
        'charset'  => 'utf8mb4',
        'collation' => 'utf8mb4_unicode_ci',
    ],

    'session' => [
        'name'     => 'MESAAYUDA_SID',
        'lifetime' => 7200,   // segundos de inactividad permitidos (2 h)
        // true = guarda sesiones en storage/sessions (aisladas de otros sitios
        // del hosting compartido). Pon false si tu hosting no lo permite.
        'use_local_storage' => true,
        // null = autodetecta HTTPS. Fuerza true cuando tengas SSL.
        'secure'   => null,
        'samesite' => 'Lax',
    ],

    'geca' => [
        // Tamaño máximo del archivo semanal (no puede superar upload_max_filesize).
        'max_upload_mb' => 30,
        // Segundos máximos de ejecución al sincronizar (si el hosting lo permite).
        'max_execution_time' => 300,
    ],

    'security' => [
        // Intentos de login fallidos antes de bloquear temporalmente.
        'login_max_attempts' => 5,
        'login_lock_seconds' => 300,
    ],
];
