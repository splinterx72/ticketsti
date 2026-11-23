<?php

declare(strict_types=1);

namespace App\Services;

use App\Core\Roles;
use App\Core\ValidationException;
use DateTimeImmutable;
use PDO;

/**
 * Responsabilidad por instalación (spec 7.3 y 8.1):
 *  - El admin TI asigna supervisores (titular o suplencia) a instalaciones.
 *  - Cada supervisor asigna agentes a SUS instalaciones y presta a sus agentes
 *    a otra instalación con fecha de fin obligatoria.
 */
final class AsignacionService
{
    private Alcance $alcance;

    public function __construct(private readonly PDO $pdo)
    {
        $this->alcance = new Alcance($pdo);
    }

    /** @param array<string, mixed> $actor */
    public function asignarSupervisor(array $actor, int $supervisorId, int $instalacionId, string $tipo, ?string $desde, ?string $hasta): int
    {
        if (($actor['rol'] ?? '') !== 'admin_ti') {
            throw new ValidationException(['Solo el Admin TI asigna supervisores a las instalaciones.']);
        }
        $this->exigirUsuario($supervisorId, ['supervisor', 'admin_ti'], 'El usuario debe tener rol Supervisor.');
        $this->exigirInstalacion($instalacionId);
        if (!in_array($tipo, ['titular', 'suplencia'], true)) {
            throw new ValidationException(['Tipo de asignación inválido.']);
        }
        [$desde, $hasta] = $this->fechas($desde, $hasta, $tipo === 'suplencia');

        $this->pdo->prepare(
            'INSERT INTO supervisor_instalaciones (supervisor_id, instalacion_id, tipo, desde, hasta, creado_por) VALUES (?, ?, ?, ?, ?, ?)'
        )->execute([$supervisorId, $instalacionId, $tipo, $desde, $hasta, (int) $actor['id']]);
        return (int) $this->pdo->lastInsertId();
    }

    /** @param array<string, mixed> $actor */
    public function asignarAgente(array $actor, int $agenteId, int $instalacionId): int
    {
        $this->exigirSupervisa($actor, $instalacionId);
        $this->exigirUsuario($agenteId, Roles::TI, 'Solo se asignan cuentas de TI (agente o supervisor).');

        $existe = $this->pdo->prepare(
            "SELECT id FROM agente_instalaciones WHERE agente_id = ? AND instalacion_id = ? AND tipo = 'base'
                AND (hasta IS NULL OR hasta >= CURDATE())"
        );
        $existe->execute([$agenteId, $instalacionId]);
        if ($id = $existe->fetchColumn()) {
            return (int) $id;
        }
        $this->pdo->prepare(
            "INSERT INTO agente_instalaciones (agente_id, instalacion_id, tipo, creado_por) VALUES (?, ?, 'base', ?)"
        )->execute([$agenteId, $instalacionId, (int) $actor['id']]);
        return (int) $this->pdo->lastInsertId();
    }

    /** @param array<string, mixed> $actor */
    public function prestarAgente(array $actor, int $agenteId, int $instalacionDestino, ?string $desde, ?string $hasta): int
    {
        $base = $this->alcance->instalacionesBase($agenteId);
        $suyas = $this->alcance->instalacionesSupervisadas((int) $actor['id']);
        if (array_intersect($base, $suyas) === []) {
            throw new ValidationException(['Solo puedes prestar agentes de tus propias instalaciones.']);
        }
        $this->exigirInstalacion($instalacionDestino);
        if (in_array($instalacionDestino, $base, true)) {
            throw new ValidationException(['El agente ya pertenece a esa instalación.']);
        }
        if ($hasta === null || trim($hasta) === '') {
            throw new ValidationException(['Indica hasta qué fecha dura el préstamo (campo "hasta").']);
        }
        [$desde, $hasta] = $this->fechas($desde ?: date('Y-m-d'), $hasta, true);

        $this->pdo->prepare(
            "INSERT INTO agente_instalaciones (agente_id, instalacion_id, tipo, desde, hasta, creado_por) VALUES (?, ?, 'prestamo', ?, ?, ?)"
        )->execute([$agenteId, $instalacionDestino, $desde, $hasta, (int) $actor['id']]);
        return (int) $this->pdo->lastInsertId();
    }

    /**
     * Termina una asignación (no borra el historial: fija "hasta" en ayer).
     *
     * @param array<string, mixed> $actor
     */
    public function quitar(array $actor, string $tabla, int $id): void
    {
        if (!in_array($tabla, ['supervisor_instalaciones', 'agente_instalaciones'], true)) {
            throw new ValidationException(['Asignación inválida.']);
        }
        $stmt = $this->pdo->prepare("SELECT * FROM {$tabla} WHERE id = ?");
        $stmt->execute([$id]);
        $row = $stmt->fetch();
        if (!$row) {
            throw new ValidationException(['La asignación no existe.']);
        }
        if ($tabla === 'supervisor_instalaciones' && ($actor['rol'] ?? '') !== 'admin_ti') {
            throw new ValidationException(['Solo el Admin TI modifica supervisores.']);
        }
        if ($tabla === 'agente_instalaciones') {
            $suyas = $this->alcance->instalacionesSupervisadas((int) $actor['id']);
            $base = $this->alcance->instalacionesBase((int) $row['agente_id']);
            $puede = in_array((int) $row['instalacion_id'], $suyas, true)
                || ($row['tipo'] === 'prestamo' && array_intersect($base, $suyas) !== []);
            if (!$puede) {
                throw new ValidationException(['No puedes modificar esa asignación.']);
            }
        }
        $this->pdo->prepare("UPDATE {$tabla} SET hasta = ? WHERE id = ?")
            ->execute([date('Y-m-d', strtotime('-1 day')), $id]);
    }

