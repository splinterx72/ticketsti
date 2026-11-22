<?php

declare(strict_types=1);

namespace App\Services;

use App\Core\ValidationException;
use DateTimeImmutable;
use PDO;

/**
 * Garantías de hardware (CLAUDE.md, sección 3.E).
 *
 * Una póliza cubre el equipo, el monitor 1 o el monitor 2 de un registro de
 * `equipos`. Estados: vigente, en_reclamacion, vencida. Las vigentes cuya
 * fecha ya pasó se marcan como vencidas automáticamente al consultarlas
 * (no se necesita cron en el hosting compartido).
 */
final class GarantiaService
{
    public const ELEMENTOS = [
        'equipo'    => 'Equipo (CPU / laptop)',
        'monitor_1' => 'Monitor 1',
        'monitor_2' => 'Monitor 2',
    ];

    public const ESTADOS = [
        'vigente'        => 'Vigente',
        'en_reclamacion' => 'En reclamación',
        'vencida'        => 'Vencida',
    ];

    /** Días de anticipación para "por vencer". */
    public const DIAS_POR_VENCER = 60;

    public function __construct(private readonly PDO $pdo)
    {
    }

    public function refreshVencidas(): void
    {
        $this->pdo->exec("UPDATE garantias SET estado = 'vencida' WHERE estado = 'vigente' AND fecha_vencimiento < CURDATE()");
    }

    /** @return array<string, int> */
    public function resumen(): array
    {
        $this->refreshVencidas();
        $row = $this->pdo->query(
            "SELECT
                COALESCE(SUM(estado = 'vigente'), 0) AS vigentes,
                COALESCE(SUM(estado = 'vigente' AND fecha_vencimiento <= CURDATE() + INTERVAL " . self::DIAS_POR_VENCER . " DAY), 0) AS por_vencer,
                COALESCE(SUM(estado = 'en_reclamacion'), 0) AS en_reclamacion,
                COALESCE(SUM(estado = 'vencida'), 0) AS vencidas,
                COUNT(*) AS todas
               FROM garantias"
        )->fetch();
        return array_map('intval', $row ?: []);
    }

    /** @return array<string, mixed>|null */
    public function find(int $id): ?array
    {
        $stmt = $this->pdo->prepare(
            'SELECT g.*, e.serie, e.marca, e.modelo, e.serie_monitor1, e.serie_monitor2
               FROM garantias g JOIN equipos e ON e.id = g.equipo_id
              WHERE g.id = ?'
        );
        $stmt->execute([$id]);
        return $stmt->fetch() ?: null;
    }

    /** @return list<array<string, mixed>> */
    public function deEquipo(int $equipoId): array
    {
        $this->refreshVencidas();
        $stmt = $this->pdo->prepare(
            "SELECT * FROM garantias WHERE equipo_id = ?
              ORDER BY FIELD(estado, 'en_reclamacion', 'vigente', 'vencida'), fecha_vencimiento DESC"
        );
        $stmt->execute([$equipoId]);
        return $stmt->fetchAll();
    }

    /**
     * Garantías activas (vigente / en reclamación) de un equipo, para mostrar
     * la cobertura al atender un ticket.
     *
     * @return list<array<string, mixed>>
     */
    public function activasDeEquipo(int $equipoId): array
    {
        return array_values(array_filter(
            $this->deEquipo($equipoId),
            static fn (array $g): bool => $g['estado'] !== 'vencida'
        ));
    }

    /**
     * Resuelve el equipo a partir de una serie de CPU o de monitor.
     *
     * @return array{equipo: array<string, mixed>, elemento: ?string}|null
     */
    public function buscarPorSerie(string $serie): ?array
    {
        $serie = strtoupper(trim($serie));
        if ($serie === '') {
            return null;
        }
        $stmt = $this->pdo->prepare(
            'SELECT id, serie, marca, modelo, serie_monitor1, serie_monitor2
               FROM equipos
              WHERE serie = ? OR serie_monitor1 = ? OR serie_monitor2 = ?
              ORDER BY (serie = ?) DESC, vigente_en_geca DESC
              LIMIT 1'
        );
        $stmt->execute([$serie, $serie, $serie, $serie]);
        $eq = $stmt->fetch();
        if (!$eq) {
            return null;
        }
        $elemento = match (true) {
            strcasecmp((string) $eq['serie'], $serie) === 0                   => 'equipo',
            strcasecmp((string) ($eq['serie_monitor1'] ?? ''), $serie) === 0 => 'monitor_1',
            strcasecmp((string) ($eq['serie_monitor2'] ?? ''), $serie) === 0 => 'monitor_2',
            default                                                           => null,
        };
        return ['equipo' => $eq, 'elemento' => $elemento];
    }

