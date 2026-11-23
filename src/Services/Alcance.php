<?php

declare(strict_types=1);

namespace App\Services;

use App\Core\Roles;
use PDO;

/**
 * "¿Qué instalaciones le tocan a este usuario hoy?"
 *
 * La responsabilidad es por instalación (spec 7.3):
 *  - admin_ti: todas.
 *  - supervisor: sus instalaciones vigentes (titular o suplencia) más las que
 *    tenga como agente.
 *  - agente_ti: sus asignaciones vigentes (base y préstamo).
 * Una asignación está vigente si (desde IS NULL OR desde <= fecha) y
 * (hasta IS NULL OR hasta >= fecha); el día "hasta" cuenta completo.
 */
final class Alcance
{
    /** @var array<string, list<int>> */
    private array $cache = [];

    public function __construct(private readonly PDO $pdo)
    {
    }

    private const VIGENTE = '(x.desde IS NULL OR x.desde <= :f) AND (x.hasta IS NULL OR x.hasta >= :f2)';

    /** @return list<int> */
    public function instalacionesDe(int $usuarioId, ?string $fecha = null): array
    {
        $fecha ??= date('Y-m-d');
        $key = $usuarioId . '|' . $fecha;
        if (isset($this->cache[$key])) {
            return $this->cache[$key];
        }

        $stmt = $this->pdo->prepare('SELECT rol FROM usuarios WHERE id = ?');
        $stmt->execute([$usuarioId]);
        $rol = (string) $stmt->fetchColumn();

        if ($rol === 'admin_ti') {
            $ids = $this->pdo->query('SELECT id FROM instalaciones WHERE activo = 1 ORDER BY id')->fetchAll(PDO::FETCH_COLUMN);
        } else {
            $sql = 'SELECT x.instalacion_id FROM agente_instalaciones x WHERE x.agente_id = :u AND ' . self::VIGENTE;
            if ($rol === 'supervisor') {
                $sql .= ' UNION SELECT s.instalacion_id FROM supervisor_instalaciones s WHERE s.supervisor_id = :u2'
                    . ' AND (s.desde IS NULL OR s.desde <= :f3) AND (s.hasta IS NULL OR s.hasta >= :f4)';
            }
            $stmt = $this->pdo->prepare($sql);
            $params = ['u' => $usuarioId, 'f' => $fecha, 'f2' => $fecha];
            if ($rol === 'supervisor') {
                $params += ['u2' => $usuarioId, 'f3' => $fecha, 'f4' => $fecha];
            }
            $stmt->execute($params);
            $ids = $stmt->fetchAll(PDO::FETCH_COLUMN);
        }

        $ids = array_values(array_unique(array_map('intval', $ids)));
        sort($ids);
        return $this->cache[$key] = $ids;
    }

    /** @return list<int> instalaciones base vigentes (sin préstamos) */
    public function instalacionesBase(int $agenteId, ?string $fecha = null): array
    {
        $fecha ??= date('Y-m-d');
        $stmt = $this->pdo->prepare(
            "SELECT DISTINCT x.instalacion_id FROM agente_instalaciones x
              WHERE x.agente_id = :u AND x.tipo = 'base' AND " . self::VIGENTE . ' ORDER BY x.instalacion_id'
        );
        $stmt->execute(['u' => $agenteId, 'f' => $fecha, 'f2' => $fecha]);
        return array_map('intval', $stmt->fetchAll(PDO::FETCH_COLUMN));
    }

    /** @return list<array{id: int, nombre: string, email: ?string}> */
    public function supervisoresDe(int $instalacionId, ?string $fecha = null): array
    {
        $fecha ??= date('Y-m-d');
        $stmt = $this->pdo->prepare(
            'SELECT DISTINCT u.id, u.nombre, u.email
               FROM supervisor_instalaciones x JOIN usuarios u ON u.id = x.supervisor_id
              WHERE x.instalacion_id = :i AND u.activo = 1 AND ' . self::VIGENTE . '
              ORDER BY u.nombre'
        );
        $stmt->execute(['i' => $instalacionId, 'f' => $fecha, 'f2' => $fecha]);
        return array_map(
            static fn (array $r): array => ['id' => (int) $r['id'], 'nombre' => (string) $r['nombre'], 'email' => $r['email']],
            $stmt->fetchAll()
        );
    }

