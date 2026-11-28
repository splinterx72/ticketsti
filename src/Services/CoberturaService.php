<?php

declare(strict_types=1);

namespace App\Services;

use App\Core\ValidationException;
use PDO;

/**
 * Cobertura de agentes por ubicación y pausas (las decide el supervisor).
 *
 * - `agente_ubicaciones`: qué ubicaciones de una instalación atiende el agente;
 *   sin filas en esa instalación = todas.
 * - `agente_pausas`: pausa en una ubicación o en toda la instalación
 *   (ubicacion_fisica_id NULL), vigente hasta su fecha o hasta reanudarla.
 *
 * El asignador (`AsignadorService::candidatos`) excluye al agente de los
 * tickets de ubicaciones que no atiende o donde está en pausa.
 */
final class CoberturaService
{
    private static ?bool $tablas = null;

    public function __construct(private readonly PDO $pdo)
    {
    }

    /** ¿Ya se importó la migración? Sin ella la asignación funciona como antes. */
    public function disponible(): bool
    {
        if (self::$tablas === null) {
            $stmt = $this->pdo->query(
                "SELECT COUNT(*) FROM information_schema.TABLES
                  WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME IN ('agente_ubicaciones', 'agente_pausas')"
            );
            self::$tablas = (int) $stmt->fetchColumn() === 2;
        }
        return self::$tablas;
    }

