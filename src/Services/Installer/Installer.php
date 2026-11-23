<?php

declare(strict_types=1);

namespace App\Services\Installer;

use DateTimeImmutable;
use DateTimeZone;
use PDO;
use PDOException;
use Throwable;

/**
 * Instalador para hosting compartido cPanel (NEUBOX).
 *
 * Responsabilidades:
 *  - Verificar requisitos del servidor (PHP, extensiones, permisos, límites,
 *    mod_rewrite, HTTPS y que las carpetas internas no sean públicas).
 *  - Probar la conexión y los privilegios reales de la base de datos.
 *  - Importar database/schema.sql (revirtiendo si algo falla a medias).
 *  - Crear o promover la cuenta admin_ti.
 *  - Escribir config/config.php de forma atómica.
 *  - Bloquear el instalador al terminar.
 *
 * Nunca borra tablas ni datos que no haya creado él mismo en esta ejecución.
 */
final class Installer
{
    /** Tablas que crea database/schema.sql. */
    public const TABLES = [
        'ubicaciones', 'cargas_geca', 'usuarios', 'equipos', 'garantias',
        'departamentos', 'estados', 'tickets', 'comentarios',
        // Fase 5
        'instalaciones', 'ubicacion_tipos', 'niveles', 'ubicaciones_fisicas', 'ubicacion_fisica_niveles',
        'ubicacion_fisica_alas', 'geca_inmueble_instalacion', 'supervisor_instalaciones', 'agente_instalaciones',
        'avisos', 'aviso_instalaciones', 'tareas', 'tarea_asignaciones', 'tarea_archivos',
        'recordatorios_enviados', 'sistema_estado', 'verificaciones_email',
    ];

    public const TIMEZONES = [
        'America/Mexico_City' => 'Centro (Ciudad de México, Guadalajara, Monterrey)',
        'America/Merida'      => 'Centro (Mérida)',
        'America/Cancun'      => 'Sureste (Cancún, Quintana Roo)',
        'America/Chihuahua'   => 'Chihuahua',
        'America/Mazatlan'    => 'Pacífico (Sinaloa, Nayarit, BCS)',
        'America/Hermosillo'  => 'Sonora',
        'America/Tijuana'     => 'Noroeste (Baja California)',
        'America/Matamoros'   => 'Frontera noreste (Matamoros, Reynosa)',
    ];

    private const KEY_FILE = '/storage/install.key';
    private const LOCK_FILE = '/storage/installed.lock';
    private const CONFIG_FILE = '/config/config.php';
    private const SCHEMA_FILE = '/database/schema.sql';
    private const KEY_ALPHABET = 'ABCDEFGHJKLMNPQRSTUVWXYZ23456789';

    public function __construct(private readonly string $root)
    {
    }

    // ==================================================================
    //  Estado y clave de instalación
    // ==================================================================

    public function isInstalled(): bool
    {
        return is_file($this->root . self::CONFIG_FILE);
    }

    /**
     * Genera (una sola vez) la clave de instalación en storage/install.key.
     * Solo quien tiene acceso al Administrador de archivos de cPanel puede
     * leerla, lo que impide que un tercero complete la instalación primero.
     */
    public function ensureInstallKey(): bool
    {
        $file = $this->root . self::KEY_FILE;
        if (is_file($file) && $this->readKey() !== null) {
            return true;
        }
        if (!is_dir(dirname($file)) || !is_writable(dirname($file))) {
            return false;
        }

        $chars = '';
        $max = strlen(self::KEY_ALPHABET) - 1;
        for ($i = 0; $i < 12; $i++) {
            $chars .= self::KEY_ALPHABET[random_int(0, $max)];
        }
        $key = implode('-', str_split($chars, 4));

        $content = "Clave de instalación de Mesa de Ayuda TI\n"
            . "Cópiala en el asistente de instalación. Este archivo se borra solo al terminar.\n\n"
            . $key . "\n";

        return @file_put_contents($file, $content, LOCK_EX) !== false;
    }

    public function verifyInstallKey(string $input): bool
    {
        $stored = $this->readKey();
        if ($stored === null) {
            return false;
        }
        $normalized = strtoupper((string) preg_replace('/[^A-Za-z0-9]/', '', $input));
        return hash_equals(str_replace('-', '', $stored), $normalized);
    }

