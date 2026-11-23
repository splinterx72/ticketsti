<?php

declare(strict_types=1);

namespace App\Services;

use App\Core\Roles;
use App\Core\ValidationException;
use PDO;
use Throwable;

/**
 * Reglas de negocio de tickets (CLAUDE.md, secciones 3.C y 3.D).
 *
 *  - Equipos del usuario ordenados por es_principal DESC.
 *  - Hostname manual con prefijo PM + 3 caracteres.
 *  - Componente afectado (equipo, monitor_1, monitor_2, general).
 *  - Ubicación reportada vs. ubicación GECA: si difiere se actualiza
 *    equipos.ubicacion_real_id, se marca discrepancia_ubicacion = 1 y se
 *    genera (una sola vez por equipo) un ticket "actualizacion_ubicacion"
 *    para Control de Resguardos GECA.
 */
final class TicketService
{
    public const HOSTNAME_PATTERN = '/^PM[A-Z0-9]{3}[A-Z0-9-]{1,25}$/';

    public function __construct(private readonly PDO $pdo)
    {
    }

    // ==================================================================
    //  Consultas
    // ==================================================================

    /**
     * Equipos vigentes o no del usuario, con el principal primero.
     *
     * @return list<array<string, mixed>>
     */
    public function equiposDeUsuario(int $usuarioId): array
    {
        $stmt = $this->pdo->prepare(
            'SELECT e.id, e.serie, e.marca, e.modelo, e.perfil, e.es_principal, e.vigente_en_geca,
                    e.serie_monitor1, e.marca_monitor1, e.modelo_monitor1,
                    e.serie_monitor2, e.marca_monitor2, e.modelo_monitor2,
                    e.ubicacion_geca_id, e.ubicacion_real_id,
                    ug.eps AS g_eps, ug.subdireccion AS g_subdireccion, ug.gerencia AS g_gerencia,
                    ug.zona AS g_zona, ug.sector AS g_sector, ug.inmueble AS g_inmueble,
                    ur.eps AS r_eps, ur.subdireccion AS r_subdireccion, ur.gerencia AS r_gerencia,
                    ur.zona AS r_zona, ur.sector AS r_sector, ur.inmueble AS r_inmueble
               FROM equipos e
               LEFT JOIN ubicaciones ug ON ug.id = e.ubicacion_geca_id
               LEFT JOIN ubicaciones ur ON ur.id = e.ubicacion_real_id
              WHERE e.usuario_id = ?
              ORDER BY e.es_principal DESC, e.vigente_en_geca DESC, e.serie'
        );
        $stmt->execute([$usuarioId]);

        $rows = [];
        foreach ($stmt->fetchAll() as $r) {
            $r['ubicacion_geca'] = $r['ubicacion_geca_id'] !== null ? Catalog::etiquetaUbicacion(self::prefixed($r, 'g_')) : null;
            $r['ubicacion_real'] = $r['ubicacion_real_id'] !== null ? Catalog::etiquetaUbicacion(self::prefixed($r, 'r_')) : null;
            $rows[] = $r;
        }
        return $rows;
    }