    /**
     * Condición SQL sobre el agente `u.id` para un ticket de la instalación
     * `:cob_inst` en la ubicación `:cob_ubf` (puede ser NULL).
     *
     * @return array{0: string, 1: array<string, mixed>}
     */
    public function condicionSql(int $instalacionId, ?int $ubicacionId): array
    {
        if (!$this->disponible()) {
            return ['1 = 1', []];
        }
        $sql = "(NOT EXISTS (SELECT 1 FROM agente_ubicaciones au JOIN ubicaciones_fisicas uf ON uf.id = au.ubicacion_fisica_id
                              WHERE au.agente_id = u.id AND uf.instalacion_id = :cob_inst1)
                 OR :cob_ubf1 IS NULL
                 OR EXISTS (SELECT 1 FROM agente_ubicaciones au2 WHERE au2.agente_id = u.id AND au2.ubicacion_fisica_id = :cob_ubf2))
            AND NOT EXISTS (SELECT 1 FROM agente_pausas ap
                             WHERE ap.agente_id = u.id AND ap.instalacion_id = :cob_inst2
                               AND (ap.ubicacion_fisica_id IS NULL OR ap.ubicacion_fisica_id = :cob_ubf3)
                               AND (ap.hasta IS NULL OR ap.hasta >= CURDATE()))";
        return [$sql, [
            'cob_inst1' => $instalacionId, 'cob_inst2' => $instalacionId,
            'cob_ubf1' => $ubicacionId, 'cob_ubf2' => $ubicacionId, 'cob_ubf3' => $ubicacionId,
        ]];
    }

    /**
     * Agentes vigentes de la instalación con sus ubicaciones y pausas.
     *
     * @return list<array{id: int, nombre: string, prestamo: bool, ubicaciones: list<int>, pausas: list<array<string, mixed>>}>
     */
    public function resumen(int $instalacionId): array
    {
        $stmt = $this->pdo->prepare(
            "SELECT u.id, u.nombre, MIN(ai.tipo = 'prestamo') AS prestamo
               FROM agente_instalaciones ai JOIN usuarios u ON u.id = ai.agente_id
              WHERE ai.instalacion_id = ? AND u.activo = 1
                AND (ai.desde IS NULL OR ai.desde <= CURDATE()) AND (ai.hasta IS NULL OR ai.hasta >= CURDATE())
              GROUP BY u.id, u.nombre ORDER BY u.nombre"
        );
        $stmt->execute([$instalacionId]);
        $agentes = $stmt->fetchAll();
        if ($agentes === []) {
            return [];
        }
        $ubis = $this->pdo->prepare(
            'SELECT au.agente_id, au.ubicacion_fisica_id FROM agente_ubicaciones au
               JOIN ubicaciones_fisicas uf ON uf.id = au.ubicacion_fisica_id WHERE uf.instalacion_id = ?'
        );
        $ubis->execute([$instalacionId]);
        $porAgente = [];
        foreach ($ubis->fetchAll() as $r) {
            $porAgente[(int) $r['agente_id']][] = (int) $r['ubicacion_fisica_id'];
        }
        $pau = $this->pdo->prepare(
            'SELECT ap.*, uf.nombre AS ubicacion, c.nombre AS creado_por_nombre
               FROM agente_pausas ap
               LEFT JOIN ubicaciones_fisicas uf ON uf.id = ap.ubicacion_fisica_id
               LEFT JOIN usuarios c ON c.id = ap.creado_por
              WHERE ap.instalacion_id = ? AND (ap.hasta IS NULL OR ap.hasta >= CURDATE())
              ORDER BY ap.creado_en'
        );
        $pau->execute([$instalacionId]);
        $pausas = [];
        foreach ($pau->fetchAll() as $r) {
            $pausas[(int) $r['agente_id']][] = $r;
        }
        return array_map(static fn (array $a): array => [
            'id' => (int) $a['id'],
            'nombre' => (string) $a['nombre'],
            'prestamo' => (int) $a['prestamo'] === 1,
            'ubicaciones' => $porAgente[(int) $a['id']] ?? [],
            'pausas' => $pausas[(int) $a['id']] ?? [],
        ], $agentes);
    }

    /** @param list<int|string> $ubicaciones vacío = todas las de la instalación */
    public function guardarCobertura(int $agenteId, int $instalacionId, array $ubicaciones): void
    {
        $validas = $this->ubicacionesDeInstalacion($instalacionId);
        $ids = array_values(array_unique(array_filter(array_map('intval', $ubicaciones), static fn (int $u): bool => in_array($u, $validas, true))));
        $this->pdo->beginTransaction();
        try {
            $this->pdo->prepare(
                'DELETE au FROM agente_ubicaciones au JOIN ubicaciones_fisicas uf ON uf.id = au.ubicacion_fisica_id
                  WHERE au.agente_id = ? AND uf.instalacion_id = ?'
            )->execute([$agenteId, $instalacionId]);
            $ins = $this->pdo->prepare('INSERT INTO agente_ubicaciones (agente_id, ubicacion_fisica_id) VALUES (?, ?)');
            foreach ($ids as $u) {
                $ins->execute([$agenteId, $u]);
            }
            $this->pdo->commit();
        } catch (\Throwable $e) {
            $this->pdo->rollBack();
            throw $e;
        }
    }

    public function pausar(int $agenteId, int $instalacionId, ?int $ubicacionId, string $motivo, ?string $hasta, int $actorId): void
    {
        if ($ubicacionId !== null && !in_array($ubicacionId, $this->ubicacionesDeInstalacion($instalacionId), true)) {
            throw new ValidationException(['Elige una ubicación de la instalación.']);
        }
        $motivo = trim(preg_replace('/\s+/u', ' ', $motivo) ?? '');
        if (mb_strlen($motivo) > 255) {
            throw new ValidationException(['El motivo admite hasta 255 caracteres.']);
        }
        $hasta = $hasta !== null && trim($hasta) !== '' ? trim($hasta) : null;
        if ($hasta !== null) {
            $d = \DateTimeImmutable::createFromFormat('!Y-m-d', $hasta);
            if ($d === false || $d->format('Y-m-d') !== $hasta || $hasta < date('Y-m-d')) {
                throw new ValidationException(['La fecha "hasta" debe ser hoy o posterior.']);
            }
        }
        $this->pdo->prepare(
            'INSERT INTO agente_pausas (agente_id, instalacion_id, ubicacion_fisica_id, motivo, hasta, creado_por) VALUES (?, ?, ?, ?, ?, ?)'
        )->execute([$agenteId, $instalacionId, $ubicacionId, $motivo !== '' ? $motivo : null, $hasta, $actorId]);
    }

    /** @return array<string, mixed>|null */
    public function pausa(int $id): ?array
    {
        $stmt = $this->pdo->prepare('SELECT * FROM agente_pausas WHERE id = ?');
        $stmt->execute([$id]);
        return $stmt->fetch() ?: null;
    }

    public function reanudar(int $pausaId): void
    {
        $this->pdo->prepare('DELETE FROM agente_pausas WHERE id = ?')->execute([$pausaId]);
    }

    /** ¿El agente está asignado (vigente) a la instalación? */
    public function esAgenteDe(int $agenteId, int $instalacionId): bool
    {
        $stmt = $this->pdo->prepare(
            'SELECT 1 FROM agente_instalaciones WHERE agente_id = ? AND instalacion_id = ?
                AND (desde IS NULL OR desde <= CURDATE()) AND (hasta IS NULL OR hasta >= CURDATE()) LIMIT 1'
        );
        $stmt->execute([$agenteId, $instalacionId]);
        return (bool) $stmt->fetchColumn();
    }

    /** @return list<int> */
    private function ubicacionesDeInstalacion(int $instalacionId): array
    {
        $stmt = $this->pdo->prepare('SELECT id FROM ubicaciones_fisicas WHERE instalacion_id = ?');
        $stmt->execute([$instalacionId]);
        return array_map('intval', $stmt->fetchAll(PDO::FETCH_COLUMN));
    }
}
