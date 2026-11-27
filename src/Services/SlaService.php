<?php

declare(strict_types=1);

namespace App\Services;

use App\Core\ValidationException;
use App\Services\Mail\Notifier;
use PDO;

/**
 * Tiempos de atención (SLA) por prioridad, en horas naturales.
 *  - Respuesta: de la creación a la primera acción de TI (tickets.atendido_en).
 *  - Resolución: de la creación al cierre (tickets.cerrado_en).
 * Semáforo: en_tiempo / por_vencer (≥ 75 % del plazo) / vencido / cumplido / fuera_de_tiempo.
 * Escalamiento (cron): respuesta vencida → supervisores de la instalación;
 * resolución vencida → coordinadores de la zona (si no hay, Admin TI). Una vez por ticket.
 */
final class SlaService
{
    public const PRIORIDADES = ['critica' => 'Crítica', 'alta' => 'Alta', 'media' => 'Media', 'baja' => 'Baja'];
    public const UMBRAL_RIESGO = 0.75;
    private const ESTADO_REVISION = 'sla_ultima_revision';

    /** @var array<string, array{horas_respuesta: int, horas_resolucion: int}>|null */
    private ?array $config = null;

    public function __construct(private readonly PDO $pdo)
    {
    }

    /** @return array<string, array{horas_respuesta: int, horas_resolucion: int}> */
    public function config(): array
    {
        if ($this->config !== null) {
            return $this->config;
        }
        $out = [];
        foreach ($this->pdo->query('SELECT prioridad, horas_respuesta, horas_resolucion FROM sla_prioridades')->fetchAll() as $r) {
            $out[(string) $r['prioridad']] = ['horas_respuesta' => (int) $r['horas_respuesta'], 'horas_resolucion' => (int) $r['horas_resolucion']];
        }
        return $this->config = $out;
    }

    /**
     * @param array<string, array{horas_respuesta?: string|int, horas_resolucion?: string|int}> $in
     * @param array<string, mixed> $admin
     */
    public function guardar(array $in, array $admin): void
    {
        if (($admin['rol'] ?? '') !== 'admin_ti') {
            throw new ValidationException(['Solo el Admin TI configura los tiempos de atención.']);
        }
        $errors = [];
        $valores = [];
        foreach ($in as $prioridad => $v) {
            if (!array_key_exists((string) $prioridad, self::PRIORIDADES)) {
                continue;
            }
            $resp = (int) ($v['horas_respuesta'] ?? 0);
            $resol = (int) ($v['horas_resolucion'] ?? 0);
            $nombre = self::PRIORIDADES[$prioridad];
            if ($resp < 1 || $resp > 720 || $resol < 1 || $resol > 2160) {
                $errors[] = "{$nombre}: las horas deben ser entre 1 y 720 (respuesta) y 1 y 2160 (resolución).";
            } elseif ($resol < $resp) {
                $errors[] = "{$nombre}: el tiempo de resolución no puede ser menor que el de respuesta.";
            }
            $valores[$prioridad] = [$resp, $resol];
        }
        if ($errors !== []) {
            throw new ValidationException($errors);
        }
        $stmt = $this->pdo->prepare(
            'UPDATE sla_prioridades SET horas_respuesta = ?, horas_resolucion = ?, actualizado_por = ? WHERE prioridad = ?'
        );
        foreach ($valores as $prioridad => [$resp, $resol]) {
            $stmt->execute([$resp, $resol, (int) $admin['id'], $prioridad]);
        }
        $this->config = null;
    }

    /**
     * @param array<string, mixed> $t fila de tickets (prioridad, creado_en, atendido_en, cerrado_en; es_cierre opcional)
     * @return array{respuesta: string, resolucion: string, vence_respuesta: string, vence_resolucion: string, avance: float}
     */
    public function estado(array $t, ?int $ahora = null): array
    {
        $ahora ??= time();
        $c = $this->config()[(string) ($t['prioridad'] ?? 'media')] ?? ['horas_respuesta' => 4, 'horas_resolucion' => 24];
        $creado = (int) strtotime((string) $t['creado_en']);
        $venceResp = $creado + $c['horas_respuesta'] * 3600;
        $venceResol = $creado + $c['horas_resolucion'] * 3600;
        $atendido = !empty($t['atendido_en']) ? (int) strtotime((string) $t['atendido_en']) : null;
        $cerrado = !empty($t['cerrado_en']) ? (int) strtotime((string) $t['cerrado_en']) : null;

        $evalua = static function (?int $hecho, int $vence, int $plazo) use ($ahora, $creado): string {
            if ($hecho !== null) {
                return $hecho <= $vence ? 'cumplido' : 'fuera_de_tiempo';
            }
            if ($ahora > $vence) {
                return 'vencido';
            }
            return ($ahora - $creado) >= $plazo * self::UMBRAL_RIESGO ? 'por_vencer' : 'en_tiempo';
        };
        return [
            'respuesta'        => $evalua($atendido ?? $cerrado, $venceResp, $c['horas_respuesta'] * 3600),
            'resolucion'       => $evalua($cerrado, $venceResol, $c['horas_resolucion'] * 3600),
            'vence_respuesta'  => date('Y-m-d H:i:s', $venceResp),
            'vence_resolucion' => date('Y-m-d H:i:s', $venceResol),
            'avance'           => min(1.0, max(0.0, (($cerrado ?? $ahora) - $creado) / max(1, $c['horas_resolucion'] * 3600))),
        ];
    }

