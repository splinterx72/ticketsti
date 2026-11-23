<?php

declare(strict_types=1);

/**
 * Arnés de pruebas sin dependencias. Requiere MySQL/MariaDB local.
 * Variables opcionales: TEST_DB_HOST, TEST_DB_USER, TEST_DB_PASS, TEST_DB_NAME.
 */

use App\Config\Database;
use App\Core\Autoloader;
use App\Services\Installer\SqlImporter;

define('ROOT_PATH', dirname(__DIR__));
define('STORAGE_PATH', ROOT_PATH . '/storage');
date_default_timezone_set('America/Mexico_City');
mb_internal_encoding('UTF-8');

require ROOT_PATH . '/src/Core/Autoloader.php';
Autoloader::register('App', ROOT_PATH . '/src');
require ROOT_PATH . '/src/Core/helpers.php';

const TEST_DB = [
    'host'     => 'localhost',
    'port'     => 3306,
    'charset'  => 'utf8mb4',
    'collation' => 'utf8mb4_unicode_ci',
];

/** @return array<string, mixed> */
function test_db_config(?string $database = null): array
{
    return TEST_DB + [
        'host'     => getenv('TEST_DB_HOST') ?: 'localhost',
        'database' => $database ?? (getenv('TEST_DB_NAME') ?: 'usr_mesa_test'),
        'username' => getenv('TEST_DB_USER') ?: 'usr_tk',
        'password' => getenv('TEST_DB_PASS') ?: 'secretoTest1',
    ];
}

$config = require ROOT_PATH . '/config/config.example.php';
$config['db'] = test_db_config();
$config['app']['base_url'] = 'http://localhost/tickets';
$config['mail']['driver'] = 'off';
$GLOBALS['__app_config'] = $config;

/** Conexión sin base seleccionada (para crear/borrar bases de prueba). */
function test_server_pdo(): PDO
{
    $c = test_db_config();
    return new PDO(
        sprintf('mysql:host=%s;port=%d;charset=utf8mb4', $c['host'], $c['port']),
        $c['username'],
        $c['password'],
        [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC]
    );
}

/** Crea (o recrea) una base vacía y devuelve su conexión. */
function fresh_db(string $name): PDO
{
    $server = test_server_pdo();
    $server->exec("DROP DATABASE IF EXISTS `{$name}`");
    $server->exec("CREATE DATABASE `{$name}` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci");
    $c = test_db_config($name);
    $pdo = new PDO(
        sprintf('mysql:host=%s;port=%d;dbname=%s;charset=utf8mb4', $c['host'], $c['port'], $name),
        $c['username'],
        $c['password'],
        [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC, PDO::ATTR_EMULATE_PREPARES => false]
    );
    $pdo->exec('SET NAMES utf8mb4 COLLATE utf8mb4_unicode_ci');
    return $pdo;
}

function import_sql(PDO $pdo, string $sql): void
{
    foreach (SqlImporter::split($sql) as $statement) {
        $pdo->exec($statement);
    }
}

/** Base principal de pruebas con el esquema vigente, vía el Singleton de la app. */
function reset_db(): void
{
    $pdo = fresh_db(test_db_config()['database']);
    import_sql($pdo, (string) file_get_contents(ROOT_PATH . '/database/schema.sql'));
    Database::configure(test_db_config(), 'America/Mexico_City');
}

function test_pdo(): PDO
{
    return Database::getInstance();
}

// ---------------------------------------------------------------------
//  Aserciones
// ---------------------------------------------------------------------

/** @var list<string> */
$GLOBALS['__test_failures'] = [];

function assert_eq(mixed $got, mixed $want, string $label): void
{
    if ($got != $want) {
        $GLOBALS['__test_failures'][] = $label . ' → obtuvo ' . var_export($got, true) . ', esperaba ' . var_export($want, true);
    }
}

function assert_true(bool $cond, string $label): void
{
    if (!$cond) {
        $GLOBALS['__test_failures'][] = $label;
    }
}

/** Ejecuta $fn y verifica que lance $class (opcionalmente con texto). */
function assert_throws(string $class, callable $fn, string $label, ?string $contains = null): void
{
    try {
        $fn();
    } catch (Throwable $e) {
        if (!$e instanceof $class) {
            $GLOBALS['__test_failures'][] = "{$label} → lanzó " . $e::class . ': ' . $e->getMessage();
            return;
        }
        if ($contains !== null && !str_contains($e->getMessage(), $contains)) {
            $GLOBALS['__test_failures'][] = "{$label} → mensaje sin \"{$contains}\": " . $e->getMessage();
        }
        return;
    }
    $GLOBALS['__test_failures'][] = "{$label} → no lanzó {$class}";
}

/** Inserta una fila y devuelve su id. @param array<string, mixed> $data */
function insert(string $table, array $data): int
{
    $cols = array_keys($data);
    $sql = sprintf(
        'INSERT INTO `%s` (`%s`) VALUES (%s)',
        $table,
        implode('`, `', $cols),
        implode(', ', array_fill(0, count($cols), '?'))
    );
    test_pdo()->prepare($sql)->execute(array_values($data));
    return (int) test_pdo()->lastInsertId();
}

function scalar(string $sql, array $params = []): mixed
{
    $stmt = test_pdo()->prepare($sql);
    $stmt->execute($params);
    return $stmt->fetchColumn();
}

require __DIR__ . '/fixtures.php';