    /**
     * Agentes (y supervisores que operan como agentes) con asignación vigente
     * en alguna instalación del supervisor. "prestado" = llegó por préstamo.
     *
     * @return list<array{id: int, nombre: string, email: ?string, prestado: bool}>
     */
    public function agentesDe(int $supervisorId, ?string $fecha = null): array
    {
        $fecha ??= date('Y-m-d');
        $instalaciones = $this->instalacionesSupervisadas($supervisorId, $fecha);
        if ($instalaciones === []) {
            return [];
        }
        $in = implode(',', array_fill(0, count($instalaciones), '?'));
        $stmt = $this->pdo->prepare(
            "SELECT u.id, u.nombre, u.email, MIN(x.tipo = 'prestamo') AS solo_prestamo
               FROM agente_instalaciones x JOIN usuarios u ON u.id = x.agente_id
              WHERE x.instalacion_id IN ({$in}) AND u.activo = 1 AND u.id <> ?
                AND (x.desde IS NULL OR x.desde <= ?) AND (x.hasta IS NULL OR x.hasta >= ?)
              GROUP BY u.id, u.nombre, u.email
              ORDER BY u.nombre"
        );
        $stmt->execute([...$instalaciones, $supervisorId, $fecha, $fecha]);
        return array_map(
            static fn (array $r): array => [
                'id' => (int) $r['id'], 'nombre' => (string) $r['nombre'], 'email' => $r['email'],
                'prestado' => (int) $r['solo_prestamo'] === 1,
            ],
            $stmt->fetchAll()
        );
    }

    /** Instalaciones donde el usuario es supervisor (admin: todas). @return list<int> */
    public function instalacionesSupervisadas(int $supervisorId, ?string $fecha = null): array
    {
        $fecha ??= date('Y-m-d');
        $stmt = $this->pdo->prepare('SELECT rol FROM usuarios WHERE id = ?');
        $stmt->execute([$supervisorId]);
        if ($stmt->fetchColumn() === 'admin_ti') {
            return array_map('intval', $this->pdo->query('SELECT id FROM instalaciones WHERE activo = 1 ORDER BY id')->fetchAll(PDO::FETCH_COLUMN));
        }
        $stmt = $this->pdo->prepare(
            'SELECT DISTINCT x.instalacion_id FROM supervisor_instalaciones x
              WHERE x.supervisor_id = :u AND ' . self::VIGENTE . ' ORDER BY x.instalacion_id'
        );
        $stmt->execute(['u' => $supervisorId, 'f' => $fecha, 'f2' => $fecha]);
        return array_map('intval', $stmt->fetchAll(PDO::FETCH_COLUMN));
    }

    /** @param array<string, mixed> $user */
    public function veTodo(array $user): bool
    {
        return ($user['rol'] ?? '') === 'admin_ti';
    }

    /**
     * @param array<string, mixed> $user
     * @param array<string, mixed> $ticket requiere instalacion_id, agente_asignado_id, usuario_solicitante_id
     */
    public function puedeVerTicket(array $user, array $ticket): bool
    {
        $uid = (int) $user['id'];
        if ((int) ($ticket['usuario_solicitante_id'] ?? 0) === $uid) {
            return true;
        }
        if (!Roles::esTi($user['rol'] ?? null)) {
            return false;
        }
        if ($this->veTodo($user) || (int) ($ticket['agente_asignado_id'] ?? 0) === $uid) {
            return true;
        }
        if ($ticket['instalacion_id'] === null) {
            return true;
        }
        return in_array((int) $ticket['instalacion_id'], $this->instalacionesDe($uid), true);
    }

    /**
     * Fragmento WHERE equivalente a puedeVerTicket() para listados.
     *
     * @param array<string, mixed> $user
     * @return array{0: string, 1: list<mixed>}
     */
    public function filtroTicketsSql(array $user, string $alias = 't'): array
    {
        $a = preg_match('/^[a-z_]+$/', $alias) === 1 ? $alias : 't';
        $uid = (int) $user['id'];
        if (!Roles::esTi($user['rol'] ?? null)) {
            return ["{$a}.usuario_solicitante_id = ?", [$uid]];
        }
        if ($this->veTodo($user)) {
            return ['1 = 1', []];
        }
        $ids = $this->instalacionesDe($uid);
        $cond = ["{$a}.usuario_solicitante_id = ?", "{$a}.agente_asignado_id = ?", "{$a}.instalacion_id IS NULL"];
        $params = [$uid, $uid];
        if ($ids !== []) {
            $cond[] = "{$a}.instalacion_id IN (" . implode(',', array_fill(0, count($ids), '?')) . ')';
            $params = array_merge($params, $ids);
        }
        return ['(' . implode(' OR ', $cond) . ')', $params];
    }
}
