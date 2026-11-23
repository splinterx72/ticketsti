<?php

declare(strict_types=1);

namespace App\Core;

use App\Config\Database;

/**
 * Autenticación y autorización por rol.
 * Roles: ver App\Core\Roles (usuario, agente_ti, supervisor, admin_ti).
 */
final class Auth
{
    private const SESSION_KEY = '_auth_user_id';

    /** @var array<string, mixed>|null|false  false = aún no consultado */
    private static array|null|false $cached = false;

    /** @return array<string, mixed>|null */
    public static function user(): ?array
    {
        if (self::$cached !== false) {
            return self::$cached;
        }

        $id = Session::get(self::SESSION_KEY);
        if (!is_int($id) || $id <= 0) {
            return self::$cached = null;
        }

        $stmt = Database::getInstance()->prepare(
            'SELECT id, ficha, nombre, email, rol, registrado, activo, telefono_ext, email_verificado_en,
                    admin_local_cambiado_en, habitual_ubicacion_fisica_id, habitual_nivel_id, habitual_ala
               FROM usuarios WHERE id = ? LIMIT 1'
        );
        $stmt->execute([$id]);
        $user = $stmt->fetch();

        if (!$user || (int) $user['activo'] !== 1 || (int) $user['registrado'] !== 1) {
            Session::remove(self::SESSION_KEY);
            return self::$cached = null;
        }

        return self::$cached = $user;
    }

    /** El usuario con sesión aún no verifica su correo de la empresa (spec 7.6 bis). */
    public static function requiereVerificacion(): bool
    {
        $user = self::user();
        return $user !== null && $user['email_verificado_en'] === null;
    }

    /** Olvida el usuario en caché (tras cambiar sus datos en la misma petición). */
    public static function refrescar(): void
    {
        self::$cached = false;
    }

    public static function id(): ?int
    {
        $user = self::user();
        return $user ? (int) $user['id'] : null;
    }

    public static function check(): bool
    {
        return self::user() !== null;
    }

    /** @param list<string> $roles */
    public static function hasRole(array $roles): bool
    {
        $user = self::user();
        return $user !== null && in_array($user['rol'], $roles, true);
    }

    /**
     * Valida credenciales. Acepta ficha o correo como identificador.
     *
     * @return array<string, mixed>|null
     */
    public static function attempt(string $identifier, string $password): ?array
    {
        $identifier = trim($identifier);
        if ($identifier === '' || $password === '') {
            return null;
        }

        $stmt = Database::getInstance()->prepare(
            'SELECT id, password_hash, activo, registrado
               FROM usuarios
              WHERE ficha = ? OR email = ?
              LIMIT 1'
        );
        $stmt->execute([$identifier, mb_strtolower($identifier)]);
        $row = $stmt->fetch();

        // Hash ficticio para que el tiempo de respuesta no revele si existe la cuenta.
        $hash = $row['password_hash'] ?? null;
        if (!is_string($hash) || $hash === '') {
            password_verify($password, '$2y$10$S7D5UKDZ9R4zN98N/fEZvupHlDUXgxHAYVPom/.vpKSQUSW1NJdui');
            return null;
        }

        if (!password_verify($password, $hash)) {
            return null;
        }
        if ((int) $row['activo'] !== 1 || (int) $row['registrado'] !== 1) {
            return null;
        }

        if (password_needs_rehash($hash, PASSWORD_DEFAULT)) {
            $upd = Database::getInstance()->prepare('UPDATE usuarios SET password_hash = ? WHERE id = ?');
            $upd->execute([password_hash($password, PASSWORD_DEFAULT), (int) $row['id']]);
        }

        return ['id' => (int) $row['id']];
    }

    public static function login(int $userId): void
    {
        Session::regenerate();
        Csrf::rotate();
        Session::set(self::SESSION_KEY, $userId);
        self::$cached = false;
    }

    public static function logout(): void
    {
        Session::destroy();
        self::$cached = null;
    }
}
