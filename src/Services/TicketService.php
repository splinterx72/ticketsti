<?php

declare(strict_types=1);

namespace App\Services;

use App\Core\Folio;
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
            'SELECT e.id, e.serie, e.tipo_componente, e.marca, e.modelo, e.perfil, e.es_principal, e.vigente_en_geca,
                    e.real_ubicacion_fisica_id, e.real_nivel_id, e.real_ala,
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
              ORDER BY e.es_principal DESC, e.vigente_en_geca DESC,
                       (e.tipo_componente IS NULL OR e.tipo_componente IN (\'' . implode("', '", GecaSyncService::COMPUTADORAS) . '\')) DESC,
                       e.serie'
        );
        $stmt->execute([$usuarioId]);

        $rows = [];
        $fisicas = new UbicacionFisicaService($this->pdo);
        foreach ($stmt->fetchAll() as $r) {
            $r['ubicacion_geca'] = $r['ubicacion_geca_id'] !== null ? Catalog::etiquetaUbicacion(self::prefixed($r, 'g_')) : null;
            $r['ubicacion_real'] = $r['ubicacion_real_id'] !== null ? Catalog::etiquetaUbicacion(self::prefixed($r, 'r_')) : null;
            $r['ubicacion_fisica'] = $r['real_ubicacion_fisica_id'] !== null
                ? $fisicas->etiqueta((int) $r['real_ubicacion_fisica_id'], $r['real_nivel_id'] !== null ? (int) $r['real_nivel_id'] : null, $r['real_ala'])
                : null;
            $instReal = $r['real_ubicacion_fisica_id'] !== null ? $fisicas->instalacionDeUbicacion((int) $r['real_ubicacion_fisica_id']) : null;
            $instGeca = $fisicas->instalacionDeInmuebleGeca($r['g_inmueble'] ?? null);
            $r['discrepancia'] = $instReal !== null && $instGeca !== null && $instReal !== $instGeca;
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
                    s.telefono_ext AS solicitante_ext, s.celular AS solicitante_celular,
                    s.jefe_nombre AS solicitante_jefe, s.jefe_ficha AS solicitante_jefe_ficha,
                    cp.nombre AS creado_por_nombre, cp.email AS creado_por_email, cp.telefono_ext AS creado_por_ext, cp.celular AS creado_por_celular,
                    e.tipo_componente, e.ubicacion_referencia AS geca_referencia,
                    a.nombre AS agente_nombre,
                    d.nombre AS departamento_nombre,
                    es.nombre AS estado_nombre, es.color_hex AS estado_color, es.es_cierre,
                    e.serie, e.marca, e.modelo, e.serie_monitor1, e.marca_monitor1, e.modelo_monitor1,
                    e.serie_monitor2, e.marca_monitor2, e.modelo_monitor2, e.ubicacion_geca_id,
                    ur.eps AS r_eps, ur.subdireccion AS r_subdireccion, ur.gerencia AS r_gerencia,
                    ur.zona AS r_zona, ur.sector AS r_sector, ur.inmueble AS r_inmueble,
                    ug.eps AS g_eps, ug.subdireccion AS g_subdireccion, ug.gerencia AS g_gerencia,
                    ug.zona AS g_zona, ug.sector AS g_sector, ug.inmueble AS g_inmueble,
                    inst.nombre AS instalacion_nombre, ubf.indicaciones
               FROM tickets t
               LEFT JOIN instalaciones inst ON inst.id = t.instalacion_id
               LEFT JOIN ubicaciones_fisicas ubf ON ubf.id = t.reportada_ubicacion_fisica_id
               JOIN usuarios s       ON s.id = t.usuario_solicitante_id
               LEFT JOIN usuarios cp ON cp.id = t.creado_por_id
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
        $t['ubicacion_fisica'] = $t['reportada_ubicacion_fisica_id'] !== null
            ? (new UbicacionFisicaService($this->pdo))->etiqueta(
                (int) $t['reportada_ubicacion_fisica_id'],
                $t['reportada_nivel_id'] !== null ? (int) $t['reportada_nivel_id'] : null,
                $t['reportada_ala']
            )
            : null;
        $t['indicaciones'] = $t['indicaciones'] !== null && trim((string) $t['indicaciones']) !== '' ? (string) $t['indicaciones'] : null;
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
    public function create(array $user, array $in, array $archivos = []): array
    {
        $esTi = Roles::esTi($user['rol']);
        $errors = [];

        // A nombre de otra persona (delegación: la secretaria levanta por su jefe).
        $userId = (int) $user['id'];
        $creadoPor = null;
        $para = (int) ($in['para_usuario_id'] ?? 0);
        if ($para > 0 && $para !== $userId) {
            if (!(new DelegacionService($this->pdo))->puedeLevantarPor($userId, $para)) {
                throw new ValidationException(['No estás autorizado para levantar tickets a nombre de esa persona.']);
            }
            $creadoPor = $userId;
            $userId = $para;
        }

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
                $errors[] = $creadoPor === null
                    ? 'El equipo seleccionado no está asignado a tu ficha.'
                    : 'El equipo seleccionado no está asignado a la ficha de esa persona.';
            }
        }

        if ($tipo === 'formateo' && $equipo === null) {
            $errors[] = 'Para solicitar un formateo elige el equipo que se va a formatear.';
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

        // Ubicación física reportada (spec 7.1 / 8.1): obligatoria cuando ya
        // existe catálogo; mientras esté vacío, el ticket entra sin instalación.
        $fisica = null;
        $ubicaciones = new UbicacionFisicaService($this->pdo);
        $capturada = (int) ($in['reportada_ubicacion_fisica_id'] ?? 0) > 0;
        if ($capturada || $ubicaciones->hayCatalogo()) {
            try {
                $fisica = $ubicaciones->validar($in, 'reportada');
            } catch (ValidationException $e) {
                $errors = array_merge($errors, $e->errors());
            }
        }

        if ($errors !== []) {
            throw new ValidationException($errors);
        }

        // Regla D por instalación: inmueble GECA del equipo → instalación física.
        $instalacionGeca = $equipo !== null ? $ubicaciones->instalacionDeInmuebleGeca($equipo['g_inmueble'] ?? null) : null;
        $discrepancia = $equipo !== null && $fisica !== null && $instalacionGeca !== null
            && $instalacionGeca !== $fisica['instalacion_id'];

        $this->pdo->beginTransaction();
        try {
            [$id, $folio] = $this->insertTicket([
                'tipo_ticket'            => $tipo,
                'titulo'                 => $titulo,
                'descripcion'            => $descripcion,
                'prioridad'              => $prioridad,
                'usuario_solicitante_id' => $userId,
                'creado_por_id'          => $creadoPor,
                'departamento_id'        => Catalog::departamentoParaTipo($tipo),
                'estado_id'              => Catalog::estadoIdPorNombre(Catalog::ESTADO_INICIAL),
                'equipo_id'              => $equipo !== null ? (int) $equipo['id'] : null,
                'componente_afectado'    => $componente,
                'hostname_reportado'     => $hostname !== '' ? $hostname : null,
                'discrepancia_ubicacion' => $discrepancia ? 1 : 0,
            ] + self::camposFisicos($fisica));
            $vinculado = null;
            if ($equipo !== null && $fisica !== null) {
                // La ubicación física real del equipo siempre refleja el último reporte.
                $this->pdo->prepare(
                    'UPDATE equipos SET real_ubicacion_fisica_id = ?, real_nivel_id = ?, real_ala = ? WHERE id = ?'
                )->execute([$fisica['ubicacion_fisica_id'], $fisica['nivel_id'], $fisica['ala'], (int) $equipo['id']]);
                if ($discrepancia) {
                    $vinculado = $this->registrarDiscrepancia($equipo, $userId, $folio, $fisica);
                }
            }

            $this->guardarAdjuntos($id, null, $userId, false, $archivos);
            if ($tipo === 'formateo') {
                (new FormatoService($this->pdo))->emitir($id, $user);
            }
            $asignadoA = (new AsignadorService($this->pdo))->alCrear($id);
            $this->pdo->commit();
        } catch (Throwable $e) {
            if ($this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }
            throw $e;
        }

        return ['id' => $id, 'folio' => $folio, 'discrepancia' => $discrepancia, 'vinculado' => $vinculado, 'asignado_a' => $asignadoA];
    }

    /**
     * Genera el ticket "actualizacion_ubicacion" para Resguardos, o agrega
     * una nota al que ya esté abierto para ese equipo (evita duplicados).
     *
     * @param array<string, mixed> $equipo
     * @param array{instalacion_id: int, ubicacion_fisica_id: int, nivel_id: int, ala: string, referencia: ?string} $fisica
     */
    private function registrarDiscrepancia(array $equipo, int $userId, string $folioOrigen, array $fisica): string
    {
        $etiquetaReal = (new UbicacionFisicaService($this->pdo))
            ->etiqueta($fisica['ubicacion_fisica_id'], $fisica['nivel_id'], $fisica['ala']);
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

        [, $folio] = $this->insertTicket([
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
            'discrepancia_ubicacion' => 1,
        ] + self::camposFisicos($fisica));
        return $folio;
    }

    /** @param array<string, mixed> $d */
    /** @return array{0: int, 1: string} id y folio */
    private function insertTicket(array $d): array
    {
        // Folio temporal único; se reemplaza por TK-AAAA-NNNNNN aleatorio.
        $stmt = $this->pdo->prepare(
            'INSERT INTO tickets (folio, tipo_ticket, titulo, descripcion, prioridad, usuario_solicitante_id, creado_por_id,
                                  departamento_id, estado_id, equipo_id, componente_afectado, hostname_reportado,
                                  discrepancia_ubicacion, instalacion_id, reportada_ubicacion_fisica_id,
                                  reportada_nivel_id, reportada_ala, referencia)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)'
        );
        $stmt->execute([
            'TMP-' . bin2hex(random_bytes(8)),
            $d['tipo_ticket'], $d['titulo'], $d['descripcion'], $d['prioridad'], $d['usuario_solicitante_id'], $d['creado_por_id'] ?? null,
            $d['departamento_id'], $d['estado_id'], $d['equipo_id'], $d['componente_afectado'], $d['hostname_reportado'],
            $d['discrepancia_ubicacion'], $d['instalacion_id'], $d['reportada_ubicacion_fisica_id'],
            $d['reportada_nivel_id'], $d['reportada_ala'], $d['referencia'],
        ]);
        $id = (int) $this->pdo->lastInsertId();
        return [$id, Folio::asignar($this->pdo, 'tickets', 'TK', $id)];
    }

    /**
     * @param array{instalacion_id: int, ubicacion_fisica_id: int, nivel_id: int, ala: string, referencia: ?string}|null $fisica
     * @return array<string, mixed>
     */
    private static function camposFisicos(?array $fisica): array
    {
        return [
            'instalacion_id'                => $fisica['instalacion_id'] ?? null,
            'reportada_ubicacion_fisica_id' => $fisica['ubicacion_fisica_id'] ?? null,
            'reportada_nivel_id'            => $fisica['nivel_id'] ?? null,
            'reportada_ala'                 => $fisica['ala'] ?? null,
            'referencia'                    => $fisica['referencia'] ?? null,
        ];
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
    public function addComment(array $ticket, array $user, string $mensaje, bool $interna, array $archivos = []): void
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
            $comentarioId = $this->insertComment((int) $ticket['id'], (int) $user['id'], $mensaje, $esTi && $interna);
            $this->guardarAdjuntos((int) $ticket['id'], $comentarioId, (int) $user['id'], $esTi && $interna, $archivos);

            // Si TI esperaba respuesta del usuario, el ticket vuelve a "En proceso".
            $esperaId = Catalog::estadoIdPorNombre(Catalog::ESTADO_ESPERA_USUARIO);
            if (!$esTi && (int) $ticket['estado_id'] === $esperaId
                && mb_strtolower((string) $ticket['estado_nombre']) === mb_strtolower(Catalog::ESTADO_ESPERA_USUARIO)) {
                $nuevo = Catalog::estadoIdPorNombre(Catalog::ESTADO_EN_PROCESO);
                $this->pdo->prepare('UPDATE tickets SET estado_id = ? WHERE id = ?')->execute([$nuevo, (int) $ticket['id']]);
                $this->insertComment((int) $ticket['id'], (int) $user['id'], '[Sistema] El usuario respondió; estado: En espera de usuario → En proceso.', false);
            } else {
                // Actualiza actualizado_en para que suba en la bandeja; la primera
                // respuesta de TI (que no sea el propio solicitante) cuenta como atención.
                $atiende = $esTi && (int) $user['id'] !== (int) $ticket['usuario_solicitante_id'];
                $this->pdo->prepare(
                    'UPDATE tickets SET actualizado_en = CURRENT_TIMESTAMP'
                    . ($atiende ? ', atendido_en = COALESCE(atendido_en, CURRENT_TIMESTAMP)' : '') . ' WHERE id = ?'
                )->execute([(int) $ticket['id']]);
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
        // Folio de Remedy: llega después; TI lo captura cuando lo tenga.
        $remedy = (string) ($ticket['folio_remedy'] ?? '');
        if (array_key_exists('folio_remedy', $in)) {
            $remedy = mb_strtoupper(trim((string) $in['folio_remedy']));
            if ($remedy !== '' && preg_match('/^[A-Z0-9-]{3,40}$/', $remedy) !== 1) {
                $errors[] = 'El folio de Remedy solo admite letras, números y guiones (máximo 40).';
            }
        }
        if ($errors === [] && (int) $estado['es_cierre'] === 1 && (int) $ticket['es_cierre'] === 0) {
            $bloqueo = (new FormatoService($this->pdo))->bloqueoCierre((int) $ticket['id']);
            if ($bloqueo !== null) {
                $errors[] = $bloqueo;
            } elseif ($remedy === '' && (new Mail\CorreoAjustes($this->pdo))->exigeFolioRemedy($ticket['instalacion_id'] !== null ? (int) $ticket['instalacion_id'] : null)) {
                $errors[] = 'Captura el folio de Remedy antes de cerrar el ticket (esta instalación envía sus tickets a Remedy).';
            }
        }
        if ($errors !== []) {
            throw new ValidationException($errors);
        }

        $cambios = [];
        if ($remedy !== (string) ($ticket['folio_remedy'] ?? '')) {
            $cambios[] = 'folio Remedy: ' . ($remedy !== '' ? $remedy : 'sin folio');
        }
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
                    SET estado_id = ?, prioridad = ?, departamento_id = ?, agente_asignado_id = ?, folio_remedy = ?,
                        cerrado_en = CASE WHEN ? = 1 THEN COALESCE(cerrado_en, CURRENT_TIMESTAMP) ELSE NULL END,
                        atendido_en = COALESCE(atendido_en, CURRENT_TIMESTAMP)
                  WHERE id = ?'
            );
            $stmt->execute([$estadoId, $prioridad, $deptoId, $agenteId > 0 ? $agenteId : null, $remedy !== '' ? $remedy : null, $estado['es_cierre'], (int) $ticket['id']]);
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

    private function insertComment(int $ticketId, int $userId, string $mensaje, bool $interna): int
    {
        $stmt = $this->pdo->prepare(
            'INSERT INTO comentarios (ticket_id, usuario_id, mensaje, es_nota_interna) VALUES (?, ?, ?, ?)'
        );
        $stmt->execute([$ticketId, $userId, $mensaje, $interna ? 1 : 0]);
        return (int) $this->pdo->lastInsertId();
    }

    /**
     * @param list<array{ruta: string, nombre_original: string, mime: string, tamano: int}> $archivos ya guardados por ArchivoService
     */
    private function guardarAdjuntos(int $ticketId, ?int $comentarioId, int $userId, bool $interno, array $archivos): void
    {
        if (count($archivos) > 5) {
            throw new ValidationException(['Máximo 5 archivos por envío.']);
        }
        $stmt = $this->pdo->prepare(
            'INSERT INTO ticket_archivos (ticket_id, comentario_id, ruta, nombre_original, mime, tamano, interno, subido_por)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?)'
        );
        foreach ($archivos as $a) {
            $stmt->execute([$ticketId, $comentarioId, $a['ruta'], $a['nombre_original'], $a['mime'], (int) $a['tamano'], $interno ? 1 : 0, $userId]);
        }
    }

    /** @return list<array<string, mixed>> adjuntos del ticket (sin los de notas internas si $incluirInternos = false) */
    public function archivosDe(int $ticketId, bool $incluirInternos): array
    {
        $stmt = $this->pdo->prepare(
            'SELECT id, ticket_id, comentario_id, ruta, nombre_original, mime, tamano, interno, creado_en
               FROM ticket_archivos WHERE ticket_id = ?' . ($incluirInternos ? '' : ' AND interno = 0') . ' ORDER BY id'
        );
        $stmt->execute([$ticketId]);
        return $stmt->fetchAll();
    }

    /** @return array<string, mixed>|null */
    public function archivo(int $id): ?array
    {
        $stmt = $this->pdo->prepare('SELECT * FROM ticket_archivos WHERE id = ?');
        $stmt->execute([$id]);
        return $stmt->fetch() ?: null;
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

    /**
     * Historial de tickets de un equipo, limitado a lo que el usuario puede ver
     * (el inventario es visible para todo TI; los tickets, por instalación).
     *
     * @param array<string, mixed> $user
     * @return list<array<string, mixed>>
     */
    public function historialEquipo(int $equipoId, array $user): array
    {
        [$filtro, $params] = (new Alcance($this->pdo))->filtroTicketsSql($user, 't');
        $stmt = $this->pdo->prepare(
            "SELECT t.id, t.folio, t.titulo, t.tipo_ticket, t.prioridad, t.componente_afectado, t.creado_en,
                    es.nombre AS estado_nombre, es.color_hex AS estado_color, s.nombre AS solicitante_nombre
               FROM tickets t
               JOIN estados es ON es.id = t.estado_id
               JOIN usuarios s ON s.id = t.usuario_solicitante_id
              WHERE t.equipo_id = ? AND {$filtro}
              ORDER BY t.id DESC"
        );
        $stmt->execute(array_merge([$equipoId], $params));
        return $stmt->fetchAll();
    }
}
