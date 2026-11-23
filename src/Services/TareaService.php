<?php

declare(strict_types=1);

namespace App\Services;

use App\Core\Roles;
use App\Core\ValidationException;
use DateTimeImmutable;
use PDO;
use Throwable;

/**
 * Tareas del supervisor a sus agentes (spec 7.6).
 *
 * Una tarea (folio TA-AAAA-NNNNNN) tiene una asignación por agente, cada una
 * con su estado y sus propias evidencias:
 *   asignada → en_proceso → entregada → aceptada | devuelta (→ en_proceso …)
 */
final class TareaService
{
    public const ESTADOS = [
        'asignada'   => ['Asignada', 'secondary'],
        'en_proceso' => ['En proceso', 'primary'],
        'entregada'  => ['Entregada', 'info'],
        'aceptada'   => ['Aceptada', 'success'],
        'devuelta'   => ['Devuelta', 'warning'],
    ];

    public const TIPOS_EVIDENCIA = ['foto' => 'Foto', 'documento' => 'Documento', 'archivo' => 'Archivo'];

    public function __construct(private readonly PDO $pdo)
    {
    }

    /**
     * @param array<string, mixed> $supervisor
     * @param array<string, mixed> $in titulo, instrucciones, prioridad, entrega_en, agentes[], evidencias[{tipo, descripcion}]
     * @param list<array{ruta: string, nombre_original: string, mime: string, tamano: int}> $referencias
     */
    public function crear(array $supervisor, array $in, array $referencias): int
    {
        if (!in_array($supervisor['rol'] ?? '', Roles::SUPERVISION, true)) {
            throw new ValidationException(['Solo los supervisores asignan tareas.']);
        }
        $titulo = trim((string) preg_replace('/\s+/u', ' ', (string) ($in['titulo'] ?? '')));
        $instrucciones = trim(str_replace(["\r\n", "\r"], "\n", (string) ($in['instrucciones'] ?? '')));
        $prioridad = (string) ($in['prioridad'] ?? 'media');
        $entrega = self::fecha((string) ($in['entrega_en'] ?? ''));
        $agentes = array_values(array_unique(array_filter(array_map('intval', (array) ($in['agentes'] ?? [])))));
        $evidencias = self::evidencias((array) ($in['evidencias'] ?? []));

        $errors = [];
        if (mb_strlen($titulo) < 3 || mb_strlen($titulo) > 180) {
            $errors[] = 'El título debe tener entre 3 y 180 caracteres.';
        }
        if (mb_strlen($instrucciones) < 5 || mb_strlen($instrucciones) > 5000) {
            $errors[] = 'Escribe las instrucciones (5 a 5000 caracteres).';
        }
        if (!array_key_exists($prioridad, Catalog::PRIORIDADES)) {
            $errors[] = 'Prioridad inválida.';
        }
        if ($entrega === null) {
            $errors[] = 'Indica la fecha y hora de entrega.';
        } elseif ($entrega <= date('Y-m-d H:i:s')) {
            $errors[] = 'La fecha de entrega debe ser futura.';
        }
        $permitidos = array_column((new Alcance($this->pdo))->agentesDe((int) $supervisor['id']), 'id');
        if ($agentes === []) {
            $errors[] = 'Elige al menos un agente.';
        } elseif (array_diff($agentes, $permitidos) !== []) {
            $errors[] = 'Solo puedes asignar tareas a los agentes de tus instalaciones.';
        }
        if ($errors !== []) {
            throw new ValidationException($errors);
        }

        $this->pdo->beginTransaction();
        try {
            $this->pdo->prepare(
                'INSERT INTO tareas (folio, supervisor_id, titulo, instrucciones, prioridad, entrega_en, evidencias_requeridas)
                 VALUES (?, ?, ?, ?, ?, ?, ?)'
            )->execute([
                'TMP-' . bin2hex(random_bytes(8)), (int) $supervisor['id'], $titulo, $instrucciones, $prioridad, $entrega,
                $evidencias !== [] ? json_encode($evidencias, JSON_UNESCAPED_UNICODE) : null,
            ]);
            $id = (int) $this->pdo->lastInsertId();
            $this->pdo->prepare('UPDATE tareas SET folio = ? WHERE id = ?')->execute([self::folio($id), $id]);

            $ins = $this->pdo->prepare('INSERT INTO tarea_asignaciones (tarea_id, agente_id) VALUES (?, ?)');
            foreach ($agentes as $a) {
                $ins->execute([$id, $a]);
            }
            $this->guardarArchivos($id, null, (int) $supervisor['id'], $referencias);
            $this->pdo->commit();
        } catch (Throwable $e) {
            $this->pdo->rollBack();
            throw $e;
        }
        return $id;
    }

