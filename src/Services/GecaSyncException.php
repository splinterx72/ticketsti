<?php

declare(strict_types=1);

namespace App\Services;

use RuntimeException;

/**
 * Error de validación del archivo GECA cuyo mensaje es seguro mostrar al
 * usuario (encabezados faltantes, formato no soportado, archivo vacío...).
 */
final class GecaSyncException extends RuntimeException
{
}