    private function readKey(): ?string
    {
        $content = @file_get_contents($this->root . self::KEY_FILE);
        if ($content === false) {
            return null;
        }
        if (preg_match('/\b([A-Z0-9]{4}-[A-Z0-9]{4}-[A-Z0-9]{4})\b/', $content, $m) === 1) {
            return $m[1];
        }
        return null;
    }

    /**
     * Usuario de cPanel deducido de la ruta (/home/usuario/public_html...).
     * Sirve para sugerir el prefijo "usuario_" de la base de datos.
     */
    public function cpanelUser(): ?string
    {
        $path = str_replace('\\', '/', $this->root);
        if (preg_match('#^/home\d*/([a-z0-9_]{1,16})/#i', $path, $m) === 1) {
            return $m[1];
        }
        return null;
    }

    // ==================================================================
    //  Paso 1 — Requisitos
    // ==================================================================

    /**
     * @return array{items: list<array{group: string, label: string, status: string, detail: string}>, canContinue: bool}
     */
    public function requirements(string $baseUrl): array
    {
        $items = [];
        $add = static function (string $group, string $label, string $status, string $detail) use (&$items): void {
            $items[] = ['group' => $group, 'label' => $label, 'status' => $status, 'detail' => $detail];
        };

        // --- PHP ---
        $add(
            'PHP',
            'Versión de PHP ≥ 8.3',
            PHP_VERSION_ID >= 80300 ? 'ok' : 'fail',
            'Detectada: ' . PHP_VERSION . (PHP_VERSION_ID >= 80300 ? '' : '. Cámbiala en cPanel > MultiPHP Manager.')
        );
        foreach (['pdo_mysql' => 'Conexión a MySQL/MariaDB', 'mbstring' => 'Textos con acentos (UTF-8)', 'session' => 'Sesiones', 'json' => 'JSON'] as $ext => $why) {
            $loaded = extension_loaded($ext);
            $add('PHP', "Extensión {$ext}", $loaded ? 'ok' : 'fail', $loaded ? $why : "Falta. Actívala en cPanel > Select PHP Version > Extensions ({$why}).");
        }
        $xlsxExt = ['zip', 'xmlreader', 'dom', 'ctype'];
        $missing = array_values(array_filter($xlsxExt, static fn (string $e): bool => !extension_loaded($e)));
        $add(
            'PHP',
            'Lectura de Excel .xlsx (zip, xmlreader, dom, ctype)',
            $missing === [] ? 'ok' : 'warn',
            $missing === [] ? 'Se podrán cargar archivos .xlsx y .csv.' : 'Faltan: ' . implode(', ', $missing) . '. Solo se aceptará CSV hasta activarlas.'
        );
        $uploads = filter_var(ini_get('file_uploads'), FILTER_VALIDATE_BOOLEAN);
        $add('PHP', 'Subida de archivos (file_uploads)', $uploads ? 'ok' : 'fail', $uploads ? 'Habilitada.' : 'Deshabilitada; actívala en MultiPHP INI Editor.');

        // --- Límites ---
        $upload = self::iniMegabytes('upload_max_filesize');
        $post = self::iniMegabytes('post_max_size');
        $effective = min($upload ?: PHP_INT_MAX, $post ?: PHP_INT_MAX);
        $add(
            'Límites',
            'Tamaño máximo de archivo',
            $effective >= 10 ? 'ok' : 'warn',
            "upload_max_filesize = {$upload} MB, post_max_size = {$post} MB"
                . ($effective >= 10 ? '' : '. Recomendado ≥ 10 MB para el GECA (ajústalo en .user.ini o MultiPHP INI Editor).')
        );
        $memory = self::iniMegabytes('memory_limit');
        $add(
            'Límites',
            'memory_limit',
            ($memory === 0 || $memory >= 128) ? 'ok' : 'warn',
            ($memory === 0 ? 'Sin límite' : "{$memory} MB") . (($memory === 0 || $memory >= 128) ? '' : '. Recomendado ≥ 128 MB.')
        );
        $time = (int) ini_get('max_execution_time');
        $add(
            'Límites',
            'max_execution_time',
            ($time === 0 || $time >= 120) ? 'ok' : 'warn',
            ($time === 0 ? 'Sin límite' : "{$time} s") . (($time === 0 || $time >= 120) ? '' : '. Recomendado ≥ 120 s para cargas grandes.')
        );

        // --- Permisos ---
        $paths = [
            'config'           => ['warn', 'Si no es escribible podrás descargar config.php y subirlo a mano.'],
            'storage'          => ['fail', 'Necesaria para la clave de instalación y el bloqueo final.'],
            'storage/uploads'  => ['fail', 'Necesaria para procesar los archivos GECA.'],
            'storage/logs'     => ['warn', 'Sin ella los errores irán al log general del hosting.'],
            'storage/sessions' => ['warn', 'Sin ella las sesiones usarán la carpeta compartida del hosting.'],
        ];
        foreach ($paths as $rel => [$severity, $why]) {
            $full = $this->root . '/' . $rel;
            $ok = is_dir($full) && is_writable($full);
            $add('Permisos', "/{$rel} escribible", $ok ? 'ok' : $severity, $ok ? 'Correcto.' : "Asigna permisos 755. {$why}");
        }

        // --- Servidor web ---
        $rewrite = getenv('MOD_REWRITE') === 'On' || getenv('REDIRECT_MOD_REWRITE') === 'On'
            || ($_SERVER['MOD_REWRITE'] ?? $_SERVER['REDIRECT_MOD_REWRITE'] ?? '') === 'On';
        $add(
            'Servidor web',
            'URLs amigables (mod_rewrite + .htaccess)',
            $rewrite ? 'ok' : 'warn',
            $rewrite ? 'Activo.' : 'No detectado. Verifica que el archivo .htaccess se haya subido (es un archivo oculto).'
        );
        $https = self::isHttps();
        $add(
            'Servidor web',
            'Conexión segura HTTPS',
            $https ? 'ok' : 'warn',
            $https ? 'Activa.' : 'Estás instalando por HTTP. Activa AutoSSL en cPanel > SSL/TLS Status y vuelve a entrar con https://.'
        );

        foreach ($this->probeProtectedPaths($baseUrl) as $probe) {
            $items[] = $probe;
        }

        $canContinue = true;
        foreach ($items as $item) {
            if ($item['status'] === 'fail') {
                $canContinue = false;
                break;
            }
        }

        return ['items' => $items, 'canContinue' => $canContinue];
    }