    /**
     * Condición SQL (alias t, estados es) para tickets abiertos con un plazo vencido.
     * $tipo: respuesta | resolucion | cualquiera.
     */
    public static function sqlVencido(string $tipo = 'cualquiera'): string
    {
        $resp = "(t.atendido_en IS NULL AND NOW() > t.creado_en + INTERVAL (SELECT s.horas_respuesta FROM sla_prioridades s WHERE s.prioridad = t.prioridad) HOUR)";
        $resol = "(NOW() > t.creado_en + INTERVAL (SELECT s.horas_resolucion FROM sla_prioridades s WHERE s.prioridad = t.prioridad) HOUR)";
        $cond = match ($tipo) {
            'respuesta'  => $resp,
            'resolucion' => $resol,
            default      => "({$resp} OR {$resol})",
        };
        return "(es.es_cierre = 0 AND {$cond})";
    }

    /** Escalamiento de tickets abiertos con plazo vencido. Devuelve cuántos avisos se generaron. */
    public function escalar(): int
    {
        $n = 0;
        $zonas = new ZonaService($this->pdo);
        foreach (['respuesta', 'resolucion'] as $tipo) {
            $rows = $this->pdo->query(
                "SELECT t.id, t.folio, t.titulo, t.prioridad, t.creado_en, t.instalacion_id, t.usuario_solicitante_id,
                        i.nombre AS instalacion, a.nombre AS agente
                   FROM tickets t
                   JOIN estados es ON es.id = t.estado_id
                   LEFT JOIN instalaciones i ON i.id = t.instalacion_id
                   LEFT JOIN usuarios a ON a.id = t.agente_asignado_id
                  WHERE " . self::sqlVencido($tipo) . '
                  ORDER BY t.id LIMIT 500'
            )->fetchAll();
            foreach ($rows as $t) {
                $clave = "sla_{$tipo}:T" . (int) $t['id'];
                $marca = $this->pdo->prepare('INSERT IGNORE INTO recordatorios_enviados (usuario_id, clave) VALUES (?, ?)');
                $marca->execute([(int) $t['usuario_solicitante_id'], $clave]);
                if ($marca->rowCount() !== 1) {
                    continue;
                }
                $inst = $t['instalacion_id'] !== null ? (int) $t['instalacion_id'] : null;
                if ($tipo === 'respuesta') {
                    $destinos = $inst !== null
                        ? array_column((new Alcance($this->pdo))->supervisoresDe($inst), 'email')
                        : [];
                    if ($destinos === [] && $inst !== null) {
                        $destinos = array_column($zonas->coordinadoresDe($inst), 'email');
                    }
                } else {
                    $destinos = $inst !== null ? array_column($zonas->coordinadoresDe($inst), 'email') : [];
                }
                if ($destinos === []) {
                    $destinos = $this->pdo->query("SELECT email FROM usuarios WHERE rol = 'admin_ti' AND activo = 1 AND registrado = 1 AND email IS NOT NULL")
                        ->fetchAll(PDO::FETCH_COLUMN);
                }
                Notifier::escalamientoSla($t, $tipo, array_map('strval', $destinos));
                $n++;
            }
        }
        $this->pdo->prepare('INSERT INTO sistema_estado (clave, valor) VALUES (?, ?) ON DUPLICATE KEY UPDATE valor = VALUES(valor)')
            ->execute([self::ESTADO_REVISION, date('Y-m-d H:i:s')]);
        return $n;
    }

    /** Respaldo sin cron: revisa a lo más cada 15 minutos desde las peticiones web. */
    public function escalarSiPendiente(): void
    {
        $stmt = $this->pdo->prepare('SELECT valor FROM sistema_estado WHERE clave = ?');
        $stmt->execute([self::ESTADO_REVISION]);
        $ultima = $stmt->fetchColumn();
        if ($ultima !== false && strtotime((string) $ultima) > time() - 900) {
            return;
        }
        $lock = 'sla_' . md5((string) $this->pdo->query('SELECT DATABASE()')->fetchColumn());
        if ((int) $this->pdo->query('SELECT GET_LOCK(' . $this->pdo->quote($lock) . ', 0)')->fetchColumn() !== 1) {
            return;
        }
        try {
            $this->escalar();
        } catch (\Throwable $e) {
            error_log('[SLA] ' . $e->getMessage());
        } finally {
            $this->pdo->query('SELECT RELEASE_LOCK(' . $this->pdo->quote($lock) . ')');
        }
    }
}