    /** @return array<string, mixed>|null */
    public function find(int $id): ?array
    {
        $stmt = $this->pdo->prepare(
            'SELECT t.*,
                    s.nombre AS solicitante_nombre, s.ficha AS solicitante_ficha, s.email AS solicitante_email,
                    s.telefono_ext AS solicitante_ext, s.jefe_nombre AS solicitante_jefe,
                    a.nombre AS agente_nombre,
                    d.nombre AS departamento_nombre,
                    es.nombre AS estado_nombre, es.color_hex AS estado_color, es.es_cierre,
                    e.serie, e.marca, e.modelo, e.serie_monitor1, e.marca_monitor1, e.modelo_monitor1,
                    e.serie_monitor2, e.marca_monitor2, e.modelo_monitor2, e.ubicacion_geca_id,
                    ur.eps AS r_eps, ur.subdireccion AS r_subdireccion, ur.gerencia AS r_gerencia,
                    ur.zona AS r_zona, ur.sector AS r_sector, ur.inmueble AS r_inmueble,
                    ug.eps AS g_eps, ug.subdireccion AS g_subdireccion, ug.gerencia AS g_gerencia,
                    ug.zona AS g_zona, ug.sector AS g_sector, ug.inmueble AS g_inmueble
               FROM tickets t
               JOIN usuarios s       ON s.id = t.usuario_solicitante_id
               LEFT JOIN usuarios a  ON a.id = t.agente_asignado_id
               JOIN departamentos d  ON d.id = t.departamento_id
               JOIN estados es       ON es.id = t.estado_id
               LEFT JOIN equipos e   ON e.id = t.equipo_id
               LEFT JOIN ubicaciones ur ON ur.id = t.ubicacion_reportada_id
               LEFT JOIN ubicaciones ug ON ug.id = e.ubicacion_geca_id
              WHERE t.id = ?'
        );
        $stmt->execute([$id]);
        $t = $stmt->fetch();
        if (!$t) {
            return null;
        }
        $t['ubicacion_reportada'] = $t['ubicacion_reportada_id'] !== null ? Catalog::etiquetaUbicacion(self::prefixed($t, 'r_')) : null;
        $t['ubicacion_geca'] = $t['ubicacion_geca_id'] !== null ? Catalog::etiquetaUbicacion(self::prefixed($t, 'g_')) : null;
        return $t;
    }

    /** @return list<array<string, mixed>> */
    public function comentarios(int $ticketId, bool $incluirInternas): array
    {
        $sql = 'SELECT c.id, c.mensaje, c.es_nota_interna, c.creado_en, c.usuario_id,
                       u.nombre AS autor, u.rol AS autor_rol
                  FROM comentarios c
                  JOIN usuarios u ON u.id = c.usuario_id
                 WHERE c.ticket_id = ?' . ($incluirInternas ? '' : ' AND c.es_nota_interna = 0') . '
                 ORDER BY c.creado_en, c.id';
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute([$ticketId]);
        return $stmt->fetchAll();
    }

    // ==================================================================
    //  Alta de ticket
    // ==================================================================