    /**
     * Pide por HTTP al propio sitio archivos internos para confirmar que el
     * .htaccess los bloquea. Si el hosting no permite peticiones a sí mismo,
     * se informa como "no verificable" sin bloquear la instalación.
     *
     * @return list<array{group: string, label: string, status: string, detail: string}>
     */
    private function probeProtectedPaths(string $baseUrl): array
    {
        $targets = [
            '/config/config.example.php' => 'Carpeta /config protegida',
            '/database/schema.sql'       => 'Carpeta /database protegida',
            '/storage/install.key'       => 'Carpeta /storage protegida',
        ];

        $results = [];
        foreach ($targets as $path => $label) {
            $code = self::httpStatus(rtrim($baseUrl, '/') . $path);
            if ($code === null) {
                $results[] = ['group' => 'Seguridad', 'label' => $label, 'status' => 'warn', 'detail' => 'No verificable (el hosting no permite que el sitio se consulte a sí mismo). Compruébalo abriendo ' . $path . ' en el navegador: debe dar 403.'];
            } elseif ($code === 200) {
                $results[] = ['group' => 'Seguridad', 'label' => $label, 'status' => 'fail', 'detail' => "{$path} es accesible públicamente (HTTP 200). Sube el archivo .htaccess de la raíz y el de cada carpeta interna."];
            } else {
                $results[] = ['group' => 'Seguridad', 'label' => $label, 'status' => 'ok', 'detail' => "Bloqueada (HTTP {$code})."];
            }
        }
        return $results;
    }

    private static function httpStatus(string $url): ?int
    {
        if (!preg_match('#^https?://#i', $url)) {
            return null;
        }

        if (function_exists('curl_init')) {
            $ch = curl_init($url);
            if ($ch === false) {
                return null;
            }
            curl_setopt_array($ch, [
                CURLOPT_NOBODY         => true,
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_FOLLOWLOCATION => false,
                CURLOPT_CONNECTTIMEOUT => 3,
                CURLOPT_TIMEOUT        => 5,
                CURLOPT_USERAGENT      => 'MesaAyudaTI-Installer',
            ]);
            $ok = curl_exec($ch);
            $code = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
            curl_close($ch);
            return ($ok === false || $code === 0) ? null : $code;
        }

        if (!filter_var(ini_get('allow_url_fopen'), FILTER_VALIDATE_BOOLEAN)) {
            return null;
        }
        $ctx = stream_context_create(['http' => [
            'method' => 'HEAD', 'timeout' => 5, 'ignore_errors' => true, 'follow_location' => 0,
        ]]);
        $headers = @get_headers($url, false, $ctx);
        if (!is_array($headers) || !isset($headers[0])) {
            return null;
        }
        return preg_match('#\s(\d{3})\s?#', (string) $headers[0], $m) === 1 ? (int) $m[1] : null;
    }

