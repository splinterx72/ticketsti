<?php

declare(strict_types=1);

namespace App\Services;

use App\Services\Mail\Notifier;
use PDO;
use Throwable;

/**
 * Recordatorios diarios (spec 7.5 y 7.6): los dispara la Tarea Cron de
 * cPanel (bin/cron.php) y, como respaldo, la primera petición web del día.
 *
 * Cada aviso se registra en recordatorios_enviados con una clave única, así
 * que correr dos veces el mismo día no duplica correos.
 */
final class Recordatorios
{
    private const ESTADO = 'recordatorios_ultima_ejecucion';

    public function __construct(private readonly PDO $pdo)
    {
    }

    /** @return array{enviados: int} */
    public function ejecutar(string $hoy): array
    {
        $enviados = $this->adminLocal($hoy) + $this->tareasPorVencer($hoy);
        $this->pdo->prepare(
            'INSERT INTO sistema_estado (clave, valor) VALUES (?, ?) ON DUPLICATE KEY UPDATE valor = VALUES(valor)'
        )->execute([self::ESTADO, $hoy]);
        return ['enviados' => $enviados];
    }

    /** Respaldo sin cron: la primera petición del día ejecuta la revisión. */
    public function ejecutarSiPendiente(): void
    {
        $hoy = date('Y-m-d');
        $stmt = $this->pdo->prepare('SELECT valor FROM sistema_estado WHERE clave = ?');
        $stmt->execute([self::ESTADO]);
        if ($stmt->fetchColumn() === $hoy) {
            return;
        }
        $lock = 'recordatorios_' . md5((string) $this->pdo->query('SELECT DATABASE()')->fetchColumn());
        if ((int) $this->pdo->query('SELECT GET_LOCK(' . $this->pdo->quote($lock) . ', 0)')->fetchColumn() !== 1) {
            return;
        }
        try {
            $stmt->execute([self::ESTADO]);
            if ($stmt->fetchColumn() !== $hoy) {
                $this->ejecutar($hoy);
            }
        } catch (Throwable $e) {
            error_log('[Recordatorios] ' . $e->getMessage());
        } finally {
            $this->pdo->query('SELECT RELEASE_LOCK(' . $this->pdo->quote($lock) . ')');
        }
    }

    /**
     * A 10 días o menos de vencer (y mientras siga vencida): un aviso diario
     * al usuario y a sus responsables. Responsables de un agente: los
     * supervisores de sus instalaciones BASE; de un supervisor: el Admin TI.
     */
    private function adminLocal(string $hoy): int
    {
        $rows = $this->pdo->query(
            "SELECT id, nombre, email, rol, admin_local_cambiado_en FROM usuarios
              WHERE rol IN ('agente_ti', 'supervisor', 'admin_ti') AND activo = 1 AND registrado = 1"
        )->fetchAll();

        $alcance = new Alcance($this->pdo);
        $enviados = 0;
        foreach ($rows as $u) {
            $dias = AdminLocalService::diasRestantes($u['admin_local_cambiado_en'], $hoy);
            if ($dias === null || $dias > AdminLocalService::DIAS_AVISO) {
                // Sin fecha: se bloquea al entrar y ahí la registra; no hay vencimiento que avisar.
                continue;
            }
            $vence = (string) AdminLocalService::vence($u['admin_local_cambiado_en']);
            $clave = "admin_local:{$vence}:{$dias}";
            if (!$this->marcar((int) $u['id'], $clave)) {
                continue;
            }

            $responsables = [];
            if ($u['rol'] === 'agente_ti') {
                foreach ($alcance->instalacionesBase((int) $u['id'], $hoy) as $inst) {
                    foreach ($alcance->supervisoresDe($inst, $hoy) as $s) {
                        $responsables[] = (string) $s['email'];
                    }
                }
            } else {
                $admins = $this->pdo->prepare(
                    "SELECT email FROM usuarios WHERE rol = 'admin_ti' AND activo = 1 AND registrado = 1 AND id <> ?"
                );
                $admins->execute([(int) $u['id']]);
                $responsables = array_map('strval', $admins->fetchAll(PDO::FETCH_COLUMN));
            }

            Notifier::adminLocal($u, $dias, $vence, $responsables);
            $enviados++;
        }
        return $enviados;
    }

    /** Tareas cuya entrega es mañana y aún no se entregan: un aviso al agente. */
    private function tareasPorVencer(string $hoy): int
    {
        $n = 0;
        foreach ((new TareaService($this->pdo))->vencenManana($hoy) as $row) {
            if ($this->marcar((int) $row['agente_id'], 'tarea_manana:' . (int) $row['id'] . ':' . substr((string) $row['entrega_en'], 0, 10))) {
                Notifier::tareaPorVencer($row);
                $n++;
            }
        }
        return $n;
    }

    /** Registra el aviso; false si ya se había enviado. */
    private function marcar(int $usuarioId, string $clave): bool
    {
        $stmt = $this->pdo->prepare(
            'INSERT IGNORE INTO recordatorios_enviados (usuario_id, clave, enviado_en) VALUES (?, ?, NOW())'
        );
        $stmt->execute([$usuarioId, $clave]);
        return $stmt->rowCount() === 1;
    }
}
