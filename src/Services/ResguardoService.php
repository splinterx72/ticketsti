<?php

declare(strict_types=1);

namespace App\Services;

use App\Core\Folio;
use App\Core\ValidationException;
use PDO;
use Throwable;

/**
 * Resguardo de equipos: lo que deja un trabajador (baja, cambio, renuncia…)
 * y los equipos disponibles para asignar.
 *
 * - Recepción (`recepciones`, folio RE-AAAA-NNNNNN): quién entrega, motivo,
 *   instalación donde se guarda, observaciones y, opcional, el acta del GECA
 *   escaneada (las actas de baja y responsivas las genera el GECA).
 * - Por equipo (`equipos_resguardo`): componentes y accesorios que dejó,
 *   condición y estado: en_resguardo → disponible → asignado, o dictamen /
 *   baja_definitiva. Al asignar queda "pendiente en GECA" hasta que la carga
 *   semanal trae el equipo a nombre de la persona (`confirmarConGeca`).
 */
final class ResguardoService
{
    public const MOTIVOS = [
        'baja'       => 'Baja del resguardo',
        'cambio'     => 'Cambio de equipo',
        'renuncia'   => 'Renuncia, jubilación o cambio de área',
        'reparacion' => 'Reparación o dictamen',
        'otro'       => 'Otro',
    ];

    /** Lista base de accesorios que se marcan al recibir. */
    public const ACCESORIOS = [
        'cargador'        => 'Cargador',
        'mouse'           => 'Mouse',
        'teclado'         => 'Teclado',
        'maletin'         => 'Maletín / funda',
        'cable_video'     => 'Cable de video',
        'cable_corriente' => 'Cable de corriente',
        'candado'         => 'Candado',
        'docking'         => 'Base / docking',
    ];

    public const CONDICIONES = ['bueno' => 'Buen estado', 'regular' => 'Regular', 'malo' => 'Malo'];

    /** estado => [etiqueta, color de Bootstrap, ícono] */
    public const ESTADOS = [
        'en_resguardo'    => ['En resguardo TI', 'secondary', 'bi-box-seam'],
        'disponible'      => ['Disponible', 'success', 'bi-check-circle'],
        'dictamen'        => ['Para dictamen', 'warning', 'bi-clipboard-pulse'],
        'baja_definitiva' => ['Baja definitiva', 'dark', 'bi-x-octagon'],
        'asignado'        => ['Asignado', 'primary', 'bi-person-check'],
    ];

    /** Estados en los que el equipo sigue "en manos de TI". */
    private const ACTIVOS = ['en_resguardo', 'disponible', 'dictamen'];

    private static ?bool $tablas = null;

    public function __construct(private readonly PDO $pdo)
    {
    }

    public function disponible(): bool
    {
        if (self::$tablas === null) {
            self::$tablas = (int) $this->pdo->query(
                "SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME IN ('recepciones', 'equipos_resguardo')"
            )->fetchColumn() === 2;
        }
        return self::$tablas;
    }

    // ------------------------------------------------------------------
    //  Recepción
    // ------------------------------------------------------------------