    // ==================================================================
    //  Paso 2 — Base de datos
    // ==================================================================

    /**
     * @param array{host: string, port: int, database: string, username: string, password: string} $db
     */
    public static function connect(array $db): PDO
    {
        $dsn = sprintf('mysql:host=%s;port=%d;dbname=%s;charset=utf8mb4', $db['host'], $db['port'], $db['database']);
        try {
            $pdo = new PDO($dsn, $db['username'], $db['password'], [
                PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                PDO::ATTR_EMULATE_PREPARES   => false,
                PDO::ATTR_TIMEOUT            => 5,
            ]);
        } catch (PDOException $e) {
            throw new InstallerException(self::friendlyConnectionError($e, $db), 0, $e);
        }
        $pdo->exec('SET NAMES utf8mb4 COLLATE utf8mb4_unicode_ci');
        return $pdo;
    }

    /**
     * Prueba conexión, versión, motor InnoDB, privilegios reales (crea y
     * borra una tabla temporal propia) y el estado de las tablas del sistema.
     *
     * @param array{host: string, port: int, database: string, username: string, password: string} $db
     * @return array{server: string, version: string, charset: string, mode: string, existing: list<string>, missing: list<string>}
     */
    public function inspectDatabase(array $db): array
    {
        $pdo = self::connect($db);

        $version = (string) $pdo->query('SELECT VERSION()')->fetchColumn();
        $isMaria = stripos($version, 'mariadb') !== false;
        preg_match('/^(\d+)\.(\d+)\.(\d+)/', $version, $m);
        $numeric = isset($m[3]) ? ((int) $m[1] * 10000 + (int) $m[2] * 100 + (int) $m[3]) : 0;
        $minimum = $isMaria ? 100202 : 50707;
        if ($numeric > 0 && $numeric < $minimum) {
            throw new InstallerException(sprintf(
                'El servidor usa %s %s y se requiere %s. Solicita a soporte de NEUBOX una versión más reciente.',
                $isMaria ? 'MariaDB' : 'MySQL',
                $version,
                $isMaria ? 'MariaDB 10.2.2 o superior' : 'MySQL 5.7.7 o superior'
            ));
        }

        $innodb = (string) $pdo->query("SELECT SUPPORT FROM information_schema.ENGINES WHERE ENGINE = 'InnoDB'")->fetchColumn();
        if (!in_array(strtoupper($innodb), ['YES', 'DEFAULT'], true)) {
            throw new InstallerException('El servidor de base de datos no tiene habilitado el motor InnoDB, necesario para las llaves foráneas.');
        }

        // Privilegios: crear, indexar, alterar y borrar una tabla propia.
        $probe = '__mesaayuda_install_probe';
        try {
            $pdo->exec("DROP TABLE IF EXISTS `{$probe}`");
            $pdo->exec("CREATE TABLE `{$probe}` (id INT UNSIGNED NOT NULL PRIMARY KEY, nombre VARCHAR(10) NULL) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
            $pdo->exec("ALTER TABLE `{$probe}` ADD INDEX idx_probe (nombre)");
            $pdo->exec("INSERT INTO `{$probe}` (id, nombre) VALUES (1, 'ok')");
            $pdo->exec("DROP TABLE `{$probe}`");
        } catch (PDOException $e) {
            try {
                $pdo->exec("DROP TABLE IF EXISTS `{$probe}`");
            } catch (Throwable) {
            }
            throw new InstallerException(
                'El usuario se conecta pero no tiene privilegios suficientes (CREATE, ALTER, INDEX, INSERT, DROP, REFERENCES). '
                . 'En cPanel > MySQL® Databases > "Add User To Database" marca ALL PRIVILEGES. Detalle: ' . $e->getMessage(),
                0,
                $e
            );
        }

        $existing = $this->existingTables($pdo);
        $missing = array_values(array_diff(self::TABLES, $existing));
        $mode = match (true) {
            $existing === []  => 'fresh',
            $missing === []   => 'reuse',
            default           => 'partial',
        };

        $charset = (string) $pdo->query('SELECT @@character_set_database')->fetchColumn();

        return [
            'server'   => $isMaria ? 'MariaDB' : 'MySQL',
            'version'  => $version,
            'charset'  => $charset,
            'mode'     => $mode,
            'existing' => $existing,
            'missing'  => $missing,
        ];
    }

    /** @return list<string> */
    private function existingTables(PDO $pdo): array
    {
        $placeholders = implode(',', array_fill(0, count(self::TABLES), '?'));
        $stmt = $pdo->prepare(
            "SELECT TABLE_NAME FROM information_schema.TABLES
              WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME IN ({$placeholders})"
        );
        $stmt->execute(self::TABLES);
        $found = array_map('strval', $stmt->fetchAll(PDO::FETCH_COLUMN));
        // Conserva el orden canónico.
        return array_values(array_filter(self::TABLES, static fn (string $t): bool => in_array($t, $found, true)));
    }

    /**
     * @param array<string, mixed> $db
     */
    private static function friendlyConnectionError(PDOException $e, array $db): string
    {
        $code = (int) ($e->errorInfo[1] ?? 0);
        if ($code === 0 && preg_match('/\[(\d{4})\]/', $e->getMessage(), $m) === 1) {
            $code = (int) $m[1];
        }
        if ($code === 0 && preg_match('/SQLSTATE\[HY000\] \[(\d+)\]/', $e->getMessage(), $m) === 1) {
            $code = (int) $m[1];
        }

        return match ($code) {
            1045 => 'Usuario o contraseña de MySQL incorrectos para "' . self::plain((string) ($db['username'] ?? '')) . '". '
                . 'En cPanel el usuario lleva el prefijo de tu cuenta (ej. micuenta_tickets) y la contraseña distingue mayúsculas.',
            1044 => 'El usuario existe pero no está asignado a la base "' . self::plain((string) ($db['database'] ?? '')) . '". En cPanel > MySQL® Databases usa "Add User To Database".',
            1049 => 'La base de datos "' . self::plain((string) ($db['database'] ?? '')) . '" no existe. Créala en cPanel > MySQL® Databases (lleva prefijo, ej. usuario_mesaayuda).',
            2002, 2003, 2005 => 'No se pudo contactar al servidor MySQL en "' . self::plain((string) ($db['host'] ?? '')) . '". En NEUBOX normalmente es "localhost".',
            2006, 2013 => 'El servidor MySQL cerró la conexión. Intenta de nuevo en unos minutos.',
            default => 'No fue posible conectar a la base de datos: ' . $e->getMessage(),
        };
    }

    // ==================================================================
    //  Paso final — Instalación
    // ==================================================================

    /**
     * Ejecuta la instalación completa.
     *
     * @param array{
     *   db: array{host: string, port: int, database: string, username: string, password: string},
     *   site: array{name: string, base_url: string, timezone: string, force_https: bool},
     *   admin: array{ficha: string, nombre: string, email: string, password_hash: string}
     * } $state
     *
     * @return array{log: list<array{status: string, message: string}>, admin_id: int, config_written: bool, config_content: string}
     */
    public function install(array $state): array
    {
        $log = [];
        $ok = static function (string $msg) use (&$log): void {
            $log[] = ['status' => 'ok', 'message' => $msg];
        };
        $warn = static function (string $msg) use (&$log): void {
            $log[] = ['status' => 'warn', 'message' => $msg];
        };

        if ($this->isInstalled()) {
            throw new InstallerException('El sistema ya está instalado (existe config/config.php).');
        }
        if (function_exists('set_time_limit')) {
            @set_time_limit(120);
        }
        ignore_user_abort(true);

        // 1) Conexión y estado de tablas (se re-verifica por si cambió).
        $report = $this->inspectDatabase($state['db']);
        $pdo = self::connect($state['db']);
        $ok("Conexión a {$report['server']} {$report['version']} verificada.");

        // 2) Esquema.
        if ($report['mode'] === 'partial') {
            throw new InstallerException(
                'La base tiene solo algunas tablas del sistema (' . implode(', ', $report['existing']) . '). '
                . 'Si es una instalación anterior, aplica primero en phpMyAdmin los scripts de database/migraciones/. '
                . 'Por seguridad el instalador no borra nada.'
            );
        }
        if ($report['mode'] === 'fresh') {
            $count = $this->importSchema($pdo);
            $ok("Esquema importado: {$count} tablas creadas y catálogos iniciales cargados.");
        } else {
            $ok('Se detectó una instalación previa completa: se conservaron todas las tablas y datos existentes.');
            $this->ensureSeedData($pdo);
        }

        // 3) Administrador.
        $adminId = $this->createAdmin($pdo, $state['admin']);
        $ok("Cuenta Admin TI lista (ficha {$state['admin']['ficha']}).");

        // 4) HTTPS forzado (opcional, solo si se instala por HTTPS).
        if ($state['site']['force_https']) {
            if ($this->enableHttpsRedirect()) {
                $ok('Redirección automática a HTTPS activada en .htaccess.');
            } else {
                $warn('No se pudo activar la redirección a HTTPS (.htaccess sin permisos de escritura). Descomenta el bloque "Forzar HTTPS" a mano.');
            }
        }

        // 5) config.php (último paso: su existencia marca el sistema como instalado).
        $content = $this->buildConfig($state);
        $written = $this->writeConfig($content);
        if ($written) {
            $ok('Archivo config/config.php creado con permisos restringidos.');
        } else {
            $warn('No se pudo escribir config/config.php. Descárgalo en la siguiente pantalla y súbelo a la carpeta /config.');
        }

        // 6) Bloqueo del instalador.
        $this->lock($state['db']['database']);
        $ok('Instalador bloqueado y clave de instalación eliminada.');

        return ['log' => $log, 'admin_id' => $adminId, 'config_written' => $written, 'config_content' => $content];
    }

    /**
     * Importa database/schema.sql. Si una sentencia falla, elimina SOLO las
     * tablas que se crearon en esta ejecución y relanza el error.
     */
    private function importSchema(PDO $pdo): int
    {
        $file = $this->root . self::SCHEMA_FILE;
        $sql = @file_get_contents($file);
        if ($sql === false || trim($sql) === '') {
            throw new InstallerException('No se encontró database/schema.sql. Vuelve a subir los archivos del sistema.');
        }

        $created = [];
        foreach (SqlImporter::split($sql) as $statement) {
            try {
                $pdo->exec($statement);
            } catch (PDOException $e) {
                $this->dropTables($pdo, $created);
                $snippet = mb_substr((string) preg_replace('/\s+/', ' ', $statement), 0, 90);
                throw new InstallerException(
                    "Falló la creación del esquema en: \"{$snippet}…\". Se revirtieron las tablas creadas. Detalle: " . $e->getMessage(),
                    0,
                    $e
                );
            }
            $table = SqlImporter::createdTable($statement);
            if ($table !== null) {
                $created[] = $table;
            }
        }
        return count($created);
    }

    /** @param list<string> $tables */
    private function dropTables(PDO $pdo, array $tables): void
    {
        try {
            $pdo->exec('SET FOREIGN_KEY_CHECKS = 0');
            foreach (array_reverse($tables) as $table) {
                if (in_array($table, self::TABLES, true)) {
                    $pdo->exec("DROP TABLE IF EXISTS `{$table}`");
                }
            }
            $pdo->exec('SET FOREIGN_KEY_CHECKS = 1');
        } catch (Throwable $e) {
            error_log('[Installer] No se pudo revertir: ' . $e->getMessage());
        }
    }

    /** En reinstalaciones, garantiza que existan los catálogos mínimos. */
    private function ensureSeedData(PDO $pdo): void
    {
        if ((int) $pdo->query('SELECT COUNT(*) FROM estados')->fetchColumn() === 0) {
            $pdo->exec("INSERT INTO estados (nombre, color_hex, es_cierre) VALUES
                ('Abierto', '#0d6efd', 0), ('En proceso', '#ffc107', 0), ('En espera de usuario', '#fd7e14', 0),
                ('Resuelto', '#198754', 1), ('Cerrado', '#6c757d', 1)");
        }
        if ((int) $pdo->query('SELECT COUNT(*) FROM departamentos')->fetchColumn() === 0) {
            $pdo->exec("INSERT INTO departamentos (nombre, descripcion) VALUES
                ('Soporte Técnico en Sitio', 'Atención a equipos de cómputo, monitores y periféricos'),
                ('Control de Resguardos GECA', 'Actualización de ubicaciones y validación de inventario'),
                ('Garantías y Proveedores', 'Seguimiento de pólizas y garantías de hardware')");
        }
    }

    /**
     * Crea el admin o, si la ficha ya existe (vino en el GECA), la promueve
     * conservando sus equipos y tickets.
     *
     * @param array{ficha: string, nombre: string, email: string, password_hash: string} $admin
     */
    private function createAdmin(PDO $pdo, array $admin): int
    {
        $stmt = $pdo->prepare('SELECT id, ficha FROM usuarios WHERE email = ? LIMIT 1');
        $stmt->execute([$admin['email']]);
        $owner = $stmt->fetch();
        if ($owner && strcasecmp((string) $owner['ficha'], $admin['ficha']) !== 0) {
            throw new InstallerException("El correo {$admin['email']} ya pertenece a la ficha {$owner['ficha']}. Usa otro correo.");
        }

        $stmt = $pdo->prepare(
            "INSERT INTO usuarios (ficha, nombre, email, password_hash, rol, registrado, activo)
             VALUES (?, ?, ?, ?, 'admin_ti', 1, 1)
             ON DUPLICATE KEY UPDATE
                 id = LAST_INSERT_ID(id),
                 email = VALUES(email),
                 password_hash = VALUES(password_hash),
                 rol = 'admin_ti',
                 registrado = 1,
                 activo = 1"
        );
        $stmt->execute([$admin['ficha'], $admin['nombre'], $admin['email'], $admin['password_hash']]);
        return (int) $pdo->lastInsertId();
    }

    /**
     * Genera el contenido de config/config.php. Cada valor se exporta con
     * var_export() para que comillas o símbolos en contraseñas no rompan el PHP.
     *
     * @param array<string, mixed> $state
     */
    public function buildConfig(array $state): string
    {
        $db = $state['db'];
        $site = $state['site'];
        $x = static fn (mixed $v): string => var_export($v, true);
        $now = (new DateTimeImmutable('now', new DateTimeZone((string) $site['timezone'])))->format('Y-m-d H:i:s T');
        $secure = $site['force_https'] ? 'true' : 'null';
        $domain = (string) preg_replace('/^www\./i', '', (string) (parse_url((string) $site['base_url'], PHP_URL_HOST) ?: 'localhost'));
        $fromEmail = 'no-responder@' . $domain;
        // Con un dominio sin punto o una IP no hay remitente válido: correo desactivado.
        $mailDriver = filter_var($fromEmail, FILTER_VALIDATE_EMAIL) && str_contains($domain, '.') && !filter_var($domain, FILTER_VALIDATE_IP)
            ? 'mail'
            : 'off';

        return <<<PHP
<?php

declare(strict_types=1);

/**
 * Configuración generada por el instalador el {$now}.
 * Este archivo contiene credenciales: no lo compartas ni lo subas a Git.
 * Está bloqueado para acceso web por config/.htaccess.
 */
return [
    'app' => [
        'name'            => {$x($site['name'])},
        'base_url'        => {$x($site['base_url'])},
        'env'             => 'production',
        'timezone'        => {$x($site['timezone'])},
        'install_enabled' => false,
    ],

    'db' => [
        'host'      => {$x($db['host'])},
        'port'      => {$x((int) $db['port'])},
        'database'  => {$x($db['database'])},
        'username'  => {$x($db['username'])},
        'password'  => {$x($db['password'])},
        'charset'   => 'utf8mb4',
        'collation' => 'utf8mb4_unicode_ci',
    ],

    'session' => [
        'name'              => 'MESAAYUDA_SID',
        'lifetime'          => 7200,
        'use_local_storage' => true,
        'secure'            => {$secure},
        'samesite'          => 'Lax',
    ],

    'geca' => [
        'max_upload_mb'      => 30,
        'max_execution_time' => 300,
    ],

    // Avisos por correo. 'mail' usa la función mail() del hosting; para mejor
    // entrega cambia a 'smtp' con una cuenta de cPanel (Email Accounts).
    'mail' => [
        'driver'     => '{$mailDriver}',
        'from_email' => {$x($fromEmail)},
        'from_name'  => {$x($site['name'])},
        'mesa_institucional' => 'mesadeayuda@pemex.com',
        'notify_new_tickets_to' => [],
        'smtp' => [
            'host'       => {$x('mail.' . $domain)},
            'port'       => 465,
            'encryption' => 'ssl',
            'username'   => {$x($fromEmail)},
            'password'   => '',
            'timeout'    => 10,
        ],
    ],

    'security' => [
        'login_max_attempts' => 5,
        'login_lock_seconds' => 300,
    ],
];

PHP;
    }

    /** Escritura atómica: archivo temporal + rename, y validación de sintaxis por inclusión. */
    private function writeConfig(string $content): bool
    {
        $target = $this->root . self::CONFIG_FILE;
        $dir = dirname($target);
        if (!is_dir($dir) || !is_writable($dir)) {
            return false;
        }

        $tmp = $dir . '/.config.' . bin2hex(random_bytes(4)) . '.tmp';
        if (@file_put_contents($tmp, $content, LOCK_EX) === false) {
            return false;
        }
        @chmod($tmp, 0640);

        // Comprueba que el archivo generado sea PHP válido y devuelva un arreglo.
        try {
            $loaded = (static fn (string $f): mixed => require $f)($tmp);
        } catch (Throwable) {
            $loaded = null;
        }
        if (!is_array($loaded) || !isset($loaded['db']['database'])) {
            @unlink($tmp);
            return false;
        }

        if (!@rename($tmp, $target)) {
            @unlink($tmp);
            return false;
        }
        if (function_exists('opcache_invalidate')) {
            @opcache_invalidate($target, true);
        }
        return true;
    }

    private function enableHttpsRedirect(): bool
    {
        $file = $this->root . '/.htaccess';
        $content = @file_get_contents($file);
        if ($content === false || !is_writable($file)) {
            return false;
        }
        $from = [
            '    # RewriteCond %{HTTPS} !=on',
            '    # RewriteCond %{HTTP:X-Forwarded-Proto} !https',
            '    # RewriteRule ^ https://%{HTTP_HOST}%{REQUEST_URI} [L,R=301]',
        ];
        $to = array_map(static fn (string $l): string => str_replace('# ', '', $l), $from);
        if (!str_contains($content, $from[0])) {
            // Ya estaba activo o el archivo fue modificado a mano.
            return str_contains($content, $to[0]);
        }
        $updated = str_replace($from, $to, $content);
        return @file_put_contents($file, $updated, LOCK_EX) !== false;
    }

    private function lock(string $database): void
    {
        @unlink($this->root . self::KEY_FILE);
        @file_put_contents(
            $this->root . self::LOCK_FILE,
            json_encode([
                'installed_at' => date(DATE_ATOM),
                'database'     => $database,
                'php'          => PHP_VERSION,
            ], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE) . "\n",
            LOCK_EX
        );
    }

    // ==================================================================
    //  Utilidades
    // ==================================================================

    public static function isHttps(): bool
    {
        if (!empty($_SERVER['HTTPS']) && strtolower((string) $_SERVER['HTTPS']) !== 'off') {
            return true;
        }
        if (strtolower((string) ($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '')) === 'https') {
            return true;
        }
        return (int) ($_SERVER['SERVER_PORT'] ?? 80) === 443;
    }

    /** URL base detectada a partir de la petición actual (sin diagonal final). */
    public static function detectBaseUrl(string $basePath): string
    {
        $scheme = self::isHttps() ? 'https' : 'http';
        $host = (string) ($_SERVER['HTTP_HOST'] ?? 'localhost');
        $host = (string) preg_replace('/[^A-Za-z0-9.\-:\[\]]/', '', $host);
        return $scheme . '://' . $host . rtrim($basePath, '/');
    }

    /** Texto corto sin etiquetas para incluir en mensajes (la vista lo escapa). */
    private static function plain(string $value): string
    {
        return mb_substr(strip_tags($value), 0, 80);
    }

    private static function iniMegabytes(string $key): int
    {
        $value = trim((string) ini_get($key));
        if ($value === '' || $value === '-1' || $value === '0') {
            return 0;
        }
        $unit = strtolower(substr($value, -1));
        $number = (float) $value;
        return (int) floor(match ($unit) {
            'g' => $number * 1024,
            'm' => $number,
            'k' => $number / 1024,
            default => $number / 1048576,
        });
    }
}
