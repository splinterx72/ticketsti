<?php

declare(strict_types=1);

namespace App\Core;

use RuntimeException;

/**
 * Error HTTP controlado (400, 403, 404, 405...) que el Front Controller
 * convierte en una página de error amigable.
 */
final class HttpException extends RuntimeException
{
    public function __construct(private readonly int $status, string $message = '')
    {
        parent::__construct($message, $status);
    }

    public function status(): int
    {
        return $this->status;
    }
}