    /**
     * Busca por ficha (todos los equipos del trabajador en el GECA) o por
     * serie (ese equipo). Marca los que ya están en resguardo.
     *
     * @return array{usuario: ?array<string, mixed>, equipos: list<array<string, mixed>>}
     */
    public function candidatos(string $q): array
    {
        $q = strtoupper(trim($q));
        if ($q === '') {
            return ['usuario' => null, 'equipos' => []];
        }
        $usr = $this->pdo->prepare('SELECT id, ficha, nombre, jefe_nombre FROM usuarios WHERE ficha = ?');
        $usr->execute([$q]);
        $usuario = $usr->fetch() ?: null;
        $sql = 'SELECT e.id, e.serie, e.marca, e.modelo, e.tipo_componente, e.estado_resguardo, e.vigente_en_geca, e.usuario_id,
                       e.serie_monitor1, e.marca_monitor1, e.modelo_monitor1, e.serie_monitor2, e.marca_monitor2, e.modelo_monitor2,
                       u.ficha, u.nombre AS usuario_nombre,
                       (SELECT r.estado FROM equipos_resguardo r WHERE r.equipo_id = e.id
                          AND (r.estado IN (\'' . implode("', '", self::ACTIVOS) . '\') OR (r.estado = \'asignado\' AND r.confirmado_geca_en IS NULL))
                          ORDER BY r.id DESC LIMIT 1) AS en_resguardo
                  FROM equipos e LEFT JOIN usuarios u ON u.id = e.usuario_id';
        if ($usuario) {
            $stmt = $this->pdo->prepare($sql . ' WHERE e.usuario_id = ? ORDER BY e.es_principal DESC, e.serie');
            $stmt->execute([(int) $usuario['id']]);
        } else {
            $stmt = $this->pdo->prepare($sql . ' WHERE e.serie = ? OR e.serie_monitor1 = ? OR e.serie_monitor2 = ?');
            $stmt->execute([$q, $q, $q]);
        }
        $equipos = $stmt->fetchAll();
        if (!$usuario && $equipos !== [] && $equipos[0]['usuario_id'] !== null) {
            $usr = $this->pdo->prepare('SELECT id, ficha, nombre, jefe_nombre FROM usuarios WHERE id = ?');
            $usr->execute([(int) $equipos[0]['usuario_id']]);
            $usuario = $usr->fetch() ?: null;
        }
        return ['usuario' => $usuario, 'equipos' => $equipos];
    }

    /**
     * Componentes que se pueden marcar para un equipo: sus monitores casados
     * (del GECA) y la lista base de accesorios.
     *
     * @param array<string, mixed> $equipo
     * @return array<string, string>
     */
    public static function opcionesDe(array $equipo): array
    {
        $out = [];
        foreach ([1, 2] as $n) {
            if (!empty($equipo["serie_monitor{$n}"])) {
                $out["monitor_{$n}"] = "Monitor {$n} " . trim($equipo["serie_monitor{$n}"] . ' ' . ($equipo["marca_monitor{$n}"] ?? ''));
            }
        }
        return $out + self::ACCESORIOS;
    }

    /**
     * Registra la recepción.
     *
     * @param array<string, mixed> $actor TI que recibe
     * @param array<string, mixed> $in usuario_id, motivo, instalacion_id, observaciones,
     *                                 equipos[] (ids marcados), condicion[id], accesorios[id][], obs[id]
     * @param array{ruta: string, nombre_original: string, mime: string, tamano: int}|null $acta escaneo del GECA
     */
    public function recibir(array $actor, array $in, ?array $acta = null): int
    {
        $errors = [];
        $motivo = (string) ($in['motivo'] ?? '');
        if (!array_key_exists($motivo, self::MOTIVOS)) {
            $errors[] = 'Elige el motivo de la entrega.';
        }
        $instalacion = (int) ($in['instalacion_id'] ?? 0);
        if ($instalacion > 0) {
            $st = $this->pdo->prepare('SELECT 1 FROM instalaciones WHERE id = ?');
            $st->execute([$instalacion]);
            if (!$st->fetchColumn()) {
                $errors[] = 'La instalación elegida no existe.';
            }
        }
        $obs = trim(str_replace(["\r\n", "\r"], "\n", (string) ($in['observaciones'] ?? '')));
        if (mb_strlen($obs) > 3000) {
            $errors[] = 'Las observaciones admiten hasta 3000 caracteres.';
        }
        $ids = array_values(array_unique(array_filter(array_map('intval', array_filter((array) ($in['equipos'] ?? []), 'is_scalar')))));
        if ($ids === []) {
            $errors[] = 'Marca al menos un equipo que se recibe.';
        }
        $equipos = [];
        foreach ($ids as $eid) {
            $st = $this->pdo->prepare('SELECT * FROM equipos WHERE id = ?');
            $st->execute([$eid]);
            $eq = $st->fetch();
            if (!$eq) {
                $errors[] = 'Uno de los equipos no existe.';
                continue;
            }
            if ($this->activo($eid) !== null) {
                $errors[] = "El equipo {$eq['serie']} ya está en resguardo de TI.";
            }
            $equipos[] = $eq;
        }
        if ($errors !== []) {
            throw new ValidationException($errors);
        }

        $usuarioId = (int) ($in['usuario_id'] ?? 0);
        $usuario = null;
        if ($usuarioId > 0) {
            $st = $this->pdo->prepare('SELECT id, ficha, nombre FROM usuarios WHERE id = ?');
            $st->execute([$usuarioId]);
            $usuario = $st->fetch() ?: null;
        }

        $this->pdo->beginTransaction();
        try {
            $this->pdo->prepare(
                'INSERT INTO recepciones (folio, usuario_id, ficha_entrega, nombre_entrega, motivo, instalacion_id, observaciones,
                                          acta_ruta, acta_nombre, acta_mime, acta_tamano, recibido_por)
                 VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)'
            )->execute([
                'TMP-' . bin2hex(random_bytes(8)), $usuario['id'] ?? null, $usuario['ficha'] ?? null, $usuario['nombre'] ?? null,
                $motivo, $instalacion > 0 ? $instalacion : null, $obs !== '' ? $obs : null,
                $acta['ruta'] ?? null, $acta['nombre_original'] ?? null, $acta['mime'] ?? null, $acta['tamano'] ?? null,
                (int) $actor['id'],
            ]);
            $id = (int) $this->pdo->lastInsertId();
            Folio::asignar($this->pdo, 'recepciones', 'RE', $id);

            $ins = $this->pdo->prepare(
                'INSERT INTO equipos_resguardo (recepcion_id, equipo_id, condicion, accesorios, observaciones, actualizado_por) VALUES (?, ?, ?, ?, ?, ?)'
            );
            foreach ($equipos as $eq) {
                $eid = (int) $eq['id'];
                $cond = (string) (($in['condicion'] ?? [])[$eid] ?? 'bueno');
                $validos = array_keys(self::opcionesDe($eq));
                $acc = array_values(array_intersect($validos, array_map('strval', (array) (($in['accesorios'] ?? [])[$eid] ?? []))));
                $obsEq = trim(mb_substr((string) (($in['obs'] ?? [])[$eid] ?? ''), 0, 500));
                $ins->execute([
                    $id, $eid, array_key_exists($cond, self::CONDICIONES) ? $cond : 'bueno',
                    json_encode($acc, JSON_UNESCAPED_UNICODE), $obsEq !== '' ? $obsEq : null, (int) $actor['id'],
                ]);
            }
            $this->pdo->commit();
        } catch (Throwable $e) {
            $this->pdo->rollBack();
            throw $e;
        }
        return $id;
    }

