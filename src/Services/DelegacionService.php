<?php

declare(strict_types=1);

namespace App\Services;

use App\Core\Roles;
use App\Core\ValidationException;
use PDO;

/**
 * Delegación de tickets: un jefe autoriza a una o varias personas (p. ej. su
 * secretaria) para levantar tickets a su nombre.
 *
 *  - Autoriza el propio jefe. Si el jefe aún no activa su cuenta, puede
 *    registrarla supervisión de TI; el Admin TI siempre.
 *  - La persona autorizada debe tener su cuenta activada (necesita entrar).
 *  - Vigente = sin fecha "hasta" o hasta hoy inclusive.
 *  - ver_todos: además ve y comenta todos los tickets del jefe; si no, solo
 *    los que ella levantó. Quien levantó un ticket siempre lo sigue viendo.
 *  - No da acceso a BitLocker ni a nada más del jefe.
 */
final class DelegacionService
{
    private const VIGENTE = '(d.hasta IS NULL OR d.hasta >= CURDATE())';

    public function __construct(private readonly PDO $pdo)
    {
    }

    /** @param array<string, mixed> $actor */
    public function autorizar(array $actor, int $jefeId, string $fichaDelegado, bool $verTodos, ?string $hasta): void
    {
        $jefe = $this->usuario('id', $jefeId);
        if ($jefe === null) {
            throw new ValidationException(['El jefe no existe.']);
        }
        if (!$this->puedeAdministrar($actor, $jefe)) {
            throw new ValidationException(['Solo el jefe puede autorizar a quién levanta tickets a su nombre.']);
        }
        $delegado = $this->usuario('ficha', mb_strtoupper(trim($fichaDelegado)));
        $errors = [];
        if ($delegado === null) {
            $errors[] = 'No existe un trabajador con esa ficha.';
        } elseif ((int) $delegado['id'] === $jefeId) {
            $errors[] = 'No puedes autorizarte a ti mismo.';
        } elseif ((int) $delegado['registrado'] !== 1 || (int) $delegado['activo'] !== 1) {
            $errors[] = 'Esa persona aún no ha activado su cuenta; pídele que se registre primero.';
        }
        $hasta = $hasta !== null && trim($hasta) !== '' ? trim($hasta) : null;
        if ($hasta !== null) {
            $d = \DateTimeImmutable::createFromFormat('!Y-m-d', $hasta);
            if ($d === false || $d->format('Y-m-d') !== $hasta || $hasta < date('Y-m-d')) {
                $errors[] = 'La fecha "hasta" debe ser hoy o posterior.';
            }
        }
        if ($errors !== []) {
            throw new ValidationException($errors);
        }
        $this->pdo->prepare(
            'INSERT INTO delegaciones (jefe_id, delegado_id, ver_todos, hasta, creado_por) VALUES (?, ?, ?, ?, ?)
             ON DUPLICATE KEY UPDATE ver_todos = VALUES(ver_todos), hasta = VALUES(hasta), creado_por = VALUES(creado_por)'
        )->execute([$jefeId, (int) $delegado['id'], $verTodos ? 1 : 0, $hasta, (int) $actor['id']]);
    }

    /** @param array<string, mixed> $actor */
    public function revocar(array $actor, int $delegacionId): void
    {
        $stmt = $this->pdo->prepare('SELECT jefe_id FROM delegaciones WHERE id = ?');
        $stmt->execute([$delegacionId]);
        $jefeId = $stmt->fetchColumn();
        $jefe = $jefeId !== false ? $this->usuario('id', (int) $jefeId) : null;
        if ($jefe === null || !$this->puedeAdministrar($actor, $jefe)) {
            throw new ValidationException(['Esa autorización no existe o no puedes quitarla.']);
        }
        $this->pdo->prepare('DELETE FROM delegaciones WHERE id = ?')->execute([$delegacionId]);
    }