    public static function folio(int $id): string
    {
        return sprintf('TA-%s-%06d', date('Y'), $id);
    }

    /**
     * @param array<string, mixed> $user
     * @param list<array{ruta: string, nombre_original: string, mime: string, tamano: int}> $evidencias
     */
    public function cambiarEstado(int $asignacionId, array $user, string $accion, ?string $comentario, array $evidencias = []): void
    {
        if (AdminLocalService::bloqueado($user)) {
            throw new ValidationException(['Registra el cambio de tu contraseña de admin local para continuar.']);
        }
        $a = $this->asignacion($asignacionId);
        if ($a === null) {
            throw new ValidationException(['La asignación no existe.']);
        }
        $uid = (int) $user['id'];
        $esAgente = (int) $a['agente_id'] === $uid;
        $esRevisor = (int) ($a['supervisor_id'] ?? 0) === $uid || ($user['rol'] ?? '') === 'admin_ti';
        $comentario = trim(str_replace(["\r\n", "\r"], "\n", (string) $comentario));
        if (mb_strlen($comentario) > 3000) {
            throw new ValidationException(['El comentario no puede exceder 3000 caracteres.']);
        }

        [$permitido, $desde, $hacia] = match ($accion) {
            'iniciar'  => [$esAgente, ['asignada', 'devuelta'], 'en_proceso'],
            'entregar' => [$esAgente, ['asignada', 'en_proceso', 'devuelta'], 'entregada'],
            'aceptar'  => [$esRevisor, ['entregada'], 'aceptada'],
            'devolver' => [$esRevisor, ['entregada'], 'devuelta'],
            default    => [false, [], ''],
        };
        if (!$permitido) {
            throw new ValidationException(['No puedes realizar esa acción en esta tarea.']);
        }
        if (!in_array($a['estado'], $desde, true)) {
            throw new ValidationException(['La tarea está "' . self::ESTADOS[$a['estado']][0] . '"; esa acción no aplica.']);
        }
        if ($accion === 'devolver' && $comentario === '') {
            throw new ValidationException(['Escribe un comentario explicando qué falta corregir.']);
        }
        if ($accion === 'entregar') {
            $requeridas = json_decode((string) ($a['evidencias_requeridas'] ?? ''), true);
            $yaSubidas = (int) $this->pdo->query('SELECT COUNT(*) FROM tarea_archivos WHERE asignacion_id = ' . (int) $asignacionId)->fetchColumn();
            if (is_array($requeridas) && $requeridas !== [] && $evidencias === [] && $yaSubidas === 0) {
                throw new ValidationException(['Esta tarea pide evidencia: adjunta al menos un archivo.']);
            }
        }

        $this->pdo->beginTransaction();
        try {
            $sql = match ($accion) {
                'iniciar'  => 'UPDATE tarea_asignaciones SET estado = ? WHERE id = ?',
                'entregar' => 'UPDATE tarea_asignaciones SET estado = ?, comentario_entrega = ?, entregado_en = NOW() WHERE id = ?',
                default    => 'UPDATE tarea_asignaciones SET estado = ?, comentario_revision = ?, revisado_en = NOW() WHERE id = ?',
            };
            $params = $accion === 'iniciar' ? [$hacia, $asignacionId] : [$hacia, $comentario !== '' ? $comentario : null, $asignacionId];
            $this->pdo->prepare($sql)->execute($params);
            if ($accion === 'entregar') {
                $this->guardarArchivos((int) $a['tarea_id'], $asignacionId, $uid, $evidencias);
            }
            $this->pdo->commit();
        } catch (Throwable $e) {
            $this->pdo->rollBack();
            throw $e;
        }
    }