    /** Registro abierto del equipo en resguardo (o null). @return array<string, mixed>|null */
    public function activo(int $equipoId): ?array
    {
        $st = $this->pdo->prepare(
            "SELECT * FROM equipos_resguardo WHERE equipo_id = ?
                AND (estado IN ('" . implode("', '", self::ACTIVOS) . "') OR (estado = 'asignado' AND confirmado_geca_en IS NULL))
              ORDER BY id DESC LIMIT 1"
        );
        $st->execute([$equipoId]);
        return $st->fetch() ?: null;
    }

    // ------------------------------------------------------------------
    //  Estado y asignación
    // ------------------------------------------------------------------

    /** @param array<string, mixed> $actor */
    public function cambiarEstado(int $itemId, string $estado, array $actor): void
    {
        $item = $this->item($itemId);
        if ($item === null) {
            throw new ValidationException(['El registro no existe.']);
        }
        if (!in_array($estado, self::ACTIVOS, true) && $estado !== 'baja_definitiva') {
            throw new ValidationException(['Estado inválido.']);
        }
        if (!in_array($item['estado'], self::ACTIVOS, true)) {
            throw new ValidationException(['El equipo ya está "' . self::ESTADOS[$item['estado']][0] . '"; ese cambio no aplica.']);
        }
        $this->pdo->prepare('UPDATE equipos_resguardo SET estado = ?, actualizado_por = ? WHERE id = ?')
            ->execute([$estado, (int) $actor['id'], $itemId]);
    }