    /**
     * Quién puede administrar las autorizaciones de un jefe.
     *
     * @param array<string, mixed> $actor
     * @param array<string, mixed> $jefe
     */
    public function puedeAdministrar(array $actor, array $jefe): bool
    {
        if ((int) $actor['id'] === (int) $jefe['id'] || ($actor['rol'] ?? '') === 'admin_ti') {
            return true;
        }
        return (int) $jefe['registrado'] !== 1 && in_array($actor['rol'] ?? '', Roles::SUPERVISION, true);
    }

    /** @return list<array<string, mixed>> personas autorizadas por el jefe (vigentes y vencidas) */
    public function deJefe(int $jefeId): array
    {
        $stmt = $this->pdo->prepare(
            'SELECT d.id, d.ver_todos, d.hasta, d.creado_en, ' . self::VIGENTE . ' AS vigente,
                    u.id AS delegado_id, u.nombre AS delegado_nombre, u.ficha AS delegado_ficha, u.email AS delegado_email,
                    c.nombre AS creado_por_nombre
               FROM delegaciones d JOIN usuarios u ON u.id = d.delegado_id LEFT JOIN usuarios c ON c.id = d.creado_por
              WHERE d.jefe_id = ? ORDER BY u.nombre'
        );
        $stmt->execute([$jefeId]);
        return $stmt->fetchAll();
    }

    /** @return list<array{id: int, nombre: string, ficha: string, ver_todos: bool, hasta: ?string}> jefes vigentes para los que puede levantar */
    public function jefesDe(int $delegadoId): array
    {
        $stmt = $this->pdo->prepare(
            'SELECT u.id, u.nombre, u.ficha, d.ver_todos, d.hasta
               FROM delegaciones d JOIN usuarios u ON u.id = d.jefe_id
              WHERE d.delegado_id = ? AND u.activo = 1 AND ' . self::VIGENTE . ' ORDER BY u.nombre'
        );
        $stmt->execute([$delegadoId]);
        return array_map(static fn (array $r): array => [
            'id' => (int) $r['id'], 'nombre' => (string) $r['nombre'], 'ficha' => (string) $r['ficha'],
            'ver_todos' => (int) $r['ver_todos'] === 1, 'hasta' => $r['hasta'],
        ], $stmt->fetchAll());
    }

    public function puedeLevantarPor(int $delegadoId, int $jefeId): bool
    {
        foreach ($this->jefesDe($delegadoId) as $j) {
            if ($j['id'] === $jefeId) {
                return true;
            }
        }
        return false;
    }

    /**
     * ¿La persona ve el ticket como autorizada del solicitante (o porque ella lo levantó)?
     *
     * @param array<string, mixed> $user
     * @param array<string, mixed> $ticket usuario_solicitante_id y creado_por_id
     */
    public function puedeVerTicket(array $user, array $ticket): bool
    {
        $uid = (int) $user['id'];
        if ((int) ($ticket['creado_por_id'] ?? 0) === $uid) {
            return true;
        }
        foreach ($this->jefesDe($uid) as $j) {
            if ($j['id'] === (int) ($ticket['usuario_solicitante_id'] ?? 0)) {
                return $j['ver_todos'];
            }
        }
        return false;
    }

    /**
     * Condición SQL (alias t) de los tickets que la persona ve como autorizada.
     *
     * @return array{0: string, 1: list<int>}
     */
    public static function filtroSql(int $uid): array
    {
        return [
            '(t.creado_por_id = ? OR EXISTS (SELECT 1 FROM delegaciones d WHERE d.delegado_id = ? AND d.jefe_id = t.usuario_solicitante_id
                AND d.ver_todos = 1 AND ' . self::VIGENTE . '))',
            [$uid, $uid],
        ];
    }

    /** @return array<string, mixed>|null */
    private function usuario(string $campo, int|string $valor): ?array
    {
        $col = $campo === 'ficha' ? 'ficha' : 'id';
        $stmt = $this->pdo->prepare("SELECT id, ficha, nombre, rol, registrado, activo FROM usuarios WHERE {$col} = ?");
        $stmt->execute([$valor]);
        return $stmt->fetch() ?: null;
    }
}
