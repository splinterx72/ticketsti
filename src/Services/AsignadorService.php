<?php

declare(strict_types=1);

namespace App\Services;

use App\Core\Roles;
use App\Core\ValidationException;
use PDO;

/**
 * Asignación inteligente de tickets.
 *
 * Candidatos: TI activo y verificado, con asignación vigente (base o préstamo)
 * en la instalación del ticket, que no esté fuera ni de vacaciones, sin
 * bloqueo de admin local y con la especialidad del departamento del ticket
 * (sin especialidades = atiende todo).
 *
 * Puntos (gana el menor): carga abierta ponderada por prioridad
 * (crítica 4, alta 3, media 2, baja 1) + 3 si está "ocupado" − 1 si ya tiene
 * un ticket abierto en el mismo edificio. Empate: al que lleva más tiempo
 * sin recibir un ticket (reparto por turnos).
 *
 * Modo por instalación: manual (nada), sugerida (TI acepta con un clic,
 * por omisión) o automatica (asigna y lo deja en la bitácora).
 */
final class AsignadorService
{
    public const MODOS = ['manual' => 'Manual', 'sugerida' => 'Sugerida', 'automatica' => 'Automática'];
    public const ESTADOS = ['disponible' => 'Disponible', 'ocupado' => 'Ocupado', 'fuera' => 'Fuera (comisión, curso…)', 'vacaciones' => 'Vacaciones'];
    private const PESO_OCUPADO = 3;
    private const BONO_EDIFICIO = 1;

    public function __construct(private readonly PDO $pdo)
    {
    }

    /**
     * Candidatos ordenados (el primero es la sugerencia).
     *
     * @return list<array{id: int, nombre: string, puntos: int, carga: int, motivo: string}>
     */
    public function candidatos(int $ticketId): array
    {
        $stmt = $this->pdo->prepare('SELECT id, instalacion_id, departamento_id, reportada_ubicacion_fisica_id FROM tickets WHERE id = ?');
        $stmt->execute([$ticketId]);
        $t = $stmt->fetch();
        if (!$t || $t['instalacion_id'] === null) {
            return [];
        }
        $vigente = '(ai.desde IS NULL OR ai.desde <= CURDATE()) AND (ai.hasta IS NULL OR ai.hasta >= CURDATE())';
        $estadoVigente = '(ae.hasta IS NULL OR ae.hasta >= CURDATE())';
        $abierto = 'SELECT 1 FROM estados es WHERE es.id = x.estado_id AND es.es_cierre = 0';
        $stmt = $this->pdo->prepare(
            "SELECT u.id, u.nombre, u.rol, u.tipo_cuenta, u.admin_local_cambiado_en,
                    (CASE WHEN ae.estado = 'ocupado' AND {$estadoVigente} THEN 1 ELSE 0 END) AS ocupado,
                    COALESCE((SELECT SUM(CASE x.prioridad WHEN 'critica' THEN 4 WHEN 'alta' THEN 3 WHEN 'media' THEN 2 ELSE 1 END)
                                FROM tickets x WHERE x.agente_asignado_id = u.id AND x.id <> :t1 AND EXISTS ({$abierto})), 0) AS carga,
                    (SELECT COUNT(*) FROM tickets x WHERE x.agente_asignado_id = u.id AND x.id <> :t2 AND EXISTS ({$abierto})
                        AND :ubf IS NOT NULL AND x.reportada_ubicacion_fisica_id = :ubf2) AS mismo_edificio,
                    (SELECT MAX(x.id) FROM tickets x WHERE x.agente_asignado_id = u.id) AS ultimo
               FROM usuarios u
               LEFT JOIN agente_estado ae ON ae.usuario_id = u.id
              WHERE u.activo = 1 AND u.registrado = 1 AND u.rol IN (" . Roles::sqlIn(Roles::TI) . ")
                AND EXISTS (SELECT 1 FROM agente_instalaciones ai WHERE ai.agente_id = u.id AND ai.instalacion_id = :inst AND {$vigente})
                AND (ae.estado IS NULL OR ae.estado NOT IN ('fuera', 'vacaciones') OR NOT {$estadoVigente})
                AND (NOT EXISTS (SELECT 1 FROM agente_departamentos ad WHERE ad.agente_id = u.id)
                     OR EXISTS (SELECT 1 FROM agente_departamentos ad WHERE ad.agente_id = u.id AND ad.departamento_id = :depto))"
        );
        $stmt->execute([
            't1' => $ticketId, 't2' => $ticketId, 'inst' => (int) $t['instalacion_id'], 'depto' => (int) $t['departamento_id'],
            'ubf' => $t['reportada_ubicacion_fisica_id'], 'ubf2' => $t['reportada_ubicacion_fisica_id'],
        ]);

        $lista = [];
        foreach ($stmt->fetchAll() as $r) {
            if (AdminLocalService::bloqueado($r)) {
                continue;
            }
            $edificio = (int) $r['mismo_edificio'] > 0;
            $puntos = (int) $r['carga'] + ((int) $r['ocupado'] === 1 ? self::PESO_OCUPADO : 0) - ($edificio ? self::BONO_EDIFICIO : 0);
            $lista[] = [
                'id' => (int) $r['id'], 'nombre' => (string) $r['nombre'], 'puntos' => $puntos, 'carga' => (int) $r['carga'],
                'ocupado' => (int) $r['ocupado'] === 1, 'edificio' => $edificio, 'ultimo' => (int) ($r['ultimo'] ?? 0),
            ];
        }
        usort($lista, static fn (array $a, array $b): int => [$a['puntos'], $a['ultimo'], $a['id']] <=> [$b['puntos'], $b['ultimo'], $b['id']]);

        foreach ($lista as $i => $c) {
            $partes = ['carga abierta de ' . $c['carga'] . ' pts'];
            if ($c['edificio']) {
                $partes[] = 'ya atiende en ese edificio';
            }
            if ($c['ocupado']) {
                $partes[] = 'marcado como ocupado';
            }
            if ($i === 0 && isset($lista[1]) && $lista[1]['puntos'] === $c['puntos']) {
                $partes[] = 'empate: lleva más tiempo sin recibir';
            }
            $lista[$i]['motivo'] = implode(', ', $partes);
            unset($lista[$i]['ultimo']);
        }
        return $lista;
    }