    /**
     * Asigna un equipo disponible a un trabajador (por ficha). Queda
     * pendiente en GECA hasta que la carga lo traiga a su nombre.
     *
     * @param array<string, mixed> $actor
     */
    public function asignar(int $itemId, string $ficha, string $nota, array $actor): void
    {
        $item = $this->item($itemId);
        if ($item === null || $item['estado'] !== 'disponible') {
            throw new ValidationException(['Solo se asignan equipos disponibles.']);
        }
        $ficha = strtoupper(trim($ficha));
        $st = $this->pdo->prepare('SELECT id, activo FROM usuarios WHERE ficha = ?');
        $st->execute([$ficha]);
        $u = $st->fetch();
        if (!$u || (int) $u['activo'] !== 1) {
            throw new ValidationException(["No existe un trabajador activo con la ficha {$ficha}."]);
        }
        $nota = trim(mb_substr($nota, 0, 500));
        $this->pdo->prepare(
            "UPDATE equipos_resguardo SET estado = 'asignado', asignado_a_id = ?, asignado_por = ?, asignado_en = NOW(),
                    asignacion_nota = ?, actualizado_por = ? WHERE id = ? AND estado = 'disponible'"
        )->execute([(int) $u['id'], (int) $actor['id'], $nota !== '' ? $nota : null, (int) $actor['id'], $itemId]);
    }

    /** Marca como confirmadas las asignaciones que el GECA ya trae a nombre de la persona. */
    public function confirmarConGeca(): int
    {
        if (!$this->disponible()) {
            return 0;
        }
        return (int) $this->pdo->exec(
            "UPDATE equipos_resguardo r JOIN equipos e ON e.id = r.equipo_id
                SET r.confirmado_geca_en = NOW()
              WHERE r.estado = 'asignado' AND r.confirmado_geca_en IS NULL AND e.usuario_id = r.asignado_a_id"
        );
    }

    // ------------------------------------------------------------------
    //  Consultas
    // ------------------------------------------------------------------

    /**
     * Equipos en resguardo con filtros: estado (o "abiertos" = en manos de TI
     * y asignados sin confirmar), instalación, tipo y texto (serie, ficha).
     *
     * @param array<string, mixed> $user
     * @param array{estado?: string, instalacion?: int, tipo?: string, q?: string} $f
     * @return list<array<string, mixed>>
     */
    public function lista(array $user, array $f = []): array
    {
        $this->confirmarConGeca();
        $where = [];
        $params = [];
        $estado = (string) ($f['estado'] ?? 'abiertos');
        if ($estado === 'abiertos') {
            $where[] = "(r.estado IN ('" . implode("', '", self::ACTIVOS) . "') OR (r.estado = 'asignado' AND r.confirmado_geca_en IS NULL))";
        } elseif (array_key_exists($estado, self::ESTADOS)) {
            $where[] = 'r.estado = ?';
            $params[] = $estado;
        }
        $alcance = new Alcance($this->pdo);
        if (!$alcance->veTodo($user)) {
            $mias = $alcance->instalacionesDe((int) $user['id']);
            $where[] = $mias === [] ? 'rc.instalacion_id IS NULL'
                : '(rc.instalacion_id IS NULL OR rc.instalacion_id IN (' . implode(',', array_map('intval', $mias)) . '))';
        }
        if ((int) ($f['instalacion'] ?? 0) > 0) {
            $where[] = 'rc.instalacion_id = ?';
            $params[] = (int) $f['instalacion'];
        }
        if (($f['tipo'] ?? '') !== '') {
            $where[] = 'e.tipo_componente = ?';
            $params[] = (string) $f['tipo'];
        }
        $q = trim((string) ($f['q'] ?? ''));
        if ($q !== '') {
            $like = '%' . addcslashes($q, '%_\\') . '%';
            $where[] = '(e.serie LIKE ? OR e.marca LIKE ? OR e.modelo LIKE ? OR rc.ficha_entrega = ? OR rc.folio LIKE ?)';
            array_push($params, $like, $like, $like, strtoupper($q), $like);
        }
        $stmt = $this->pdo->prepare(
            'SELECT r.*, e.serie, e.marca, e.modelo, e.tipo_componente, e.serie_monitor1, e.marca_monitor1, e.serie_monitor2, e.marca_monitor2,
                    rc.folio, rc.motivo, rc.ficha_entrega, rc.nombre_entrega, rc.creado_en AS recibido_en, rc.instalacion_id,
                    i.nombre AS instalacion, ua.ficha AS asignado_ficha, ua.nombre AS asignado_nombre
               FROM equipos_resguardo r
               JOIN equipos e ON e.id = r.equipo_id
               JOIN recepciones rc ON rc.id = r.recepcion_id
               LEFT JOIN instalaciones i ON i.id = rc.instalacion_id
               LEFT JOIN usuarios ua ON ua.id = r.asignado_a_id'
            . ($where !== [] ? ' WHERE ' . implode(' AND ', $where) : '')
            . " ORDER BY FIELD(r.estado, 'disponible', 'en_resguardo', 'dictamen', 'asignado', 'baja_definitiva'), rc.creado_en DESC LIMIT 500"
        );
        $stmt->execute($params);
        return $stmt->fetchAll();
    }

