<?php

declare(strict_types=1);

namespace App\Services;

use App\Config\Database;
use RuntimeException;

/**
 * Catálogos (estados, departamentos, ubicaciones, agentes) con caché por petición.
 */
final class Catalog
{
    public const ESTADO_INICIAL = 'Abierto';
    public const ESTADO_EN_PROCESO = 'En proceso';
    public const ESTADO_ESPERA_USUARIO = 'En espera de usuario';

    public const DEPTO_SOPORTE = 'Soporte Técnico en Sitio';
    public const DEPTO_RESGUARDOS = 'Control de Resguardos GECA';
    public const DEPTO_GARANTIAS = 'Garantías y Proveedores';

    public const TIPOS = [
        'incidencia'              => 'Incidencia (algo no funciona)',
        'solicitud'               => 'Solicitud (instalación, acceso, cambio)',
        'garantia'                => 'Garantía de hardware',
        'actualizacion_ubicacion' => 'Actualización de ubicación',
    ];

    /** Tipos que puede elegir un trabajador al crear un ticket. */
    public const TIPOS_USUARIO = ['incidencia', 'solicitud', 'garantia'];

    public const PRIORIDADES = [
        'baja'    => 'Baja',
        'media'   => 'Media',
        'alta'    => 'Alta',
        'critica' => 'Crítica',
    ];

    public const PRIORIDADES_USUARIO = ['baja', 'media', 'alta'];

    public const COMPONENTES = [
        'equipo'    => 'Equipo (CPU / laptop)',
        'monitor_1' => 'Monitor 1',
        'monitor_2' => 'Monitor 2',
        'general'   => 'General / sin equipo',
    ];

    /** @var array<string, mixed> */
    private static array $cache = [];

    /** @return list<array{id: int, nombre: string, color_hex: string, es_cierre: int}> */
    public static function estados(): array
    {
        return self::$cache['estados'] ??= array_map(
            static fn (array $r): array => [
                'id' => (int) $r['id'], 'nombre' => (string) $r['nombre'],
                'color_hex' => (string) $r['color_hex'], 'es_cierre' => (int) $r['es_cierre'],
            ],
            Database::getInstance()->query('SELECT id, nombre, color_hex, es_cierre FROM estados ORDER BY id')->fetchAll()
        );
    }

    /** @return array{id: int, nombre: string, color_hex: string, es_cierre: int}|null */
    public static function estado(int $id): ?array
    {
        foreach (self::estados() as $e) {
            if ($e['id'] === $id) {
                return $e;
            }
        }
        return null;
    }

    public static function estadoIdPorNombre(string $nombre): int
    {
        foreach (self::estados() as $e) {
            if (mb_strtolower($e['nombre']) === mb_strtolower($nombre)) {
                return $e['id'];
            }
        }
        // Respaldo: el primer estado abierto del catálogo.
        foreach (self::estados() as $e) {
            if ($e['es_cierre'] === 0) {
                return $e['id'];
            }
        }
        throw new RuntimeException('El catálogo de estados está vacío.');
    }

    /** @return list<int> */
    public static function estadosCierreIds(): array
    {
        return array_values(array_map(
            static fn (array $e): int => $e['id'],
            array_filter(self::estados(), static fn (array $e): bool => $e['es_cierre'] === 1)
        ));
    }

    /** @return list<array{id: int, nombre: string, descripcion: ?string}> */
    public static function departamentos(): array
    {
        return self::$cache['departamentos'] ??= array_map(
            static fn (array $r): array => ['id' => (int) $r['id'], 'nombre' => (string) $r['nombre'], 'descripcion' => $r['descripcion']],
            Database::getInstance()->query('SELECT id, nombre, descripcion FROM departamentos WHERE activo = 1 ORDER BY id')->fetchAll()
        );
    }

    public static function departamentoIdPorNombre(string $nombre): int
    {
        $deptos = self::departamentos();
        foreach ($deptos as $d) {
            if (mb_strtolower($d['nombre']) === mb_strtolower($nombre)) {
                return $d['id'];
            }
        }
        if ($deptos === []) {
            throw new RuntimeException('No hay departamentos activos.');
        }
        return $deptos[0]['id'];
    }

    /** Departamento al que se enruta automáticamente cada tipo de ticket. */
    public static function departamentoParaTipo(string $tipo): int
    {
        return self::departamentoIdPorNombre(match ($tipo) {
            'garantia'                => self::DEPTO_GARANTIAS,
            'actualizacion_ubicacion' => self::DEPTO_RESGUARDOS,
            default                   => self::DEPTO_SOPORTE,
        });
    }

    /** @return list<array{id: int, etiqueta: string}> */
    public static function ubicaciones(): array
    {
        return self::$cache['ubicaciones'] ??= array_map(
            static fn (array $r): array => ['id' => (int) $r['id'], 'etiqueta' => self::etiquetaUbicacion($r)],
            Database::getInstance()->query(
                'SELECT id, eps, subdireccion, gerencia, zona, sector, inmueble
                   FROM ubicaciones WHERE activo = 1
                  ORDER BY inmueble, gerencia, subdireccion, zona, sector'
            )->fetchAll()
        );
    }

    public static function ubicacionExiste(int $id): bool
    {
        $stmt = Database::getInstance()->prepare('SELECT 1 FROM ubicaciones WHERE id = ? AND activo = 1');
        $stmt->execute([$id]);
        return (bool) $stmt->fetchColumn();
    }

    /** @param array<string, mixed> $u */
    public static function etiquetaUbicacion(array $u): string
    {
        $detalle = array_filter([
            (string) ($u['gerencia'] ?? ''),
            (string) ($u['subdireccion'] ?? ''),
            trim((string) ($u['zona'] ?? '') . ' ' . (string) ($u['sector'] ?? '')),
        ], static fn (string $v): bool => $v !== '');
        $inmueble = (string) ($u['inmueble'] ?? '');
        return $inmueble . ($detalle !== [] ? ' — ' . implode(' / ', $detalle) : '');
    }

    /** @return list<array{id: int, nombre: string}> */
    public static function agentes(): array
    {
        return self::$cache['agentes'] ??= array_map(
            static fn (array $r): array => ['id' => (int) $r['id'], 'nombre' => (string) $r['nombre']],
            Database::getInstance()->query(
                "SELECT id, nombre FROM usuarios
                  WHERE rol IN (" . \App\Core\Roles::sqlIn(\App\Core\Roles::TI) . ") AND registrado = 1 AND activo = 1
                  ORDER BY nombre"
            )->fetchAll()
        );
    }
}
