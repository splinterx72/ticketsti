<?php

declare(strict_types=1);

namespace App\Config;

use DateTimeImmutable;
use DateTimeZone;
use PDO;
use PDOException;
use RuntimeException;

/**
 * Conexión PDO única (Singleton) a MySQL / MariaDB de cPanel.
 *
 * - charset utf8mb4 + collation utf8mb4_unicode_ci
 * - zona horaria de sesión America/Mexico_City. En hosting compartido las
 *   tablas de zonas horarias de MySQL casi nunca están cargadas, así que si el
 *   nombre falla se usa el desfase numérico calculado por PHP (ej. -06:00).
 * - Prepared statements reales (EMULATE_PREPARES = false).
 */
final class Database
{
    private static ?PDO $instance = null;

    /** @var array<string, mixed> */
    private static array $config = [];

    private static string $timezone = 'America/Mexico_City';

    private function __construct()
    {
    }

    private function __clone()
    {
    }

    public function __wakeup(): void
    {
        throw new RuntimeException('No se puede deserializar la conexión.');
    }

    /**
     * @param array<string, mixed> $dbConfig
     */
    public static function configure(array $dbConfig, string $timezone = 'America/Mexico_City'): void
    {
        self::$config = $dbConfig;
        self::$timezone = $timezone;
        self::$instance = null;
    }

    public static function getInstance(): PDO
    {
        if (self::$instance instanceof PDO) {
            return self::$instance;
        }

        if (self::$config === []) {
            throw new RuntimeException('La base de datos no está configurada (config/config.php).');
        }

        $c = self::$config;
        $charset = (string) ($c['charset'] ?? 'utf8mb4');
        $collation = (string) ($c['collation'] ?? 'utf8mb4_unicode_ci');

        $dsn = sprintf(
            'mysql:host=%s;port=%d;dbname=%s;charset=%s',
            (string) ($c['host'] ?? 'localhost'),
            (int) ($c['port'] ?? 3306),
            (string) ($c['database'] ?? ''),
            $charset
        );

        $options = [
            PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES   => false,
            PDO::ATTR_STRINGIFY_FETCHES  => false,
            PDO::ATTR_PERSISTENT         => false,
        ];

        try {
            $pdo = new PDO($dsn, (string) ($c['username'] ?? ''), (string) ($c['password'] ?? ''), $options);
        } catch (PDOException $e) {
            // No exponemos credenciales ni DSN en el mensaje público.
            error_log('[DB] Error de conexión: ' . $e->getMessage());
            throw new RuntimeException('No fue posible conectar con la base de datos.', 0, $e);
        }

        $pdo->exec("SET NAMES {$charset} COLLATE {$collation}");
        self::applyTimezone($pdo);

        self::$instance = $pdo;
        return $pdo;
    }

    private static function applyTimezone(PDO $pdo): void
    {
        try {
            $stmt = $pdo->prepare('SET time_zone = ?');
            $stmt->execute([self::$timezone]);
        } catch (PDOException) {
            $offset = (new DateTimeImmutable('now', new DateTimeZone(self::$timezone)))->format('P');
            $stmt = $pdo->prepare('SET time_zone = ?');
            $stmt->execute([$offset]);
        }
    }
}
