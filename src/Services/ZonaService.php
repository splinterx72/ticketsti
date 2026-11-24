<?php

declare(strict_types=1);

namespace App\Services;

use App\Core\ValidationException;
use PDO;

/**
 * Zonas (Norte, Sur, Marina...): agrupan instalaciones bajo uno o varios
 * coordinadores (titular o suplencia con fechas). Las crea el Admin TI.
 */
final class ZonaService
{
    private const VIGENTE = '(x.desde IS NULL OR x.desde <= ?) AND (x.hasta IS NULL OR x.hasta >= ?)';

    public function __construct(private readonly PDO $pdo)
    {
    }

    public function guardarZona(?int $id, string $nombre, bool $activo = true): int
    {
        $nombre = trim((string) preg_replace('/\s+/u', ' ', $nombre));
        if (mb_strlen($nombre) < 2 || mb_strlen($nombre) > 100) {
            throw new ValidationException(['El nombre de la zona debe tener entre 2 y 100 caracteres.']);
        }
        $dup = $this->pdo->prepare('SELECT id FROM zonas WHERE nombre = ? AND id <> ?');
        $dup->execute([$nombre, $id ?? 0]);
        if ($dup->fetchColumn()) {
            throw new ValidationException(['Ya existe una zona con ese nombre.']);
        }
        if ($id === null) {
            $this->pdo->prepare('INSERT INTO zonas (nombre, activo) VALUES (?, ?)')->execute([$nombre, $activo ? 1 : 0]);
            return (int) $this->pdo->lastInsertId();
        }
        $this->pdo->prepare('UPDATE zonas SET nombre = ?, activo = ? WHERE id = ?')->execute([$nombre, $activo ? 1 : 0, $id]);
        return $id;
    }

    public function asignarZona(int $instalacionId, ?int $zonaId): void
    {
        $this->pdo->prepare('UPDATE instalaciones SET zona_id = ? WHERE id = ?')->execute([$zonaId !== null && $zonaId > 0 ? $zonaId : null, $instalacionId]);
    }

    /** @return list<array<string, mixed>> zonas con sus instalaciones y coordinadores vigentes */
    public function zonas(): array
    {
        $zonas = $this->pdo->query('SELECT id, nombre, activo FROM zonas ORDER BY nombre')->fetchAll();
        $hoy = date('Y-m-d');
        foreach ($zonas as &$z) {
            $i = $this->pdo->prepare('SELECT id, nombre FROM instalaciones WHERE zona_id = ? ORDER BY nombre');
            $i->execute([(int) $z['id']]);
            $z['instalaciones'] = $i->fetchAll();
            $c = $this->pdo->prepare(
                'SELECT x.id AS asignacion_id, x.tipo, x.desde, x.hasta, u.id, u.nombre, u.ficha
                   FROM coordinador_zonas x JOIN usuarios u ON u.id = x.coordinador_id
                  WHERE x.zona_id = ? AND (x.hasta IS NULL OR x.hasta >= ?) ORDER BY x.tipo, u.nombre'
            );
            $c->execute([(int) $z['id'], $hoy]);
            $z['coordinadores'] = $c->fetchAll();
        }
        unset($z);
        return $zonas;
    }

    /** @return list<int> zonas vigentes del coordinador */
    public function zonasDe(int $coordinadorId, ?string $fecha = null): array
    {
        $fecha ??= date('Y-m-d');
        $stmt = $this->pdo->prepare('SELECT DISTINCT x.zona_id FROM coordinador_zonas x WHERE x.coordinador_id = ? AND ' . self::VIGENTE);
        $stmt->execute([$coordinadorId, $fecha, $fecha]);
        return array_map('intval', $stmt->fetchAll(PDO::FETCH_COLUMN));
    }

    /** @return list<int> instalaciones activas de las zonas vigentes del coordinador */
    public function instalacionesDeCoordinador(int $coordinadorId, ?string $fecha = null): array
    {
        $fecha ??= date('Y-m-d');
        $stmt = $this->pdo->prepare(
            'SELECT DISTINCT i.id FROM instalaciones i JOIN coordinador_zonas x ON x.zona_id = i.zona_id
              WHERE i.activo = 1 AND x.coordinador_id = ? AND ' . self::VIGENTE . ' ORDER BY i.id'
        );
        $stmt->execute([$coordinadorId, $fecha, $fecha]);
        return array_map('intval', $stmt->fetchAll(PDO::FETCH_COLUMN));
    }

    /** @return list<array{id: int, nombre: string, email: ?string}> coordinadores vigentes de la zona de una instalación */
    public function coordinadoresDe(int $instalacionId, ?string $fecha = null): array
    {
        $fecha ??= date('Y-m-d');
        $stmt = $this->pdo->prepare(
            'SELECT DISTINCT u.id, u.nombre, u.email
               FROM instalaciones i JOIN coordinador_zonas x ON x.zona_id = i.zona_id JOIN usuarios u ON u.id = x.coordinador_id
              WHERE i.id = ? AND u.activo = 1 AND ' . self::VIGENTE . ' ORDER BY u.nombre'
        );
        $stmt->execute([$instalacionId, $fecha, $fecha]);
        return array_map(
            static fn (array $r): array => ['id' => (int) $r['id'], 'nombre' => (string) $r['nombre'], 'email' => $r['email']],
            $stmt->fetchAll()
        );
    }
}
