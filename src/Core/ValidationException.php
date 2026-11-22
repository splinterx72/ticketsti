<?php

declare(strict_types=1);

namespace App\Core;

use RuntimeException;

/**
 * Errores de validación de formulario, seguros para mostrar al usuario.
 */
final class ValidationException extends RuntimeException
{
    /** @param list<string> $errors */
    public function __construct(private readonly array $errors)
    {
        parent::__construct(implode(' ', $errors));
    }

    /** @return list<string> */
    public function errors(): array
    {
        return $this->errors;
    }
}
