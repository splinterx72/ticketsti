<?php

declare(strict_types=1);

namespace App\Core;

/**
 * Roles del sistema. El supervisor es un agente con funciones adicionales
 * (responde por una o más instalaciones, publica avisos y asigna tareas).
 */
final class Roles
{
    /** Personal de TI: atiende tickets, carga GECA, consulta inventario y garantías. */
    public const TI = ['agente_ti', 'supervisor', 'admin_ti'];

    /** Puede publicar avisos, asignar tareas y gestionar agentes de sus instalaciones. */
    public const SUPERVISION = ['supervisor', 'admin_ti'];

    public const LABELS = [
        'usuario'    => 'Usuario',
        'agente_ti'  => 'Agente TI',
        'supervisor' => 'Supervisor',
        'admin_ti'   => 'Admin TI',
    ];

    public static function label(string $rol): string
    {
        return self::LABELS[$rol] ?? $rol;
    }

    public static function esTi(?string $rol): bool
    {
        return in_array($rol, self::TI, true);
    }
}