    /** @return array{total: int, entregadas: int, aceptadas: int} */
    public function avance(int $tareaId): array
    {
        $stmt = $this->pdo->prepare(
            "SELECT COUNT(*) AS total,
                    COALESCE(SUM(estado IN ('entregada', 'aceptada')), 0) AS entregadas,
                    COALESCE(SUM(estado = 'aceptada'), 0) AS aceptadas
               FROM tarea_asignaciones WHERE tarea_id = ?"
        );
        $stmt->execute([$tareaId]);
        return array_map('intval', $stmt->fetch() ?: ['total' => 0, 'entregadas' => 0, 'aceptadas' => 0]);
    }

    /**
     * @param array<string, mixed> $user
     * @param array<string, mixed> $archivo fila de tarea_archivos
     */
    public function puedeDescargar(array $user, array $archivo): bool
    {
        $uid = (int) $user['id'];
        if (($user['rol'] ?? '') === 'admin_ti') {
            return true;
        }
        $stmt = $this->pdo->prepare('SELECT supervisor_id FROM tareas WHERE id = ?');
        $stmt->execute([(int) $archivo['tarea_id']]);
        if ((int) $stmt->fetchColumn() === $uid) {
            return true;
        }
        if ($archivo['asignacion_id'] === null) {
            $stmt = $this->pdo->prepare('SELECT 1 FROM tarea_asignaciones WHERE tarea_id = ? AND agente_id = ?');
            $stmt->execute([(int) $archivo['tarea_id'], $uid]);
            return (bool) $stmt->fetchColumn();
        }
        $stmt = $this->pdo->prepare('SELECT agente_id FROM tarea_asignaciones WHERE id = ?');
        $stmt->execute([(int) $archivo['asignacion_id']]);
        return (int) $stmt->fetchColumn() === $uid;
    }

    // ------------------------------------------------------------------
    //  Consultas para pantallas
    // ------------------------------------------------------------------

    /**
     * Tareas del supervisor (o todas para el admin) con su avance.
     *
     * @param array<string, mixed> $user
     * @return list<array<string, mixed>>
     */
    public function asignadasPor(array $user): array
    {
        $sql = "SELECT t.*, u.nombre AS supervisor_nombre,
                       COUNT(a.id) AS total,
                       COALESCE(SUM(a.estado IN ('entregada', 'aceptada')), 0) AS entregadas,
                       COALESCE(SUM(a.estado = 'aceptada'), 0) AS aceptadas,
                       COALESCE(SUM(a.estado = 'entregada'), 0) AS por_revisar
                  FROM tareas t
                  LEFT JOIN usuarios u ON u.id = t.supervisor_id
                  LEFT JOIN tarea_asignaciones a ON a.tarea_id = t.id";
        $params = [];
        if (($user['rol'] ?? '') !== 'admin_ti') {
            $sql .= ' WHERE t.supervisor_id = ?';
            $params[] = (int) $user['id'];
        }
        $sql .= " GROUP BY t.id ORDER BY (COALESCE(SUM(a.estado = 'aceptada'), 0) < COUNT(a.id)) DESC, t.entrega_en ASC LIMIT 200";
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute($params);
        return $stmt->fetchAll();
    }

    /** @return list<array<string, mixed>> */
    public function deAgente(int $agenteId): array
    {
        $stmt = $this->pdo->prepare(
            "SELECT t.id, t.folio, t.titulo, t.prioridad, t.entrega_en, a.id AS asignacion_id, a.estado,
                    u.nombre AS supervisor_nombre
               FROM tarea_asignaciones a
               JOIN tareas t ON t.id = a.tarea_id
               LEFT JOIN usuarios u ON u.id = t.supervisor_id
              WHERE a.agente_id = ?
              ORDER BY (a.estado = 'aceptada') ASC, t.entrega_en ASC"
        );
        $stmt->execute([$agenteId]);
        return $stmt->fetchAll();
    }

