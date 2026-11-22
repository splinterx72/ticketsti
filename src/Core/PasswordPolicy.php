<?php

declare(strict_types=1);

namespace App\Core;

/**
 * Política de contraseñas compartida (registro de trabajadores e instalador).
 */
final class PasswordPolicy
{
    public const MIN_USER = 10;
    public const MIN_ADMIN = 12;
    public const MAX = 128;

    /**
     * @param list<string> $personal datos que no deben formar parte de la contraseña
     * @return list<string> mensajes de error (vacío = válida)
     */
    public static function errors(string $password, array $personal = [], int $minLength = self::MIN_USER): array
    {
        $errors = [];
        $length = mb_strlen($password);
        if ($length < $minLength) {
            $errors[] = "La contraseña debe tener al menos {$minLength} caracteres.";
        }
        if ($length > self::MAX) {
            $errors[] = 'La contraseña no puede exceder ' . self::MAX . ' caracteres.';
        }

        $classes = (int) (preg_match('/[a-z]/', $password) === 1)
            + (int) (preg_match('/[A-Z]/', $password) === 1)
            + (int) (preg_match('/\d/', $password) === 1)
            + (int) (preg_match('/[^A-Za-z0-9]/', $password) === 1);
        if ($classes < 3) {
            $errors[] = 'Combina al menos tres de: minúsculas, mayúsculas, números y símbolos.';
        }

        $lower = mb_strtolower($password);
        foreach ($personal as $value) {
            foreach (preg_split('/[\s@._-]+/', mb_strtolower(trim($value))) ?: [] as $piece) {
                if (mb_strlen($piece) >= 4 && str_contains($lower, $piece)) {
                    $errors[] = 'La contraseña no debe contener tu ficha, nombre o correo.';
                    break 2;
                }
            }
        }

        return $errors;
    }
}
