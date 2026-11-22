<?php

declare(strict_types=1);

namespace App\Services\Installer;

use RuntimeException;

/**
 * Error del instalador cuyo mensaje es seguro y útil para mostrar en pantalla.
 */
final class InstallerException extends RuntimeException
{
}