    /**
     * Alta o edición.
     *
     * @param array<string, string> $in
     */
    public function save(?int $id, array $in): int
    {
        $errors = [];

        $encontrado = $this->buscarPorSerie($in['serie'] ?? '');
        if ($encontrado === null) {
            $errors[] = 'No existe ningún equipo o monitor con esa serie en el inventario.';
        }
        $equipo = $encontrado['equipo'] ?? null;

        $elemento = $in['elemento_cubierto'] ?? '';
        if (!array_key_exists($elemento, self::ELEMENTOS)) {
            $errors[] = 'Indica qué cubre la garantía.';
        } elseif ($equipo !== null) {
            if ($elemento === 'monitor_1' && empty($equipo['serie_monitor1'])) {
                $errors[] = 'Ese equipo no tiene Monitor 1 registrado en el GECA.';
            }
            if ($elemento === 'monitor_2' && empty($equipo['serie_monitor2'])) {
                $errors[] = 'Ese equipo no tiene Monitor 2 registrado en el GECA.';
            }
        }

        $proveedor = trim((string) preg_replace('/\s+/u', ' ', $in['proveedor'] ?? ''));
        if (mb_strlen($proveedor) < 2 || mb_strlen($proveedor) > 120) {
            $errors[] = 'Captura el proveedor (2 a 120 caracteres).';
        }
        $poliza = trim($in['numero_poliza_contrato'] ?? '');
        if (mb_strlen($poliza) > 80) {
            $errors[] = 'El número de póliza o contrato no puede exceder 80 caracteres.';
        }

        $inicio = self::date($in['fecha_inicio'] ?? '');
        $fin = self::date($in['fecha_vencimiento'] ?? '');
        if ($inicio === null) {
            $errors[] = 'Captura una fecha de inicio válida.';
        }
        if ($fin === null) {
            $errors[] = 'Captura una fecha de vencimiento válida.';
        }
        if ($inicio !== null && $fin !== null && $fin < $inicio) {
            $errors[] = 'La fecha de vencimiento no puede ser anterior a la de inicio.';
        }

        $estado = $in['estado'] ?? 'vigente';
        if (!array_key_exists($estado, self::ESTADOS)) {
            $errors[] = 'Estado inválido.';
        }
        $notas = trim(str_replace(["\r\n", "\r"], "\n", $in['notas'] ?? ''));
        if (mb_strlen($notas) > 2000) {
            $errors[] = 'Las notas no pueden exceder 2000 caracteres.';
        }

        if ($errors !== []) {
            throw new ValidationException($errors);
        }

        // Una garantía "vigente" con fecha pasada se guarda como vencida.
        if ($estado === 'vigente' && $fin < (new DateTimeImmutable('today'))->format('Y-m-d')) {
            $estado = 'vencida';
        }

        $params = [
            (int) $equipo['id'], $elemento, $proveedor, $poliza !== '' ? $poliza : null,
            $inicio, $fin, $estado, $notas !== '' ? $notas : null,
        ];

        if ($id === null) {
            $stmt = $this->pdo->prepare(
                'INSERT INTO garantias (equipo_id, elemento_cubierto, proveedor, numero_poliza_contrato,
                                        fecha_inicio, fecha_vencimiento, estado, notas)
                 VALUES (?, ?, ?, ?, ?, ?, ?, ?)'
            );
            $stmt->execute($params);
            return (int) $this->pdo->lastInsertId();
        }

        $stmt = $this->pdo->prepare(
            'UPDATE garantias
                SET equipo_id = ?, elemento_cubierto = ?, proveedor = ?, numero_poliza_contrato = ?,
                    fecha_inicio = ?, fecha_vencimiento = ?, estado = ?, notas = ?
              WHERE id = ?'
        );
        $stmt->execute([...$params, $id]);
        return $id;
    }

    public function delete(int $id): void
    {
        $this->pdo->prepare('DELETE FROM garantias WHERE id = ?')->execute([$id]);
    }

    /** Días restantes (negativo si ya venció). */
    public static function diasRestantes(string $fechaVencimiento): int
    {
        $hoy = new DateTimeImmutable('today');
        $fin = new DateTimeImmutable($fechaVencimiento);
        return (int) $hoy->diff($fin)->format('%r%a');
    }

    private static function date(string $value): ?string
    {
        $value = trim($value);
        if ($value === '') {
            return null;
        }
        foreach (['Y-m-d', 'd/m/Y'] as $format) {
            $d = DateTimeImmutable::createFromFormat('!' . $format, $value);
            $err = DateTimeImmutable::getLastErrors();
            if ($d !== false && ($err === false || ($err['warning_count'] === 0 && $err['error_count'] === 0))) {
                $y = (int) $d->format('Y');
                return ($y >= 2000 && $y <= 2100) ? $d->format('Y-m-d') : null;
            }
        }
        return null;
    }
}