    /**
     * @param array<string, mixed>  $user  usuario autenticado
     * @param array<string, string> $in    datos del formulario
     * @return array{id: int, folio: string, discrepancia: bool, vinculado: ?string}
     */
    public function create(array $user, array $in): array
    {
        $userId = (int) $user['id'];
        $esTi = Roles::esTi($user['rol']);
        $errors = [];

        $tipo = $in['tipo_ticket'] ?? '';
        if (!in_array($tipo, Catalog::TIPOS_USUARIO, true)) {
            $errors[] = 'Selecciona el tipo de ticket.';
        }
        $prioridad = $in['prioridad'] ?? 'media';
        $permitidas = $esTi ? array_keys(Catalog::PRIORIDADES) : Catalog::PRIORIDADES_USUARIO;
        if (!in_array($prioridad, $permitidas, true)) {
            $errors[] = 'Selecciona una prioridad válida.';
        }

        $titulo = self::cleanLine($in['titulo'] ?? '');
        if (mb_strlen($titulo) < 5 || mb_strlen($titulo) > 180) {
            $errors[] = 'El título debe tener entre 5 y 180 caracteres.';
        }
        $descripcion = self::cleanText($in['descripcion'] ?? '');
        if (mb_strlen($descripcion) < 10 || mb_strlen($descripcion) > 5000) {
            $errors[] = 'Describe el problema con al menos 10 caracteres (máximo 5000).';
        }

        // Equipo (debe pertenecer al usuario) y componente.
        $equipo = null;
        $equipoId = (int) ($in['equipo_id'] ?? 0);
        if ($equipoId > 0) {
            foreach ($this->equiposDeUsuario($userId) as $eq) {
                if ((int) $eq['id'] === $equipoId) {
                    $equipo = $eq;
                    break;
                }
            }
            if ($equipo === null) {
                $errors[] = 'El equipo seleccionado no está asignado a tu ficha.';
            }
        }

        $componente = $in['componente_afectado'] ?? 'general';
        if ($equipo === null) {
            $componente = 'general';
        } else {
            $validos = ['equipo', 'general'];
            if (!empty($equipo['serie_monitor1'])) {
                $validos[] = 'monitor_1';
            }
            if (!empty($equipo['serie_monitor2'])) {
                $validos[] = 'monitor_2';
            }
            if (!in_array($componente, $validos, true)) {
                $errors[] = 'El componente seleccionado no corresponde a ese equipo.';
            }
        }

        $hostname = strtoupper((string) preg_replace('/\s+/', '', $in['hostname_reportado'] ?? ''));
        if ($hostname !== '' && preg_match(self::HOSTNAME_PATTERN, $hostname) !== 1) {
            $errors[] = 'El hostname debe iniciar con "PM" + 3 caracteres y la serie (ej. PMPPP' . ($equipo['serie'] ?? '5CG1234XYZ') . '), máximo 30 caracteres.';
        }

        // Ubicación física reportada.
        $ubicacionId = (int) ($in['ubicacion_reportada_id'] ?? 0);
        if ($ubicacionId > 0 && !Catalog::ubicacionExiste($ubicacionId)) {
            $errors[] = 'La ubicación seleccionada no existe.';
        }
        if ($equipo !== null && $ubicacionId <= 0) {
            $errors[] = 'Confirma en qué ubicación se encuentra físicamente el equipo.';
        }

        if ($errors !== []) {
            throw new ValidationException($errors);
        }

        $ubicacionId = $ubicacionId > 0 ? $ubicacionId : null;
        $gecaId = $equipo !== null && $equipo['ubicacion_geca_id'] !== null ? (int) $equipo['ubicacion_geca_id'] : null;
        $discrepancia = $equipo !== null && $ubicacionId !== null && $gecaId !== null && $ubicacionId !== $gecaId;

        $this->pdo->beginTransaction();
        try {
            $id = $this->insertTicket([
                'tipo_ticket'            => $tipo,
                'titulo'                 => $titulo,
                'descripcion'            => $descripcion,
                'prioridad'              => $prioridad,
                'usuario_solicitante_id' => $userId,
                'departamento_id'        => Catalog::departamentoParaTipo($tipo),
                'estado_id'              => Catalog::estadoIdPorNombre(Catalog::ESTADO_INICIAL),
                'equipo_id'              => $equipo !== null ? (int) $equipo['id'] : null,
                'componente_afectado'    => $componente,
                'hostname_reportado'     => $hostname !== '' ? $hostname : null,
                'ubicacion_reportada_id' => $ubicacionId,
                'discrepancia_ubicacion' => $discrepancia ? 1 : 0,
            ]);
            $folio = self::folio($id);

            $vinculado = null;
            if ($equipo !== null && $ubicacionId !== null) {
                // La ubicación real del equipo siempre refleja lo último que reportó el usuario.
                if ((int) ($equipo['ubicacion_real_id'] ?? 0) !== $ubicacionId) {
                    $upd = $this->pdo->prepare('UPDATE equipos SET ubicacion_real_id = ? WHERE id = ?');
                    $upd->execute([$ubicacionId, (int) $equipo['id']]);
                }
                if ($discrepancia) {
                    $vinculado = $this->registrarDiscrepancia($equipo, $userId, $folio, $ubicacionId);
                }
            }

            $this->pdo->commit();
        } catch (Throwable $e) {
            if ($this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }
            throw $e;
        }

        return ['id' => $id, 'folio' => $folio, 'discrepancia' => $discrepancia, 'vinculado' => $vinculado];
    }

