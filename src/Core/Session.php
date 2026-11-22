<?php

declare(strict_types=1);

namespace App\Core;

/**
 * Manejo de sesión endurecido para hosting compartido.
 */
final class Session
{
    /**
     * @param array<string, mixed> $config
     */
    public static function start(array $config, string $storagePath): void
    {
        if (session_status() === PHP_SESSION_ACTIVE) {
            return;
        }

        $lifetime = (int) ($config['lifetime'] ?? 7200);
        $secure = $config['secure'] ?? null;
        if ($secure === null) {
            $secure = self::isHttps();
        }

        if (!empty($config['use_local_storage']) && is_dir($storagePath) && is_writable($storagePath)) {
            session_save_path($storagePath);
            // Limpieza probabilística de sesiones viejas en nuestra carpeta.
            ini_set('session.gc_probability', '1');
            ini_set('session.gc_divisor', '100');
            ini_set('session.gc_maxlifetime', (string) $lifetime);
        }

        ini_set('session.use_strict_mode', '1');
        ini_set('session.use_only_cookies', '1');
        ini_set('session.use_trans_sid', '0');

        session_name((string) ($config['name'] ?? 'APPSID'));
        session_set_cookie_params([
            'lifetime' => 0,
            'path'     => self::cookiePath(),
            'secure'   => (bool) $secure,
            'httponly' => true,
            'samesite' => (string) ($config['samesite'] ?? 'Lax'),
        ]);

        session_start();

        // Expiración por inactividad.
        $now = time();
        $last = (int) ($_SESSION['_last_activity'] ?? $now);
        if (($now - $last) > $lifetime) {
            $_SESSION = [];
            session_regenerate_id(true);
        }
        $_SESSION['_last_activity'] = $now;
    }

    public static function regenerate(): void
    {
        if (session_status() === PHP_SESSION_ACTIVE) {
            session_regenerate_id(true);
        }
    }

    public static function get(string $key, mixed $default = null): mixed
    {
        return $_SESSION[$key] ?? $default;
    }

    public static function set(string $key, mixed $value): void
    {
        $_SESSION[$key] = $value;
    }

    public static function remove(string $key): void
    {
        unset($_SESSION[$key]);
    }

    public static function destroy(): void
    {
        if (session_status() !== PHP_SESSION_ACTIVE) {
            return;
        }
        $_SESSION = [];
        $params = session_get_cookie_params();
        setcookie(session_name(), '', [
            'expires'  => time() - 42000,
            'path'     => $params['path'],
            'domain'   => $params['domain'],
            'secure'   => $params['secure'],
            'httponly' => $params['httponly'],
            'samesite' => $params['samesite'] ?? 'Lax',
        ]);
        session_destroy();
    }

    /** Mensaje de un solo uso para mostrar tras un redirect. */
    public static function flash(string $type, string $message): void
    {
        $_SESSION['_flash'][] = ['type' => $type, 'message' => $message];
    }

    /** @return list<array{type: string, message: string}> */
    public static function pullFlash(): array
    {
        $messages = $_SESSION['_flash'] ?? [];
        unset($_SESSION['_flash']);
        return is_array($messages) ? array_values($messages) : [];
    }

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

    private static function cookiePath(): string
    {
        $dir = str_replace('\\', '/', dirname((string) ($_SERVER['SCRIPT_NAME'] ?? '/')));
        return ($dir === '' || $dir === '.') ? '/' : rtrim($dir, '/') . '/';
    }
}