    /** @return array{id: int, nombre: string, puntos: int, carga: int, motivo: string}|null */
    public function sugerir(int $ticketId): ?array
    {
        return $this->candidatos($ticketId)[0] ?? null;
    }

    /**
     * Aplica el modo de la instalación al ticket recién creado.
     *
     * @return int|null id del agente asignado automáticamente (para avisarle), o null
     */
    public function alCrear(int $ticketId): ?int
    {
        $stmt = $this->pdo->prepare(
            "SELECT COALESCE(i.modo_asignacion, 'sugerida') FROM tickets t LEFT JOIN instalaciones i ON i.id = t.instalacion_id WHERE t.id = ?"
        );
        $stmt->execute([$ticketId]);
        $modo = (string) $stmt->fetchColumn();
        if ($modo === 'manual' || $modo === '') {
            return null;
        }
        $s = $this->sugerir($ticketId);
        if ($s === null) {
            return null;
        }
        if ($modo === 'sugerida') {
            $this->pdo->prepare('UPDATE tickets SET sugerido_agente_id = ? WHERE id = ?')->execute([$s['id'], $ticketId]);
            return null;
        }
        $this->pdo->prepare('UPDATE tickets SET sugerido_agente_id = ?, agente_asignado_id = ? WHERE id = ? AND agente_asignado_id IS NULL')
            ->execute([$s['id'], $s['id'], $ticketId]);
        $this->pdo->prepare('INSERT INTO comentarios (ticket_id, usuario_id, mensaje, es_nota_interna) VALUES (?, ?, ?, 0)')
            ->execute([$ticketId, $s['id'], '[Sistema] Asignado automáticamente a ' . $s['nombre'] . ' (' . $s['motivo'] . ').']);
        return $s['id'];
    }