    /**
     * Genera el ticket "actualizacion_ubicacion" para Resguardos, o agrega
     * una nota al que ya esté abierto para ese equipo (evita duplicados).
     *
     * @param array<string, mixed> $equipo
     */
    private function registrarDiscrepancia(array $equipo, int $userId, string $folioOrigen, int $ubicacionId): string
    {
        $etiquetaReal = $this->etiquetaUbicacionPorId($ubicacionId);
        $etiquetaGeca = (string) ($equipo['ubicacion_geca'] ?? 'sin ubicación en GECA');
        $cierre = Catalog::estadosCierreIds();

        $sql = "SELECT id, folio FROM tickets
                 WHERE equipo_id = ? AND tipo_ticket = 'actualizacion_ubicacion'";
        $params = [(int) $equipo['id']];
        if ($cierre !== []) {
            $sql .= ' AND estado_id NOT IN (' . implode(',', array_fill(0, count($cierre), '?')) . ')';
            $params = array_merge($params, $cierre);
        }
        $sql .= ' ORDER BY id DESC LIMIT 1';
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute($params);
        $abierto = $stmt->fetch();

        if ($abierto) {
            $this->insertComment(
                (int) $abierto['id'],
                $userId,
                "[Sistema] El ticket {$folioOrigen} volvió a reportar el equipo {$equipo['serie']} en: {$etiquetaReal}.",
                true
            );
            return (string) $abierto['folio'];
        }

        $id = $this->insertTicket([
            'tipo_ticket'            => 'actualizacion_ubicacion',
            'titulo'                 => mb_substr("Actualizar resguardo GECA — serie {$equipo['serie']}", 0, 180),
            'descripcion'            => "Generado automáticamente desde el ticket {$folioOrigen}.\n\n"
                . "Equipo: {$equipo['serie']} " . trim(($equipo['marca'] ?? '') . ' ' . ($equipo['modelo'] ?? '')) . "\n"
                . "Ubicación en GECA: {$etiquetaGeca}\n"
                . "Ubicación física reportada: {$etiquetaReal}\n\n"
                . 'Tramitar el cambio de resguardo en GECA para que coincida con la ubicación física.',
            'prioridad'              => 'media',
            'usuario_solicitante_id' => $userId,
            'departamento_id'        => Catalog::departamentoParaTipo('actualizacion_ubicacion'),
            'estado_id'              => Catalog::estadoIdPorNombre(Catalog::ESTADO_INICIAL),
            'equipo_id'              => (int) $equipo['id'],
            'componente_afectado'    => 'equipo',
            'hostname_reportado'     => null,
            'ubicacion_reportada_id' => $ubicacionId,
            'discrepancia_ubicacion' => 1,
        ]);
        return self::folio($id);
    }