    /** @return array<string, mixed>|null tarea con asignaciones y archivos */
    public function detalle(int $tareaId): ?array
    {
        $stmt = $this->pdo->prepare(
            'SELECT t.*, u.nombre AS supervisor_nombre, u.email AS supervisor_email
               FROM tareas t LEFT JOIN usuarios u ON u.id = t.supervisor_id WHERE t.id = ?'
        );
        $stmt->execute([$tareaId]);
        $t = $stmt->fetch();
        if (!$t) {
            return null;
        }
        $t['evidencias'] = json_decode((string) ($t['evidencias_requeridas'] ?? ''), true) ?: [];
        $a = $this->pdo->prepare(
            'SELECT a.*, u.nombre AS agente_nombre, u.email AS agente_email
               FROM tarea_asignaciones a JOIN usuarios u ON u.id = a.agente_id
              WHERE a.tarea_id = ? ORDER BY u.nombre'
        );
        $a->execute([$tareaId]);
        $t['asignaciones'] = $a->fetchAll();
        $f = $this->pdo->prepare('SELECT * FROM tarea_archivos WHERE tarea_id = ? ORDER BY id');
        $f->execute([$tareaId]);
        $t['archivos'] = $f->fetchAll();
        return $t;
    }

    /** @return array<string, mixed>|null */
    public function asignacion(int $id): ?array
    {
        $stmt = $this->pdo->prepare(
            'SELECT a.*, t.supervisor_id, t.evidencias_requeridas, t.folio, t.titulo, t.entrega_en
               FROM tarea_asignaciones a JOIN tareas t ON t.id = a.tarea_id WHERE a.id = ?'
        );
        $stmt->execute([$id]);
        return $stmt->fetch() ?: null;
    }

    /** @return array<string, mixed>|null */
    public function archivo(int $id): ?array
    {
        $stmt = $this->pdo->prepare('SELECT * FROM tarea_archivos WHERE id = ?');
        $stmt->execute([$id]);
        return $stmt->fetch() ?: null;
    }

    /** Asignaciones abiertas cuya entrega es mañana (para el recordatorio diario). @return list<array<string, mixed>> */
    public function vencenManana(string $hoy): array
    {
        $manana = (new DateTimeImmutable($hoy))->modify('+1 day')->format('Y-m-d');
        $stmt = $this->pdo->prepare(
            "SELECT a.id, a.agente_id, t.id AS tarea_id, t.folio, t.titulo, t.entrega_en, u.nombre, u.email
               FROM tarea_asignaciones a JOIN tareas t ON t.id = a.tarea_id JOIN usuarios u ON u.id = a.agente_id
              WHERE a.estado IN ('asignada', 'en_proceso', 'devuelta') AND DATE(t.entrega_en) = ? AND u.activo = 1"
        );
        $stmt->execute([$manana]);
        return $stmt->fetchAll();
    }

    // ------------------------------------------------------------------

    /** @param list<array{ruta: string, nombre_original: string, mime: string, tamano: int}> $archivos */
    private function guardarArchivos(int $tareaId, ?int $asignacionId, int $por, array $archivos): void
    {
        $ins = $this->pdo->prepare(
            'INSERT INTO tarea_archivos (tarea_id, asignacion_id, nombre_original, ruta, mime, tamano, subido_por) VALUES (?, ?, ?, ?, ?, ?, ?)'
        );
        foreach ($archivos as $f) {
            $ins->execute([$tareaId, $asignacionId, $f['nombre_original'], $f['ruta'], $f['mime'], $f['tamano'], $por]);
        }
    }

    /**
     * @param array<int|string, mixed> $raw
     * @return list<array{tipo: string, descripcion: string}>
     */
    private static function evidencias(array $raw): array
    {
        $out = [];
        foreach ($raw as $e) {
            if (!is_array($e)) {
                continue;
            }
            $tipo = (string) ($e['tipo'] ?? '');
            $desc = trim(mb_substr((string) ($e['descripcion'] ?? ''), 0, 200));
            if (array_key_exists($tipo, self::TIPOS_EVIDENCIA) && $desc !== '') {
                $out[] = ['tipo' => $tipo, 'descripcion' => $desc];
            }
        }
        return array_slice($out, 0, 10);
    }

    private static function fecha(string $v): ?string
    {
        $v = trim($v);
        foreach (['Y-m-d\TH:i', 'Y-m-d H:i', 'Y-m-d H:i:s'] as $f) {
            $d = DateTimeImmutable::createFromFormat('!' . $f, $v);
            if ($d !== false && $d->format($f) === $v) {
                return $d->format('Y-m-d H:i:s');
            }
        }
        return null;
    }
}