    /**
     * Asignaciones vigentes o futuras para mostrar en pantalla.
     *
     * @param list<int> $instalacionIds
     * @return list<array<string, mixed>>
     */
    public function vigentes(string $tabla, array $instalacionIds): array
    {
        if (!in_array($tabla, ['supervisor_instalaciones', 'agente_instalaciones'], true) || $instalacionIds === []) {
            return [];
        }
        $col = $tabla === 'supervisor_instalaciones' ? 'supervisor_id' : 'agente_id';
        $in = implode(',', array_fill(0, count($instalacionIds), '?'));
        $stmt = $this->pdo->prepare(
            "SELECT x.*, u.nombre, u.ficha, u.rol, i.nombre AS instalacion
               FROM {$tabla} x JOIN usuarios u ON u.id = x.{$col} JOIN instalaciones i ON i.id = x.instalacion_id
              WHERE x.instalacion_id IN ({$in}) AND (x.hasta IS NULL OR x.hasta >= CURDATE())
              ORDER BY i.nombre, x.tipo, u.nombre"
        );
        $stmt->execute($instalacionIds);
        return $stmt->fetchAll();
    }

    /** Préstamos vigentes de los agentes base de estas instalaciones hacia otras. @return list<array<string, mixed>> */
    public function prestamosSalientes(array $instalacionIds): array
    {
        if ($instalacionIds === []) {
            return [];
        }
        $in = implode(',', array_fill(0, count($instalacionIds), '?'));
        $stmt = $this->pdo->prepare(
            "SELECT p.*, u.nombre, u.ficha, i.nombre AS instalacion
               FROM agente_instalaciones p
               JOIN usuarios u ON u.id = p.agente_id
               JOIN instalaciones i ON i.id = p.instalacion_id
              WHERE p.tipo = 'prestamo' AND p.hasta >= CURDATE()
                AND p.agente_id IN (SELECT b.agente_id FROM agente_instalaciones b
                                     WHERE b.tipo = 'base' AND b.instalacion_id IN ({$in})
                                       AND (b.hasta IS NULL OR b.hasta >= CURDATE()))
              ORDER BY p.hasta"
        );
        $stmt->execute($instalacionIds);
        return $stmt->fetchAll();
    }

    // ------------------------------------------------------------------

    /** @param array<string, mixed> $actor */
    private function exigirSupervisa(array $actor, int $instalacionId): void
    {
        if (!in_array($instalacionId, $this->alcance->instalacionesSupervisadas((int) $actor['id']), true)) {
            throw new ValidationException(['Solo puedes asignar agentes a tus propias instalaciones.']);
        }
    }

    /** @param list<string> $roles */
    private function exigirUsuario(int $id, array $roles, string $mensaje): void
    {
        $stmt = $this->pdo->prepare('SELECT rol FROM usuarios WHERE id = ? AND activo = 1 AND registrado = 1');
        $stmt->execute([$id]);
        if (!in_array($stmt->fetchColumn(), $roles, true)) {
            throw new ValidationException([$mensaje]);
        }
    }

    private function exigirInstalacion(int $id): void
    {
        $stmt = $this->pdo->prepare('SELECT 1 FROM instalaciones WHERE id = ? AND activo = 1');
        $stmt->execute([$id]);
        if (!$stmt->fetchColumn()) {
            throw new ValidationException(['La instalación no existe o está inactiva.']);
        }
    }

    /** @return array{0: ?string, 1: ?string} */
    private function fechas(?string $desde, ?string $hasta, bool $requiereHasta): array
    {
        $parse = static function (?string $v): ?string {
            if ($v === null || trim($v) === '') {
                return null;
            }
            $d = DateTimeImmutable::createFromFormat('!Y-m-d', trim($v));
            if ($d === false || $d->format('Y-m-d') !== trim($v)) {
                throw new ValidationException(['Fecha inválida: ' . $v]);
            }
            return $d->format('Y-m-d');
        };
        $d = $parse($desde);
        $h = $parse($hasta);
        if ($requiereHasta && $h === null) {
            throw new ValidationException(['Indica hasta qué fecha dura la asignación (campo "hasta").']);
        }
        if ($h !== null && $h < date('Y-m-d')) {
            throw new ValidationException(['La fecha "hasta" ya pasó.']);
        }
        if ($d !== null && $h !== null && $h < $d) {
            throw new ValidationException(['La fecha "hasta" no puede ser anterior a "desde".']);
        }
        return [$d, $h];
    }
}