    /** @param array<string, mixed> $d */
    private function insertTicket(array $d): int
    {
        // Folio temporal único; se reemplaza por TK-AAAA-NNNNNN con el id real.
        $stmt = $this->pdo->prepare(
            'INSERT INTO tickets (folio, tipo_ticket, titulo, descripcion, prioridad, usuario_solicitante_id,
                                  departamento_id, estado_id, equipo_id, componente_afectado, hostname_reportado,
                                  ubicacion_reportada_id, discrepancia_ubicacion)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)'
        );
        $stmt->execute([
            'TMP-' . bin2hex(random_bytes(8)),
            $d['tipo_ticket'], $d['titulo'], $d['descripcion'], $d['prioridad'], $d['usuario_solicitante_id'],
            $d['departamento_id'], $d['estado_id'], $d['equipo_id'], $d['componente_afectado'], $d['hostname_reportado'],
            $d['ubicacion_reportada_id'], $d['discrepancia_ubicacion'],
        ]);
        $id = (int) $this->pdo->lastInsertId();
        $upd = $this->pdo->prepare('UPDATE tickets SET folio = ? WHERE id = ?');
        $upd->execute([self::folio($id), $id]);
        return $id;
    }

    public static function folio(int $id): string
    {
        return sprintf('TK-%s-%06d', date('Y'), $id);
    }

    // ==================================================================
    //  Seguimiento
    // ==================================================================

    /**
     * Comentario del solicitante o de TI.
     *
     * @param array<string, mixed> $ticket
     * @param array<string, mixed> $user
     */
    public function addComment(array $ticket, array $user, string $mensaje, bool $interna): void
    {
        $esTi = Roles::esTi($user['rol']);
        $mensaje = self::cleanText($mensaje);
        $errors = [];
        if (mb_strlen($mensaje) < 2 || mb_strlen($mensaje) > 5000) {
            $errors[] = 'El comentario debe tener entre 2 y 5000 caracteres.';
        }
        if (!$esTi && (int) $ticket['es_cierre'] === 1) {
            $errors[] = 'Este ticket ya está cerrado. Si el problema continúa, crea un ticket nuevo.';
        }
        if ($errors !== []) {
            throw new ValidationException($errors);
        }

        $this->pdo->beginTransaction();
        try {
            $this->insertComment((int) $ticket['id'], (int) $user['id'], $mensaje, $esTi && $interna);

            // Si TI esperaba respuesta del usuario, el ticket vuelve a "En proceso".
            $esperaId = Catalog::estadoIdPorNombre(Catalog::ESTADO_ESPERA_USUARIO);
            if (!$esTi && (int) $ticket['estado_id'] === $esperaId
                && mb_strtolower((string) $ticket['estado_nombre']) === mb_strtolower(Catalog::ESTADO_ESPERA_USUARIO)) {
                $nuevo = Catalog::estadoIdPorNombre(Catalog::ESTADO_EN_PROCESO);
                $this->pdo->prepare('UPDATE tickets SET estado_id = ? WHERE id = ?')->execute([$nuevo, (int) $ticket['id']]);
                $this->insertComment((int) $ticket['id'], (int) $user['id'], '[Sistema] El usuario respondió; estado: En espera de usuario → En proceso.', false);
            } else {
                // Actualiza actualizado_en para que suba en la bandeja.
                $this->pdo->prepare('UPDATE tickets SET actualizado_en = CURRENT_TIMESTAMP WHERE id = ?')->execute([(int) $ticket['id']]);
            }
            $this->pdo->commit();
        } catch (Throwable $e) {
            if ($this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }
            throw $e;
        }
    }

    /**
     * Gestión por TI: estado, prioridad, departamento y agente asignado.
     * Cada cambio queda en la bitácora del ticket, visible para el solicitante.
     *
     * @param array<string, mixed>  $ticket
     * @param array<string, mixed>  $agente
     * @param array<string, string> $in
     * @return int número de cambios aplicados
     */
    public function manage(array $ticket, array $agente, array $in): int
    {
        $errors = [];

        $estadoId = (int) ($in['estado_id'] ?? 0);
        $estado = Catalog::estado($estadoId);
        if ($estado === null) {
            $errors[] = 'Estado inválido.';
        }
        $prioridad = $in['prioridad'] ?? '';
        if (!array_key_exists($prioridad, Catalog::PRIORIDADES)) {
            $errors[] = 'Prioridad inválida.';
        }
        $deptoId = (int) ($in['departamento_id'] ?? 0);
        $depto = null;
        foreach (Catalog::departamentos() as $d) {
            if ($d['id'] === $deptoId) {
                $depto = $d;
            }
        }
        if ($depto === null) {
            $errors[] = 'Departamento inválido.';
        }
        $agenteId = (int) ($in['agente_asignado_id'] ?? 0);
        $agenteNuevo = null;
        if ($agenteId > 0) {
            foreach (Catalog::agentes() as $a) {
                if ($a['id'] === $agenteId) {
                    $agenteNuevo = $a;
                }
            }
            if ($agenteNuevo === null) {
                $errors[] = 'El agente seleccionado no es válido.';
            }
        }
        if ($errors !== []) {
            throw new ValidationException($errors);
        }

        $cambios = [];
        if ($estadoId !== (int) $ticket['estado_id']) {
            $cambios[] = "estado: {$ticket['estado_nombre']} → {$estado['nombre']}";
        }
        if ($prioridad !== $ticket['prioridad']) {
            $cambios[] = 'prioridad: ' . Catalog::PRIORIDADES[$ticket['prioridad']] . ' → ' . Catalog::PRIORIDADES[$prioridad];
        }
        if ($deptoId !== (int) $ticket['departamento_id']) {
            $cambios[] = "departamento: {$ticket['departamento_nombre']} → {$depto['nombre']}";
        }
        if ($agenteId !== (int) ($ticket['agente_asignado_id'] ?? 0)) {
            $cambios[] = 'asignado: ' . ($ticket['agente_nombre'] ?? 'nadie') . ' → ' . ($agenteNuevo['nombre'] ?? 'nadie');
        }
        if ($cambios === []) {
            return 0;
        }

        $this->pdo->beginTransaction();
        try {
            $stmt = $this->pdo->prepare(
                'UPDATE tickets
                    SET estado_id = ?, prioridad = ?, departamento_id = ?, agente_asignado_id = ?,
                        cerrado_en = CASE WHEN ? = 1 THEN COALESCE(cerrado_en, CURRENT_TIMESTAMP) ELSE NULL END
                  WHERE id = ?'
            );
            $stmt->execute([$estadoId, $prioridad, $deptoId, $agenteId > 0 ? $agenteId : null, $estado['es_cierre'], (int) $ticket['id']]);
            // Visible para el solicitante: así sigue el avance de su ticket.
            $this->insertComment((int) $ticket['id'], (int) $agente['id'], '[Sistema] ' . ucfirst(implode('; ', $cambios)) . '.', false);
            $this->pdo->commit();
        } catch (Throwable $e) {
            if ($this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }
            throw $e;
        }
        return count($cambios);
    }

    // ==================================================================
    //  Utilidades
    // ==================================================================

    private function insertComment(int $ticketId, int $userId, string $mensaje, bool $interna): void
    {
        $stmt = $this->pdo->prepare(
            'INSERT INTO comentarios (ticket_id, usuario_id, mensaje, es_nota_interna) VALUES (?, ?, ?, ?)'
        );
        $stmt->execute([$ticketId, $userId, $mensaje, $interna ? 1 : 0]);
    }

    private function etiquetaUbicacionPorId(int $id): string
    {
        $stmt = $this->pdo->prepare('SELECT eps, subdireccion, gerencia, zona, sector, inmueble FROM ubicaciones WHERE id = ?');
        $stmt->execute([$id]);
        $u = $stmt->fetch();
        return $u ? Catalog::etiquetaUbicacion($u) : "Ubicación #{$id}";
    }

    /**
     * @param array<string, mixed> $row
     * @return array<string, mixed>
     */
    private static function prefixed(array $row, string $prefix): array
    {
        $out = [];
        foreach (['eps', 'subdireccion', 'gerencia', 'zona', 'sector', 'inmueble'] as $f) {
            $out[$f] = $row[$prefix . $f] ?? '';
        }
        return $out;
    }

    private static function cleanLine(string $value): string
    {
        return trim((string) preg_replace('/\s+/u', ' ', $value));
    }

    /** Conserva saltos de línea, elimina caracteres de control y espacios sobrantes. */
    private static function cleanText(string $value): string
    {
        $value = str_replace(["\r\n", "\r"], "\n", $value);
        $value = (string) preg_replace('/[^\P{C}\n\t]/u', '', $value);
        $value = (string) preg_replace("/\n{3,}/", "\n\n", $value);
        return trim($value);
    }
}
