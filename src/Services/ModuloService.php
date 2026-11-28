<?php

declare(strict_types=1);

namespace App\Services;

use App\Core\ValidationException;
use PDO;

/**
 * Módulos que el Admin TI enciende o apaga y los roles que los usan.
 * admin_ti siempre tiene acceso (para poder volver a encenderlos).
 * Dentro de un módulo siguen aplicando el alcance y los permisos propios
 * (p. ej. el trabajador solo ve las garantías de sus equipos).
 */
final class ModuloService
{
    /** Igual a las semillas del esquema; se usa si la tabla aún no existe (migración pendiente). */
    public const POR_OMISION = [
        'garantias'  => 'usuario,agente_ti,supervisor,coordinador',
        'bitlocker'  => 'usuario,agente_ti,supervisor,coordinador',
        'inventario' => 'agente_ti,supervisor,coordinador',
        'reportes'   => 'agente_ti,supervisor,coordinador',
        'tareas'     => 'agente_ti,supervisor,coordinador',
        'avisos'     => 'supervisor,coordinador',
        'plantillas' => 'agente_ti,supervisor,coordinador',
        'formatos'   => 'usuario,agente_ti,supervisor,coordinador',
        'resguardo'  => 'agente_ti,supervisor,coordinador',
    ];

    /** Roles a los que puede darse cada módulo (el resto depende de funciones que esos roles no tienen). */
    public const POSIBLES = [
        'garantias'  => ['usuario', 'agente_ti', 'supervisor', 'coordinador'],
        'bitlocker'  => ['usuario', 'agente_ti', 'supervisor', 'coordinador'],
        'inventario' => ['agente_ti', 'supervisor', 'coordinador'],
        'reportes'   => ['agente_ti', 'supervisor', 'coordinador'],
        'tareas'     => ['agente_ti', 'supervisor', 'coordinador'],
        'avisos'     => ['supervisor', 'coordinador'],
        'plantillas' => ['agente_ti', 'supervisor', 'coordinador'],
        'formatos'   => ['usuario', 'agente_ti', 'supervisor', 'coordinador'],
        'resguardo'  => ['agente_ti', 'supervisor', 'coordinador'],
    ];

    /** @var array<string, array{activo: bool, roles: list<string>}>|null */
    private static ?array $cache = null;

    public function __construct(private readonly PDO $pdo)
    {
    }

    public function permite(string $clave, ?string $rol): bool
    {
        if ($rol === 'admin_ti') {
            return true;
        }
        $m = $this->mapa()[$clave] ?? null;
        return $m !== null && $m['activo'] && $rol !== null && in_array($rol, $m['roles'], true);
    }

    /** @return list<array{clave: string, nombre: string, descripcion: ?string, activo: bool, roles: list<string>}> */
    public function todos(): array
    {
        $rows = $this->pdo->query('SELECT clave, nombre, descripcion, activo, roles FROM modulos ORDER BY clave')->fetchAll();
        return array_map(static fn (array $r): array => [
            'clave' => (string) $r['clave'], 'nombre' => (string) $r['nombre'], 'descripcion' => $r['descripcion'],
            'activo' => (int) $r['activo'] === 1, 'roles' => self::roles((string) $r['roles']),
        ], $rows);
    }

    /** @param list<string> $roles */
    public function guardar(string $clave, bool $activo, array $roles): void
    {
        if (!array_key_exists($clave, $this->mapa())) {
            throw new ValidationException(['Ese módulo no existe.']);
        }
        $validos = array_values(array_filter(
            self::POSIBLES[$clave] ?? [],
            static fn (string $r): bool => in_array($r, $roles, true)
        ));
        $this->pdo->prepare('UPDATE modulos SET activo = ?, roles = ? WHERE clave = ?')
            ->execute([$activo ? 1 : 0, implode(',', $validos), $clave]);
        self::$cache = null;
    }

    /** @return array<string, array{activo: bool, roles: list<string>}> */
    private function mapa(): array
    {
        if (self::$cache === null) {
            self::$cache = [];
            try {
                foreach ($this->pdo->query('SELECT clave, activo, roles FROM modulos')->fetchAll() as $r) {
                    self::$cache[(string) $r['clave']] = ['activo' => (int) $r['activo'] === 1, 'roles' => self::roles((string) $r['roles'])];
                }
            } catch (\PDOException) {
                // Base sin migrar: mismos permisos de siempre.
                foreach (self::POR_OMISION as $clave => $roles) {
                    self::$cache[$clave] = ['activo' => true, 'roles' => self::roles($roles)];
                }
            }
        }
        return self::$cache;
    }

    /** Limpia la memoria de la petición (pruebas). */
    public static function olvidar(): void
    {
        self::$cache = null;
    }

    /** @return list<string> */
    private static function roles(string $csv): array
    {
        return array_values(array_filter(array_map('trim', explode(',', $csv)), static fn (string $r): bool => $r !== ''));
    }
}