    /**
     * Disponibilidad y especialidades: el propio agente, el supervisor o
     * coordinador de alguna de sus instalaciones vigentes, o el Admin TI.
     *
     * @param array<string, mixed> $user
     */
    public function puedeGestionar(array $user, int $agenteId): bool
    {
        if ((int) $user['id'] === $agenteId || ($user['rol'] ?? '') === 'admin_ti') {
            return true;
        }
        if (!in_array($user['rol'] ?? '', Roles::SUPERVISION, true)) {
            return false;
        }
        return in_array($agenteId, array_column((new Alcance($this->pdo))->agentesDe((int) $user['id']), 'id'), true);
    }

    public function guardarEstado(int $agenteId, string $estado, ?string $hasta, int $actorId): void
    {
        if (!array_key_exists($estado, self::ESTADOS)) {
            throw new ValidationException(['Elige un estado válido.']);
        }
        $hasta = $hasta !== null && trim($hasta) !== '' ? trim($hasta) : null;
        if ($hasta !== null) {
            $d = \DateTimeImmutable::createFromFormat('!Y-m-d', $hasta);
            if ($d === false || $d->format('Y-m-d') !== $hasta || $hasta < date('Y-m-d')) {
                throw new ValidationException(['La fecha "hasta" debe ser hoy o posterior.']);
            }
        }
        if ($estado === 'disponible') {
            $hasta = null;
        }
        $this->pdo->prepare(
            'INSERT INTO agente_estado (usuario_id, estado, hasta, actualizado_por) VALUES (?, ?, ?, ?)
             ON DUPLICATE KEY UPDATE estado = VALUES(estado), hasta = VALUES(hasta), actualizado_por = VALUES(actualizado_por)'
        )->execute([$agenteId, $estado, $hasta, $actorId]);
    }

    /** @return array{estado: string, hasta: ?string} estado efectivo hoy */
    public function estadoDe(int $agenteId): array
    {
        $stmt = $this->pdo->prepare('SELECT estado, hasta FROM agente_estado WHERE usuario_id = ?');
        $stmt->execute([$agenteId]);
        $r = $stmt->fetch();
        if (!$r || ($r['hasta'] !== null && $r['hasta'] < date('Y-m-d'))) {
            return ['estado' => 'disponible', 'hasta' => null];
        }
        return ['estado' => (string) $r['estado'], 'hasta' => $r['hasta']];
    }

    /** @param list<int|string> $departamentos */
    public function guardarEspecialidades(int $agenteId, array $departamentos): void
    {
        $validos = array_map(static fn (array $d): int => (int) $d['id'], Catalog::departamentos());
        $ids = array_values(array_unique(array_filter(array_map('intval', $departamentos), static fn (int $d): bool => in_array($d, $validos, true))));
        $this->pdo->prepare('DELETE FROM agente_departamentos WHERE agente_id = ?')->execute([$agenteId]);
        $ins = $this->pdo->prepare('INSERT INTO agente_departamentos (agente_id, departamento_id) VALUES (?, ?)');
        foreach ($ids as $d) {
            $ins->execute([$agenteId, $d]);
        }
    }

    /** @return list<int> */
    public function especialidadesDe(int $agenteId): array
    {
        $stmt = $this->pdo->prepare('SELECT departamento_id FROM agente_departamentos WHERE agente_id = ? ORDER BY departamento_id');
        $stmt->execute([$agenteId]);
        return array_map('intval', $stmt->fetchAll(PDO::FETCH_COLUMN));
    }

    public function guardarModo(int $instalacionId, string $modo): void
    {
        if (!array_key_exists($modo, self::MODOS)) {
            throw new ValidationException(['Elige un modo de asignación válido.']);
        }
        $this->pdo->prepare('UPDATE instalaciones SET modo_asignacion = ? WHERE id = ?')->execute([$modo, $instalacionId]);
    }
}
