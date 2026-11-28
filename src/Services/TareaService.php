<?php

declare(strict_types=1);

namespace App\Services;

use App\Core\Folio;
use App\Core\Roles;
use App\Core\ValidationException;
use DateTimeImmutable;
use PDO;
use Throwable;

/**
 * Tareas del supervisor a sus agentes (spec 7.6).
 *
 * Una tarea (folio TA-AAAA-NNNNNN, aleatorio) tiene una asignación por agente, cada una
 * con su estado y sus propias evidencias:
 *   asignada → en_proceso → entregada → aceptada | devuelta (→ en_proceso …)
 *
 * Hoja de reportes (`tarea_avances`): el agente va escribiendo lo que hace y
 * cuántos equipos/usuarios atendió. Con meta (`tareas.meta_*`, por agente) se
 * calcula el % de avance; al llegar al 100 % se avisa al supervisor una vez.
 * "Cierre" = entregar: con menos del 100 % pide el motivo.
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

    public const UNIDADES = ['equipos' => 'Equipos', 'usuarios' => 'Usuarios', 'otro' => 'Otro'];

    private static ?bool $reportes = null;
    private static ?bool $lista = null;

    public function __construct(private readonly PDO $pdo)
    {
    }

    /** ¿Ya se importó la migración de la hoja de reportes? */
    public function conReportes(): bool
    {
        if (self::$reportes === null) {
            self::$reportes = (int) $this->pdo->query(
                "SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'tarea_avances'"
            )->fetchColumn() === 1;
        }
        return self::$reportes;
    }

    /** ¿Ya se importó la migración de la lista de equipos de la tarea? */
    public function conLista(): bool
    {
        if (self::$lista === null) {
            self::$lista = (int) $this->pdo->query(
                "SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'tarea_equipos'"
            )->fetchColumn() === 1;
        }
        return self::$lista;
    }

    /** % de avance (0–100) o null si la tarea no tiene meta. */
    public static function porcentaje(int $hecho, ?int $meta): ?int
    {
        if ($meta === null || $meta <= 0) {
            return null;
        }
        return (int) min(100, floor($hecho * 100 / $meta));
    }

    /** "equipos", "usuarios" o lo que capturó el supervisor (p. ej. "nodos"). */
    public static function unidad(array $t): string
    {
        return match ($t['meta_unidad'] ?? null) {
            'equipos'  => 'equipos',
            'usuarios' => 'usuarios',
            'otro'     => (string) ($t['meta_descripcion'] ?: 'unidades'),
            default    => '',
        };
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
        $unidad = (string) ($in['meta_unidad'] ?? '');
        $metaDesc = trim((string) preg_replace('/\s+/u', ' ', (string) ($in['meta_descripcion'] ?? '')));
        $metaCant = (int) ($in['meta_cantidad'] ?? 0);
        // Meta "Equipos" con lista de series: la meta es el número de equipos de la lista.
        $listaEquipos = [];
        if ($unidad === 'equipos' && trim((string) ($in['equipos_series'] ?? '')) !== '' && $this->conLista()) {
            try {
                $listaEquipos = (new TicketService($this->pdo))->resolverEquipos([], (string) $in['equipos_series']);
                $metaCant = count($listaEquipos);
            } catch (ValidationException $e) {
                $listaErrores = $e->errors();
            }
        }

        $errors = $listaErrores ?? [];
        if ($unidad !== '') {
            if (!array_key_exists($unidad, self::UNIDADES)) {
                $errors[] = 'Elige qué se va a intervenir: equipos, usuarios u otro.';
            }
            if ($metaCant < 1 || $metaCant > 100000) {
                $errors[] = 'Indica cuántos se van a intervenir por agente (1 a 100 000).';
            }
            if ($unidad === 'otro' && (mb_strlen($metaDesc) < 2 || mb_strlen($metaDesc) > 60)) {
                $errors[] = 'Escribe qué se va a contar (p. ej. nodos, impresoras).';
            }
        }
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
            Folio::asignar($this->pdo, 'tareas', 'TA', $id);
            if ($unidad !== '' && $this->conReportes()) {
                $this->pdo->prepare('UPDATE tareas SET meta_unidad = ?, meta_descripcion = ?, meta_cantidad = ? WHERE id = ?')
                    ->execute([$unidad, $unidad === 'otro' ? $metaDesc : null, $metaCant, $id]);
                $insEq = $this->pdo->prepare('INSERT INTO tarea_equipos (tarea_id, equipo_id) VALUES (?, ?)');
                foreach ($listaEquipos as $eid) {
                    $insEq->execute([$id, $eid]);
                }
            }

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
        if ($accion === 'entregar' && $comentario === '' && $this->conReportes()) {
            $pct = $this->progreso($a)['porcentaje'];
            if ($pct !== null && $pct < 100) {
                throw new ValidationException(["Vas en {$pct} %: escribe el motivo del cierre antes del 100 %."]);
            }
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
            // Solo si sigue en el estado esperado: un doble envío, o el supervisor
            // aceptando mientras el agente reentrega, no deben aplicarse dos veces.
            $estadoOk = ' AND estado IN (' . implode(',', array_fill(0, count($desde), '?')) . ')';
            $sql = match ($accion) {
                'iniciar'  => 'UPDATE tarea_asignaciones SET estado = ? WHERE id = ?',
                'entregar' => 'UPDATE tarea_asignaciones SET estado = ?, comentario_entrega = ?, entregado_en = NOW() WHERE id = ?',
                default    => 'UPDATE tarea_asignaciones SET estado = ?, comentario_revision = ?, revisado_en = NOW() WHERE id = ?',
            } . $estadoOk;
            $params = $accion === 'iniciar' ? [$hacia, $asignacionId] : [$hacia, $comentario !== '' ? $comentario : null, $asignacionId];
            $upd = $this->pdo->prepare($sql);
            $upd->execute(array_merge($params, $desde));
            if ($upd->rowCount() !== 1) {
                throw new ValidationException(['La tarea cambió de estado mientras tanto; recarga la página.']);
            }
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
     * Agrega un reporte a la hoja del agente. Si la asignación estaba asignada
     * o devuelta pasa a en proceso. Debe llamarse dentro o fuera de una
     * transacción (respeta la que ya exista).
     *
     * @param array<string, mixed> $user
     * @param list<array{ruta: string, nombre_original: string, mime: string, tamano: int}> $archivos
     * @return array{porcentaje: ?int, hecho: int, completa: bool} completa = llegó al 100 % con este reporte (avisar al supervisor)
     */
    public function reportar(int $asignacionId, array $user, string $texto, int $cantidad, array $archivos = [], ?int $ticketId = null): array
    {
        if (!$this->conReportes()) {
            throw new ValidationException(['Falta importar la migración de la hoja de reportes de tareas.']);
        }
        if (AdminLocalService::bloqueado($user)) {
            throw new ValidationException(['Registra el cambio de tu contraseña de admin local para continuar.']);
        }
        $a = $this->asignacion($asignacionId);
        if ($a === null || (int) $a['agente_id'] !== (int) $user['id']) {
            throw new ValidationException(['Solo el agente asignado escribe en su hoja de reportes.']);
        }
        if (!in_array($a['estado'], ['asignada', 'en_proceso', 'devuelta'], true)) {
            throw new ValidationException(['La tarea ya está cerrada; no admite más reportes.']);
        }
        $texto = trim(str_replace(["\r\n", "\r"], "\n", $texto));
        if (mb_strlen($texto) < 3 || mb_strlen($texto) > 3000) {
            throw new ValidationException(['Escribe el reporte (3 a 3000 caracteres).']);
        }
        if ($cantidad < 0 || $cantidad > 100000) {
            throw new ValidationException(['La cantidad atendida no es válida.']);
        }
        $meta = isset($a['meta_cantidad']) ? (int) $a['meta_cantidad'] : null;
        $propia = !$this->pdo->inTransaction();
        if ($propia) {
            $this->pdo->beginTransaction();
        }
        try {
            $this->pdo->prepare('INSERT INTO tarea_avances (asignacion_id, texto, cantidad, ticket_id, creado_por) VALUES (?, ?, ?, ?, ?)')
                ->execute([$asignacionId, $texto, $meta !== null ? $cantidad : 0, $ticketId, (int) $user['id']]);
            $avanceId = (int) $this->pdo->lastInsertId();
            $this->guardarArchivos((int) $a['tarea_id'], $asignacionId, (int) $user['id'], $archivos, $avanceId);
            if ($a['estado'] !== 'en_proceso') {
                $this->pdo->prepare("UPDATE tarea_asignaciones SET estado = 'en_proceso' WHERE id = ?")->execute([$asignacionId]);
            }
            ['hecho' => $hecho, 'porcentaje' => $pct] = $this->progreso($a);
            $completa = false;
            if ($pct === 100) {
                $upd = $this->pdo->prepare('UPDATE tarea_asignaciones SET completa_avisada_en = NOW() WHERE id = ? AND completa_avisada_en IS NULL');
                $upd->execute([$asignacionId]);
                $completa = $upd->rowCount() === 1;
            }
            if ($propia) {
                $this->pdo->commit();
            }
        } catch (Throwable $e) {
            if ($propia && $this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }
            throw $e;
        }
        return ['porcentaje' => $pct, 'hecho' => $hecho, 'completa' => $completa];
    }

    /**
     * Avance de una asignación. Con lista de equipos el avance es de la tarea
     * (equipos intervenidos / equipos de la lista); si no, lo reportado por el
     * agente contra su meta.
     *
     * @param array<string, mixed> $a fila de asignacion()
     * @return array{hecho: int, meta: ?int, porcentaje: ?int, lista: bool}
     */
    public function progreso(array $a): array
    {
        $lista = $this->totalesLista((int) $a['tarea_id']);
        if ($lista['total'] > 0) {
            return ['hecho' => $lista['atendidos'], 'meta' => $lista['total'], 'porcentaje' => self::porcentaje($lista['atendidos'], $lista['total']), 'lista' => true];
        }
        $meta = isset($a['meta_cantidad']) ? (int) $a['meta_cantidad'] : null;
        $hecho = $this->hecho((int) $a['id']);
        return ['hecho' => $hecho, 'meta' => $meta, 'porcentaje' => self::porcentaje($hecho, $meta), 'lista' => false];
    }

    /** @return array{total: int, atendidos: int} */
    public function totalesLista(int $tareaId): array
    {
        if (!$this->conLista()) {
            return ['total' => 0, 'atendidos' => 0];
        }
        $st = $this->pdo->prepare('SELECT COUNT(*) AS total, COALESCE(SUM(atendido_en IS NOT NULL), 0) AS atendidos FROM tarea_equipos WHERE tarea_id = ?');
        $st->execute([$tareaId]);
        $r = $st->fetch() ?: ['total' => 0, 'atendidos' => 0];
        return ['total' => (int) $r['total'], 'atendidos' => (int) $r['atendidos']];
    }

    /** @return list<array<string, mixed>> equipos de la lista de la tarea con quién y cuándo se intervinieron */
    public function listaEquipos(int $tareaId): array
    {
        if (!$this->conLista()) {
            return [];
        }
        $st = $this->pdo->prepare(
            'SELECT te.*, e.serie, e.marca, e.modelo, e.tipo_componente, u.ficha, u.nombre AS usuario_nombre,
                    ua.nombre AS atendio_nombre, t.folio AS ticket_folio
               FROM tarea_equipos te
               JOIN equipos e ON e.id = te.equipo_id
               LEFT JOIN usuarios u ON u.id = e.usuario_id
               LEFT JOIN usuarios ua ON ua.id = te.atendido_por
               LEFT JOIN tickets t ON t.id = te.ticket_id
              WHERE te.tarea_id = ? ORDER BY (te.atendido_en IS NOT NULL), e.serie'
        );
        $st->execute([$tareaId]);
        return $st->fetchAll();
    }

    /**
     * El agente marca equipos de la lista como intervenidos. Con $conTicket se
     * genera UN ticket por equipo (tipo Tarea, ligado a la tarea, con los datos
     * de la tarea y el equipo); sin él, solo un reporte en la hoja.
     *
     * @param array<string, mixed> $user
     * @param list<int|string> $equipoIds
     * @return array{tickets: list<array{id: int, folio: string}>, completa: bool}
     */
    public function intervenir(int $asignacionId, array $user, array $equipoIds, string $comentario, bool $conTicket, int $categoriaId = 0): array
    {
        $a = $this->asignacion($asignacionId);
        if ($a === null || (int) $a['agente_id'] !== (int) $user['id']) {
            throw new ValidationException(['Solo el agente asignado interviene los equipos de su tarea.']);
        }
        if (!in_array($a['estado'], ['asignada', 'en_proceso', 'devuelta'], true)) {
            throw new ValidationException(['La tarea ya está cerrada.']);
        }
        $ids = array_values(array_unique(array_filter(array_map('intval', $equipoIds), static fn (int $i): bool => $i > 0)));
        if ($ids === []) {
            throw new ValidationException(['Marca al menos un equipo.']);
        }
        $comentario = trim(str_replace(["\r\n", "\r"], "\n", $comentario));
        if (mb_strlen($comentario) > 3000) {
            throw new ValidationException(['El comentario admite hasta 3000 caracteres.']);
        }
        $pend = $this->pdo->prepare('SELECT te.id, te.equipo_id, e.serie, e.marca, e.modelo, e.tipo_componente, u.ficha, u.nombre AS usuario_nombre
                                       FROM tarea_equipos te JOIN equipos e ON e.id = te.equipo_id LEFT JOIN usuarios u ON u.id = e.usuario_id
                                      WHERE te.tarea_id = ? AND te.equipo_id = ? AND te.atendido_en IS NULL');
        $equipos = [];
        foreach ($ids as $eid) {
            $pend->execute([(int) $a['tarea_id'], $eid]);
            $row = $pend->fetch();
            if (!$row) {
                throw new ValidationException(['Uno de los equipos no está en la lista de la tarea o ya fue intervenido; recarga la página.']);
            }
            $equipos[] = $row;
        }
        $marcar = $this->pdo->prepare(
            'UPDATE tarea_equipos SET asignacion_id = ?, atendido_por = ?, atendido_en = NOW() WHERE id = ? AND atendido_en IS NULL'
        );

        if (!$conTicket) {
            $this->pdo->beginTransaction();
            try {
                foreach ($equipos as $eq) {
                    $marcar->execute([$asignacionId, (int) $user['id'], (int) $eq['id']]);
                    if ($marcar->rowCount() !== 1) {
                        throw new ValidationException(["El equipo {$eq['serie']} ya fue intervenido; recarga la página."]);
                    }
                }
                $texto = 'Equipos intervenidos (' . count($equipos) . '): ' . implode(', ', array_column($equipos, 'serie')) . ($comentario !== '' ? "\n" . $comentario : '');
                $r = $this->reportar($asignacionId, $user, $texto, count($equipos));
                $this->pdo->commit();
            } catch (Throwable $e) {
                if ($this->pdo->inTransaction()) {
                    $this->pdo->rollBack();
                }
                throw $e;
            }
            return ['tickets' => [], 'completa' => $r['completa']];
        }

        // Un ticket por equipo: cada uno en su propia transacción.
        $tickets = [];
        $completa = false;
        $svc = new TicketService($this->pdo);
        $desmarcar = $this->pdo->prepare('UPDATE tarea_equipos SET asignacion_id = NULL, atendido_por = NULL, atendido_en = NULL WHERE id = ?');
        $ligar = $this->pdo->prepare('UPDATE tarea_equipos SET ticket_id = ? WHERE id = ?');
        foreach ($equipos as $eq) {
            $marcar->execute([$asignacionId, (int) $user['id'], (int) $eq['id']]);
            if ($marcar->rowCount() !== 1) {
                continue;   // otro agente lo tomó mientras tanto
            }
            $resguardante = $eq['ficha'] ? "{$eq['ficha']} {$eq['usuario_nombre']}" : 'sin resguardante';
            try {
                $r = $svc->create($user, [
                    'tipo_ticket' => 'tarea', 'prioridad' => (string) $a['prioridad'], 'categoria_id' => (string) $categoriaId,
                    'titulo' => mb_substr("{$a['folio']} · {$eq['serie']} · {$a['titulo']}", 0, 180),
                    'descripcion' => mb_substr("Tarea {$a['folio']}: {$a['titulo']}\n\n" . trim((string) $a['instrucciones'])
                        . "\n\nEquipo: {$eq['serie']} " . trim(($eq['marca'] ?? '') . ' ' . ($eq['modelo'] ?? '')) . " · Resguardante: {$resguardante}"
                        . ($comentario !== '' ? "\n\n{$comentario}" : ''), 0, 5000),
                    'equipos' => [(string) $eq['equipo_id']], 'equipos_series' => '',
                    'tarea_asignacion_id' => (string) $asignacionId,
                    'equipo_id' => '0', 'componente_afectado' => 'general', 'hostname_reportado' => '',
                ]);
            } catch (Throwable $e) {
                $desmarcar->execute([(int) $eq['id']]);
                throw $e;
            }
            $ligar->execute([$r['id'], (int) $eq['id']]);
            $tickets[] = ['id' => (int) $r['id'], 'folio' => (string) $r['folio']];
            $completa = $completa || ($r['tarea_completa'] ?? null) !== null;
        }
        return ['tickets' => $tickets, 'completa' => $completa];
    }

    /** Suma de lo atendido en la hoja de reportes. */
    public function hecho(int $asignacionId): int
    {
        if (!$this->conReportes()) {
            return 0;
        }
        $stmt = $this->pdo->prepare('SELECT COALESCE(SUM(cantidad), 0) FROM tarea_avances WHERE asignacion_id = ?');
        $stmt->execute([$asignacionId]);
        return (int) $stmt->fetchColumn();
    }

    /** @return list<array<string, mixed>> reportes de una asignación (más recientes primero) con autor y ticket */
    public function avances(int $asignacionId): array
    {
        if (!$this->conReportes()) {
            return [];
        }
        $stmt = $this->pdo->prepare(
            'SELECT av.*, u.nombre AS autor, t.folio AS ticket_folio
               FROM tarea_avances av LEFT JOIN usuarios u ON u.id = av.creado_por LEFT JOIN tickets t ON t.id = av.ticket_id
              WHERE av.asignacion_id = ? ORDER BY av.id DESC'
        );
        $stmt->execute([$asignacionId]);
        return $stmt->fetchAll();
    }

    /** @return list<array<string, mixed>> asignaciones abiertas del agente (para vincular un ticket de TI) */
    public function abiertasDe(int $agenteId): array
    {
        return array_values(array_filter($this->deAgente($agenteId), static fn (array $r): bool => in_array($r['estado'], ['asignada', 'en_proceso', 'devuelta'], true)));
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
                       COALESCE(SUM(a.estado = 'entregada'), 0) AS por_revisar" . ($this->conReportes()
                    ? ", COALESCE(SUM(avs.hecho), 0) AS hecho,
                       COALESCE(SUM(a.completa_avisada_en IS NOT NULL AND a.estado IN ('asignada', 'en_proceso', 'devuelta')), 0) AS listas_cierre"
                    : ', 0 AS hecho, 0 AS listas_cierre')
                . ($this->conLista() ? ',
                       (SELECT COUNT(*) FROM tarea_equipos te WHERE te.tarea_id = t.id) AS lista_total,
                       (SELECT COUNT(*) FROM tarea_equipos te WHERE te.tarea_id = t.id AND te.atendido_en IS NOT NULL) AS lista_hecho' : ', 0 AS lista_total, 0 AS lista_hecho') . "
                  FROM tareas t
                  LEFT JOIN usuarios u ON u.id = t.supervisor_id
                  LEFT JOIN tarea_asignaciones a ON a.tarea_id = t.id"
            . ($this->conReportes() ? ' LEFT JOIN (SELECT asignacion_id, SUM(cantidad) AS hecho FROM tarea_avances GROUP BY asignacion_id) avs ON avs.asignacion_id = a.id' : '');
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
                    u.nombre AS supervisor_nombre"
            . ($this->conReportes()
                ? ', t.meta_unidad, t.meta_descripcion, t.meta_cantidad, (SELECT COALESCE(SUM(av.cantidad), 0) FROM tarea_avances av WHERE av.asignacion_id = a.id) AS hecho'
                    . ($this->conLista() ? ', (SELECT COUNT(*) FROM tarea_equipos te WHERE te.tarea_id = t.id) AS lista_total,
                       (SELECT COUNT(*) FROM tarea_equipos te WHERE te.tarea_id = t.id AND te.atendido_en IS NOT NULL) AS lista_hecho' : ', 0 AS lista_total, 0 AS lista_hecho')
                : ', NULL AS meta_unidad, NULL AS meta_descripcion, NULL AS meta_cantidad, 0 AS hecho') . "
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
        $meta = isset($t['meta_cantidad']) ? (int) $t['meta_cantidad'] : null;
        $lista = $this->totalesLista($tareaId);
        $t['lista'] = $lista['total'] > 0 ? $this->listaEquipos($tareaId) : [];
        $hechoTotal = 0;
        foreach ($t['asignaciones'] as $i => $asig) {
            $hecho = $this->hecho((int) $asig['id']);
            $hechoTotal += $hecho;
            $t['asignaciones'][$i]['hecho'] = $hecho;
            // Con lista, el avance es de toda la tarea (los equipos se reparten entre agentes).
            $t['asignaciones'][$i]['porcentaje'] = $lista['total'] > 0
                ? self::porcentaje($lista['atendidos'], $lista['total'])
                : self::porcentaje($hecho, $meta);
            $t['asignaciones'][$i]['avances'] = $this->avances((int) $asig['id']);
        }
        if ($lista['total'] > 0) {
            $t['hecho'] = $lista['atendidos'];
            $t['meta_total'] = $lista['total'];
        } else {
            $t['hecho'] = $hechoTotal;
            $t['meta_total'] = $meta !== null ? $meta * count($t['asignaciones']) : null;
        }
        $t['porcentaje'] = self::porcentaje((int) $t['hecho'], $t['meta_total']);
        $f = $this->pdo->prepare('SELECT * FROM tarea_archivos WHERE tarea_id = ? ORDER BY id');
        $f->execute([$tareaId]);
        $t['archivos'] = $f->fetchAll();
        return $t;
    }

    /** @return array<string, mixed>|null */
    public function asignacion(int $id): ?array
    {
        $stmt = $this->pdo->prepare(
            'SELECT a.*, t.supervisor_id, t.evidencias_requeridas, t.folio, t.titulo, t.entrega_en, t.prioridad, t.instrucciones'
            . ($this->conReportes() ? ', t.meta_cantidad, t.meta_unidad, t.meta_descripcion' : ', NULL AS meta_cantidad, NULL AS meta_unidad, NULL AS meta_descripcion') . '
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
    private function guardarArchivos(int $tareaId, ?int $asignacionId, int $por, array $archivos, ?int $avanceId = null): void
    {
        if ($avanceId !== null) {
            $ins = $this->pdo->prepare(
                'INSERT INTO tarea_archivos (tarea_id, asignacion_id, avance_id, nombre_original, ruta, mime, tamano, subido_por) VALUES (?, ?, ?, ?, ?, ?, ?, ?)'
            );
            foreach ($archivos as $f) {
                $ins->execute([$tareaId, $asignacionId, $avanceId, $f['nombre_original'], $f['ruta'], $f['mime'], $f['tamano'], $por]);
            }
            return;
        }
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
