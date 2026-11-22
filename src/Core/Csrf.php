<?php

declare(strict_types=1);

namespace App\Core;

/**
 * Protección CSRF basada en token de sesión (synchronizer token).
 */
final class Csrf
{
    private const KEY = '_csrf_token';
    public const FIELD = '_csrf';

    public static function token(): string
    {
        $token = $_SESSION[self::KEY] ?? null;
        if (!is_string($token) || strlen($token) !== 64) {
            $token = bin2hex(random_bytes(32));
            $_SESSION[self::KEY] = $token;
        }
        return $token;
    }

    public static function field(): string
    {
        return '<input type="hidden" name="' . self::FIELD . '" value="'
            . htmlspecialchars(self::token(), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') . '">';
    }

    public static function validate(?string $submitted): bool
    {
        $token = $_SESSION[self::KEY] ?? null;
        if (!is_string($token) || !is_string($submitted) || $submitted === '') {
            return false;
        }
        return hash_equals($token, $submitted);
    }

    /** Rota el token (p. ej. después de iniciar sesión). */
    public static function rotate(): void
    {
        $_SESSION[self::KEY] = bin2hex(random_bytes(32));
    }
}
