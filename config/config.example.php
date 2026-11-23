<?php

declare(strict_types=1);

/**
 * Configuración de la aplicación.
 *
 * Lo normal es NO editar este archivo: el asistente web (/instalacion) genera
 * config/config.php automáticamente. Úsalo solo para instalación manual.
 * Mientras no exista config/config.php, estos valores sirven como
 * predeterminados del modo instalación.
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

    'mail' => [
        // 'off' = sin correos, 'mail' = función mail() del hosting (funciona en
        // cPanel sin configurar nada), 'smtp' = cuenta de correo de cPanel
        // (recomendado: menos probabilidad de caer en spam).
        'driver'     => 'off',
        'from_email' => 'no-responder@tudominio.com',
        'from_name'  => 'Mesa de Ayuda TI',
        // Mesa de ayuda institucional: recibe cada ticket nuevo (para Remedy).
        'mesa_institucional' => 'mesadeayuda@pemex.com',
        // Correos adicionales que reciben cada ticket nuevo. Además se avisa
        // siempre a los supervisores de la instalación del ticket.
        'notify_new_tickets_to' => [],
        // Solo para driver 'smtp'. En cPanel: Email Accounts > Connect Devices.
        'smtp' => [
            'host'       => 'mail.tudominio.com',
            'port'       => 465,          // 465 = SSL, 587 = STARTTLS
            'encryption' => 'ssl',        // 'ssl', 'tls' o '' (sin cifrado)
            'username'   => 'no-responder@tudominio.com',
            'password'   => '',
            'timeout'    => 10,
        ],
    ],

    'security' => [
        // Intentos de login fallidos antes de bloquear temporalmente.
        'login_max_attempts' => 5,
        'login_lock_seconds' => 300,
    ],
];
