<?php

declare(strict_types=1);

namespace App\Core;

/**
 * Roles del sistema. Jerarquía: Admin TI → Coordinador (zonas) → Supervisor
 * (instalaciones) → Agente → Usuario. Supervisor y coordinador también
 * atienden tickets como agentes.
 */
final class Roles
{
    /** Personal de TI: atiende tickets, carga GECA, consulta inventario y garantías. */
    public const TI = ['agente_ti', 'supervisor', 'coordinador', 'admin_ti'];

    /** Puede publicar avisos, asignar tareas y gestionar agentes de sus instalaciones. */
    public const SUPERVISION = ['supervisor', 'coordinador', 'admin_ti'];

    /** Asigna supervisores a instalaciones (el coordinador, solo en sus zonas). */
    public const COORDINACION = ['coordinador', 'admin_ti'];

    public const LABELS = [
        'usuario'     => 'Usuario',
        'agente_ti'   => 'Agente TI',
        'supervisor'  => 'Supervisor',
        'coordinador' => 'Coordinador',
        'admin_ti'    => 'Admin TI',
    ];

    /** Lista para SQL "IN (...)" de constantes de rol (nunca datos del usuario). @param list<string> $roles */
    public static function sqlIn(array $roles): string
    {
        return implode(', ', array_map(static fn (string $r): string => "'" . preg_replace('/[^a-z_]/', '', $r) . "'", $roles));
    }

    public static function label(string $rol): string
    {
        return self::LABELS[$rol] ?? $rol;
    }

    public static function esTi(?string $rol): bool
    {
        return in_array($rol, self::TI, true);
    }
}