    /** @return list<string> tipos de componente presentes (para el filtro) */
    public function tipos(): array
    {
        return array_map('strval', $this->pdo->query(
            'SELECT DISTINCT e.tipo_componente FROM equipos_resguardo r JOIN equipos e ON e.id = r.equipo_id
              WHERE e.tipo_componente IS NOT NULL ORDER BY e.tipo_componente'
        )->fetchAll(PDO::FETCH_COLUMN));
    }

    /** @return array<string, mixed>|null recepción con sus equipos */
    public function recepcion(int $id): ?array
    {
        $st = $this->pdo->prepare(
            'SELECT rc.*, i.nombre AS instalacion, u.nombre AS recibio_nombre
               FROM recepciones rc LEFT JOIN instalaciones i ON i.id = rc.instalacion_id LEFT JOIN usuarios u ON u.id = rc.recibido_por
              WHERE rc.id = ?'
        );
        $st->execute([$id]);
        $rc = $st->fetch();
        if (!$rc) {
            return null;
        }
        $it = $this->pdo->prepare(
            'SELECT r.*, e.serie, e.marca, e.modelo, e.tipo_componente, e.serie_monitor1, e.marca_monitor1, e.serie_monitor2, e.marca_monitor2,
                    ua.ficha AS asignado_ficha, ua.nombre AS asignado_nombre
               FROM equipos_resguardo r JOIN equipos e ON e.id = r.equipo_id LEFT JOIN usuarios ua ON ua.id = r.asignado_a_id
              WHERE r.recepcion_id = ? ORDER BY e.serie'
        );
        $it->execute([$id]);
        $rc['equipos'] = $it->fetchAll();
        return $rc;
    }

    /** @return array<string, mixed>|null */
    public function item(int $id): ?array
    {
        $st = $this->pdo->prepare('SELECT r.*, rc.instalacion_id FROM equipos_resguardo r JOIN recepciones rc ON rc.id = r.recepcion_id WHERE r.id = ?');
        $st->execute([$id]);
        return $st->fetch() ?: null;
    }

    /**
     * ¿El usuario de TI puede ver/operar esta recepción? Admin, todo; los
     * demás, las de sus instalaciones o sin instalación.
     *
     * @param array<string, mixed> $user
     */
    public function enAlcance(array $user, ?int $instalacionId): bool
    {
        $alcance = new Alcance($this->pdo);
        return $alcance->veTodo($user) || $instalacionId === null
            || in_array($instalacionId, $alcance->instalacionesDe((int) $user['id']), true);
    }

    /**
     * Etiquetas de lo que dejó (componentes y accesorios marcados).
     *
     * @param array<string, mixed> $item fila con accesorios y datos del equipo
     * @return list<string>
     */
    public static function dejo(array $item): array
    {
        $claves = json_decode((string) ($item['accesorios'] ?? '[]'), true);
        $opciones = self::opcionesDe($item);
        return array_values(array_map(static fn (string $k): string => $opciones[$k] ?? $k, is_array($claves) ? array_map('strval', $claves) : []));
    }
}
