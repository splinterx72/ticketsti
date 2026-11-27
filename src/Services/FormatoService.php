<?php

declare(strict_types=1);

namespace App\Services;

use App\Core\Folio;
use App\Core\Roles;
use App\Core\ValidationException;
use PDO;
use Throwable;

/**
 * Formatos oficiales con firmas (hoy: autorización de formateo de equipo).
 *
 *  - Plantillas por año (formato_plantillas): logos, textos, motivos y métodos.
 *    Si no hay versión del año en curso se usa la más reciente; si no existe
 *    ninguna se crea con el texto oficial (DEFAULT).
 *  - Datos automáticos: usuario y extensión de su cuenta; organismo,
 *    subdirección y gerencia del GECA; ubicación física del ticket; equipo;
 *    inventario (lo captura TI una vez); folio Remedy del ticket; ciudad de la
 *    instalación. Mientras no se complete refleja lo capturado después; al
 *    completarse se congela (formatos.datos) y ya no cambia.
 *  - Firmas en orden: usuario → responsable (jefe) → TI. Casos: otro jefe de
 *    área por ficha; el propio usuario como responsable con motivo; firma en
 *    sesión o presencial en el dispositivo del técnico; o en papel (autógrafa).
 *  - Candado: el ticket no se cierra con un formato pendiente.
 */
final class FormatoService
{
    public const ROLES = ['usuario' => 'Usuario del equipo', 'responsable' => 'Responsable que autoriza', 'ti' => 'Responsable de TI'];
    private const PENDIENTE = ['usuario' => 'pendiente_usuario', 'responsable' => 'pendiente_responsable', 'ti' => 'pendiente_ti'];
    private const SIGUIENTE = ['usuario' => 'pendiente_responsable', 'responsable' => 'pendiente_ti', 'ti' => 'completo'];
    public const MODOS = ['sesion' => 'en su sesión', 'presencial' => 'en persona', 'autografa' => 'en papel'];
    private const MAX_FIRMA = 300 * 1024;

    /** Etiquetas del formato vigente (editables en Gestión › Formatos). */
    public const ETIQUETAS = [
        'usuario' => 'Usuario Responsable', 'ficha' => 'Ficha', 'organismo' => 'Dirección/Organización', 'ext' => 'Ext.',
        'subdireccion' => 'Subdirección:', 'gerencia' => 'Gerencia', 'ubicacion' => 'Ubicación Oficina', 'remedy' => 'Ticket Remedy',
        'tipo' => 'Tipo de Equipo', 'marca' => 'Marca', 'modelo' => 'Modelo', 'serie' => 'N° de Serie',
        'inventario' => 'Inventario PEMEX o Compañía propietaria', 'motivo' => 'Descripción del Motivo del Borrado', 'aplica' => 'Aplica',
    ];

    /** Etiquetas con que se firmaron las versiones anteriores (sin "etiquetas" guardadas). */
    public const ETIQUETAS_ANTERIORES = [
        'usuario' => 'Usuario Responsable:', 'ficha' => 'Ficha:', 'organismo' => 'Dirección /Organismo:', 'ext' => 'Ext:',
        'subdireccion' => 'Subdirección:', 'gerencia' => 'Gerencia:', 'ubicacion' => 'Ubicación Oficina:', 'remedy' => 'Ticket Remedy:',
        'tipo' => 'Tipo de Equipo', 'marca' => 'Marca', 'modelo' => 'Modelo', 'serie' => 'N° de Serie',
        'inventario' => 'Inventario PEMEX (Cuando Aplique)', 'motivo' => 'Descripción del motivo del formateo', 'aplica' => 'Aplica',
    ];

    /** Texto oficial de cada formato (base de la primera versión). */
    public const DEFAULT = [
        'formateo' => [
            'titulo' => 'Formato de autorización de formateo de equipo',
            'contenido' => [
                'encabezado' => ['DIRECCIÓN DE ADMINISTRACIÓN Y SERVICIOS', 'SUBDIRECCIÓN DE TECNOLOGÍAS DE LA INFORMACIÓN'],
                'lugar_omision' => 'Ciudad del Carmen, Campeche',
                'organismo_omision' => 'PETRÓLEOS MEXICANOS',
                'seccion_usuario' => 'Datos del Usuario Responsable del equipo a formatear:',
                'nota_resguardo' => 'Nota: Se validará e incluirá copia del resguardo del equipo a nombre del usuario Responsable.',
                'intro' => 'En Apego a lo establecido en las Políticas para Usuarios Finales de Tecnología de la Información en Petróleos Mexicanos, Sus empresas Productivas Subsidiaria y, en su caso, empresas Filiales, Sección II Políticas, en sus apartados:',
                'parrafos' => [
                    'II.1.3. El Usuario Final es responsable del uso, manejo, cuidado, guarda y custodia de los Bienes de TI/TO y Servicios de TI que le son asignados y de la información que reside en ellos, en consecuencia, será responsable de las actividades que a través de ellos se realicen, y de acuerdo con la legislación vigente en caso de robo, extravío, daño, mal uso o pérdida. Los Usuarios deben reportar el robo, pérdida, mal uso, o uso no autorizado de los Bienes de TI/TO y/o Servicios de TI. al momento de identificarlo, a través de la MATI.',
                    'II.1.11 En los Equipos de Cómputo o Dispositivos Móviles que hayan tenido acceso a Servicios de e Información de PEMEX, EPS y, en su caso, EF, previo a que se den de baja o se desincorporen, el Usuario Final debe asegurarse de respaldar la Información de PEMEX, EPS y en su caso, EF contenida en ellos, en los medios indicados en las presentes Políticas (véase II.1.27) y de eliminar la información en dichos equipos solicitando a la MATI efectuar el borrado seguro.',
                ],
                'subtitulo' => 'Respaldo y resguardo de información.',
                'parrafos2' => [
                    'II.1.27. Los usuarios Finales son responsables de resguardar y respaldar la Información de PEMEX, EPS y, en su caso, EF, que generen o que esté bajo su custodia; para tal efecto, deben utilizar únicamente las herramientas OneDrive, Teams o SharePoint, u otros servicios o aplicaciones de almacenamiento o respaldo de información y datos que sean proporcionados y/o autorizados por la STI. Para lo anterior, se puede consultar la Guía de resguardo de información del equipo de cómputo personal, disponible mediante vínculo señalado en el Anexo único de las presentes Políticas.',
                ],
                'manifiesto' => 'El Usuario Final manifiesta haber respaldado la información del equipo de cómputo que se detalla a continuación y autoriza el formateo del mismo.',
                'titulo_motivos' => 'Motivo de Formateo Mencionado:',
                'motivos' => [
                    'Software potencialmente indeseable (PUA) / Malware *',
                    'Falla del Sistema operativo o reasignación.',
                    'Baja o Desincorporación de equipo.***',
                    'Información encriptada **',
                ],
                'notas' => [
                    '*Equipo comprometido por PUA/Malware.',
                    '**Equipo Comprometido por detección de información cifrada originada por malware. Así mismo, está enterado(a) que, al autorizar el formateo del equipo, se procede con la eliminación de la información almacenada en el equipo de cómputo descrito y no será posible recuperarla.',
                    '***Cuando el equipo se retira de la empresa por baja en garantía, baja por término de contrato o desincorporación de equipo PEMEX se aplica borrado seguro.',
                ],
                'etiquetas' => self::ETIQUETAS,
                'leyenda_firma' => '',
                'formato_lugar' => '{lugar}, a {fecha}',
                'firmas' => [
                    'usuario' => 'Usuario del equipo que autoriza el formateo',
                    'responsable' => 'Responsable que autoriza el formateo',
                    'ti' => 'Responsable de TI que realiza el formateo',
                ],
                'metodos' => ['Imagen institucional', 'Instalación limpia', 'Restablecimiento de fábrica'],
            ],
        ],
    ];

    public function __construct(private readonly PDO $pdo)
    {
    }

    // ------------------------------------------------------------------
    //  Plantillas
    // ------------------------------------------------------------------

    /** @param array<string, mixed> $c contenido de la plantilla @return array<string, string> */
    public static function etiquetas(array $c): array
    {
        if (!isset($c['etiquetas']) || !is_array($c['etiquetas'])) {
            return self::ETIQUETAS_ANTERIORES;
        }
        return array_map('strval', array_intersect_key($c['etiquetas'], self::ETIQUETAS) + self::ETIQUETAS);
    }

    /** Renglón bajo las firmas ("" = sin renglón). @param array<string, mixed> $c */
    public static function leyendaFirma(array $c): string
    {
        return array_key_exists('leyenda_firma', $c) ? (string) $c['leyenda_firma'] : 'Nombre, ficha y firma';
    }

    /** "Ciudad del Carmen, Campeche, a 06 de julio de 2026" según la versión. @param array<string, mixed> $c */
    public static function lugarYFecha(array $c, string $lugar, string $fecha): string
    {
        return strtr((string) ($c['formato_lugar'] ?? '{lugar} a {fecha}'), ['{lugar}' => $lugar, '{fecha}' => fecha_larga($fecha)]);
    }

    /** @return array<string, mixed> versión vigente (la del año o la más reciente) con contenido decodificado */
    public function plantillaVigente(string $clave): array
    {
        $stmt = $this->pdo->prepare('SELECT * FROM formato_plantillas WHERE clave = ? AND anio <= ? ORDER BY anio DESC, version DESC LIMIT 1');
        $stmt->execute([$clave, (int) date('Y')]);
        $p = $stmt->fetch();
        if (!$p) {
            if (!isset(self::DEFAULT[$clave])) {
                throw new ValidationException(['Ese formato no existe.']);
            }
            $this->pdo->prepare('INSERT INTO formato_plantillas (clave, anio, version, titulo, contenido) VALUES (?, ?, 1, ?, ?)')
                ->execute([$clave, (int) date('Y'), self::DEFAULT[$clave]['titulo'], json_encode(self::DEFAULT[$clave]['contenido'], JSON_UNESCAPED_UNICODE)]);
            return $this->plantilla((int) $this->pdo->lastInsertId());
        }
        $p = self::decodificar($p);
        // Versión creada con el texto anterior y aún sin documentos: se actualiza
        // al texto vigente conservando los logos (lo firmado nunca se toca).
        if (!isset($p['contenido']['etiquetas']) && isset(self::DEFAULT[$clave])) {
            $usados = $this->pdo->prepare('SELECT COUNT(*) FROM formatos WHERE plantilla_id = ?');
            $usados->execute([(int) $p['id']]);
            if ((int) $usados->fetchColumn() === 0) {
                $this->pdo->prepare('UPDATE formato_plantillas SET titulo = ?, contenido = ? WHERE id = ?')
                    ->execute([self::DEFAULT[$clave]['titulo'], json_encode(self::DEFAULT[$clave]['contenido'], JSON_UNESCAPED_UNICODE), (int) $p['id']]);
                return $this->plantilla((int) $p['id']);
            }
        }
        return $p;
    }

    /** @return array<string, mixed> */
    public function plantilla(int $id): array
    {
        $stmt = $this->pdo->prepare('SELECT * FROM formato_plantillas WHERE id = ?');
        $stmt->execute([$id]);
        $p = $stmt->fetch();
        if (!$p) {
            throw new ValidationException(['La plantilla no existe.']);
        }
        return self::decodificar($p);
    }

    /** @return list<array<string, mixed>> todas las versiones con cuántos documentos usan cada una */
    public function listado(): array
    {
        return $this->pdo->query(
            'SELECT p.id, p.clave, p.anio, p.version, p.titulo, p.creado_en, p.logo_izq, p.logo_der, p.logo_pie, u.nombre AS creado_por_nombre,
                    (SELECT COUNT(*) FROM formatos f WHERE f.plantilla_id = p.id) AS usados
               FROM formato_plantillas p LEFT JOIN usuarios u ON u.id = p.creado_por
              ORDER BY p.clave, p.anio DESC, p.version DESC'
        )->fetchAll();
    }

    /**
     * Guarda textos y logos. Si la versión ya tiene documentos emitidos, crea
     * una versión nueva del mismo año (lo firmado nunca cambia).
     *
     * @param array<string, mixed> $in textos (listas: una línea por elemento)
     * @param array{izq?: string|null, der?: string|null, pie?: string|null} $logos rutas nuevas
     * @param array<string, mixed> $actor
     * @return int id de la versión guardada
     */
    public function guardarPlantilla(int $id, array $in, array $logos, array $actor): int
    {
        if (($actor['rol'] ?? '') !== 'admin_ti') {
            throw new ValidationException(['Solo el Admin TI edita los formatos.']);
        }
        $p = $this->plantilla($id);
        $lineas = static fn (string $k): array => array_values(array_filter(array_map('trim', preg_split('/\R/', (string) ($in[$k] ?? '')) ?: []), static fn (string $l): bool => $l !== ''));
        $texto = static fn (string $k, int $max = 2000): string => mb_substr(trim((string) ($in[$k] ?? '')), 0, $max);
        $titulo = $texto('titulo', 150);
        $contenido = [
            'encabezado' => array_slice($lineas('encabezado'), 0, 4),
            'lugar_omision' => $texto('lugar_omision', 120), 'organismo_omision' => $texto('organismo_omision', 150),
            'seccion_usuario' => $texto('seccion_usuario', 200), 'nota_resguardo' => $texto('nota_resguardo', 400),
            'intro' => $texto('intro'), 'parrafos' => $lineas('parrafos'), 'subtitulo' => $texto('subtitulo', 200),
            'parrafos2' => $lineas('parrafos2'), 'manifiesto' => $texto('manifiesto'), 'titulo_motivos' => $texto('titulo_motivos', 200),
            'motivos' => $lineas('motivos'), 'notas' => $lineas('notas'),
            'firmas' => ['usuario' => $texto('firma_usuario', 120), 'responsable' => $texto('firma_responsable', 120), 'ti' => $texto('firma_ti', 120)],
            'metodos' => $lineas('metodos'),
            'etiquetas' => [],
            'leyenda_firma' => array_key_exists('leyenda_firma', $in) ? $texto('leyenda_firma', 120) : self::leyendaFirma($p['contenido']),
            'formato_lugar' => array_key_exists('formato_lugar', $in) && str_contains((string) $in['formato_lugar'], '{fecha}')
                ? $texto('formato_lugar', 120) : (string) ($p['contenido']['formato_lugar'] ?? '{lugar}, a {fecha}'),
        ];
        $previas = isset($p['contenido']['etiquetas']) ? self::etiquetas($p['contenido']) : self::ETIQUETAS;
        foreach (self::ETIQUETAS as $k => $omision) {
            $valor = array_key_exists('etiqueta_' . $k, $in) ? $texto('etiqueta_' . $k, 120) : $previas[$k];
            $contenido['etiquetas'][$k] = $valor !== '' ? $valor : $omision;
        }
        $errors = [];
        if (mb_strlen($titulo) < 5) {
            $errors[] = 'Escribe el título del formato.';
        }
        if ($contenido['motivos'] === [] || count($contenido['motivos']) > 8) {
            $errors[] = 'Escribe entre 1 y 8 motivos (uno por línea).';
        }
        if ($contenido['metodos'] === []) {
            $errors[] = 'Escribe al menos un método de formateo (uno por línea).';
        }
        if ($contenido['parrafos'] === [] || $contenido['manifiesto'] === '') {
            $errors[] = 'El texto de las políticas y el manifiesto del usuario son obligatorios.';
        }
        if ($errors !== []) {
            throw new ValidationException($errors);
        }
        $json = json_encode($contenido, JSON_UNESCAPED_UNICODE);
        $izq = $logos['izq'] ?? $p['logo_izq'];
        $der = $logos['der'] ?? $p['logo_der'];
        $pie = $logos['pie'] ?? $p['logo_pie'];
        $usados = $this->pdo->prepare('SELECT COUNT(*) FROM formatos WHERE plantilla_id = ?');
        $usados->execute([$id]);
        if ((int) $usados->fetchColumn() === 0) {
            $this->pdo->prepare('UPDATE formato_plantillas SET titulo = ?, contenido = ?, logo_izq = ?, logo_der = ?, logo_pie = ? WHERE id = ?')
                ->execute([$titulo, $json, $izq, $der, $pie, $id]);
            return $id;
        }
        $v = $this->pdo->prepare('SELECT COALESCE(MAX(version), 0) + 1 FROM formato_plantillas WHERE clave = ? AND anio = ?');
        $v->execute([$p['clave'], (int) $p['anio']]);
        $this->pdo->prepare(
            'INSERT INTO formato_plantillas (clave, anio, version, titulo, contenido, logo_izq, logo_der, logo_pie, creado_por) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)'
        )->execute([$p['clave'], (int) $p['anio'], (int) $v->fetchColumn(), $titulo, $json, $izq, $der, $pie, (int) $actor['id']]);
        return (int) $this->pdo->lastInsertId();
    }

    /**
     * Prepara la versión de otro año copiando la más reciente (textos y logos)
     * para que el Admin cambie lo que corresponda; se usa al llegar ese año.
     *
     * @param array<string, mixed> $actor
     */
    public function nuevoAnio(string $clave, int $anio, array $actor): int
    {
        if (($actor['rol'] ?? '') !== 'admin_ti') {
            throw new ValidationException(['Solo el Admin TI crea versiones de los formatos.']);
        }
        $actual = (int) date('Y');
        if ($anio < $actual || $anio > $actual + 1) {
            throw new ValidationException(["Solo se prepara la versión de {$actual} o de " . ($actual + 1) . '.']);
        }
        $existe = $this->pdo->prepare('SELECT COUNT(*) FROM formato_plantillas WHERE clave = ? AND anio = ?');
        $existe->execute([$clave, $anio]);
        if ((int) $existe->fetchColumn() > 0) {
            throw new ValidationException(["Ya existe la versión {$anio} de ese formato; edítala."]);
        }
        $this->plantillaVigente($clave);
        $base = $this->pdo->prepare('SELECT * FROM formato_plantillas WHERE clave = ? ORDER BY anio DESC, version DESC LIMIT 1');
        $base->execute([$clave]);
        $b = $base->fetch();
        $this->pdo->prepare(
            'INSERT INTO formato_plantillas (clave, anio, version, titulo, contenido, logo_izq, logo_der, logo_pie, creado_por) VALUES (?, ?, 1, ?, ?, ?, ?, ?, ?)'
        )->execute([$clave, $anio, $b['titulo'], $b['contenido'], $b['logo_izq'], $b['logo_der'], $b['logo_pie'], (int) $actor['id']]);
        return (int) $this->pdo->lastInsertId();
    }

    /**
     * Logo para el formato: PNG, JPG o WEBP hasta 2 MB; se vuelve a generar
     * como PNG (conserva transparencia) y se reduce a 1600 px de ancho.
     *
     * @param array<string, mixed> $upload elemento de $_FILES
     * @return string ruta relativa a storage
     */
    public function guardarLogo(array $upload, bool $soloSubidas = true): string
    {
        $error = new ValidationException(['El logo debe ser una imagen PNG, JPG o WEBP de hasta 2 MB.']);
        $tmp = (string) ($upload['tmp_name'] ?? '');
        if ((int) ($upload['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK || $tmp === '' || !is_file($tmp)
            || ($soloSubidas && !is_uploaded_file($tmp)) || filesize($tmp) > 2 * 1024 * 1024) {
            throw $error;
        }
        $info = @getimagesize($tmp);
        if ($info === false || !in_array($info[2], [IMAGETYPE_PNG, IMAGETYPE_JPEG, IMAGETYPE_WEBP], true) || $info[0] > 6000 || $info[1] > 6000) {
            throw $error;
        }
        $im = @imagecreatefromstring((string) file_get_contents($tmp));
        if ($im === false) {
            throw $error;
        }
        $w = imagesx($im);
        $h = imagesy($im);
        if ($w > 1600) {
            $nuevo = imagecreatetruecolor(1600, (int) round($h * 1600 / $w));
            imagealphablending($nuevo, false);
            imagesavealpha($nuevo, true);
            imagecopyresampled($nuevo, $im, 0, 0, 0, 0, 1600, (int) round($h * 1600 / $w), $w, $h);
            $im = $nuevo;
        }
        imagesavealpha($im, true);
        $dir = STORAGE_PATH . '/formatos/logos';
        if (!is_dir($dir) && !@mkdir($dir, 0775, true) && !is_dir($dir)) {
            throw new \RuntimeException('No se pudo crear la carpeta de logos.');
        }
        $ruta = 'formatos/logos/' . bin2hex(random_bytes(12)) . '.png';
        if (!imagepng($im, STORAGE_PATH . '/' . $ruta, 6)) {
            throw new \RuntimeException('No se pudo guardar el logo.');
        }
        return $ruta;
    }

    // ------------------------------------------------------------------
    //  Emisión y consulta
    // ------------------------------------------------------------------

    /** @param array<string, mixed> $actor */
    public function emitir(int $ticketId, array $actor, string $clave = 'formateo'): int
    {
        $stmt = $this->pdo->prepare('SELECT id, usuario_solicitante_id, equipo_id FROM tickets WHERE id = ?');
        $stmt->execute([$ticketId]);
        $t = $stmt->fetch();
        if (!$t) {
            throw new ValidationException(['El ticket no existe.']);
        }
        if ($t['equipo_id'] === null) {
            throw new ValidationException(['El ticket no tiene equipo; elige el equipo antes de generar el formato.']);
        }
        if ($this->activo($ticketId) !== null) {
            throw new ValidationException(['Este ticket ya tiene un formato en firma.']);
        }
        $plantilla = $this->plantillaVigente($clave);
        $this->pdo->prepare(
            'INSERT INTO formatos (folio, codigo, plantilla_id, ticket_id, usuario_id, equipo_id, creado_por) VALUES (?, ?, ?, ?, ?, ?, ?)'
        )->execute([
            'TMP-' . bin2hex(random_bytes(8)), strtoupper(bin2hex(random_bytes(4))), (int) $plantilla['id'], $ticketId,
            (int) $t['usuario_solicitante_id'], (int) $t['equipo_id'], (int) $actor['id'],
        ]);
        $id = (int) $this->pdo->lastInsertId();
        $folio = Folio::asignar($this->pdo, 'formatos', 'FF', $id);
        $this->bitacora($ticketId, (int) $actor['id'], "[Sistema] Formato {$folio} ({$plantilla['titulo']}) generado; pendiente de firmas: usuario, responsable y TI.");
        return $id;
    }

    /** @return array<string, mixed>|null */
    public function find(int $id): ?array
    {
        $stmt = $this->pdo->prepare(
            'SELECT f.*, p.clave, p.titulo, p.anio, p.version, p.contenido, p.logo_izq, p.logo_der, p.logo_pie,
                    u.nombre AS usuario_nombre, u.ficha AS usuario_ficha, u.jefe_ficha, u.email AS usuario_email, t.folio AS ticket_folio
               FROM formatos f JOIN formato_plantillas p ON p.id = f.plantilla_id JOIN usuarios u ON u.id = f.usuario_id
               LEFT JOIN tickets t ON t.id = f.ticket_id
              WHERE f.id = ?'
        );
        $stmt->execute([$id]);
        $f = $stmt->fetch();
        if (!$f) {
            return null;
        }
        $f['contenido'] = json_decode((string) $f['contenido'], true) ?: [];
        $marcados = json_decode((string) ($f['motivos'] ?? '[]'), true) ?: [];
        $f['motivos_idx'] = array_map('intval', $marcados);
        $f['motivos_lista'] = array_values(array_map(static fn (int $i): string => (string) ($f['contenido']['motivos'][$i] ?? ''), $f['motivos_idx']));
        return $f;
    }

    /** @return array<string, mixed>|null formato en firma del ticket */
    public function activo(int $ticketId): ?array
    {
        $stmt = $this->pdo->prepare("SELECT id FROM formatos WHERE ticket_id = ? AND estado LIKE 'pendiente%' ORDER BY id DESC LIMIT 1");
        $stmt->execute([$ticketId]);
        $id = $stmt->fetchColumn();
        return $id !== false ? $this->find((int) $id) : null;
    }

    /** @return list<array<string, mixed>> formatos del ticket (más reciente primero) */
    public function deTicket(int $ticketId): array
    {
        $stmt = $this->pdo->prepare('SELECT id FROM formatos WHERE ticket_id = ? ORDER BY id DESC');
        $stmt->execute([$ticketId]);
        return array_values(array_filter(array_map(fn ($id): ?array => $this->find((int) $id), $stmt->fetchAll(PDO::FETCH_COLUMN))));
    }

    /** @return list<array<string, mixed>> formatos que esperan la firma de este usuario en su sesión */
    public function pendientesDe(array $user): array
    {
        $stmt = $this->pdo->prepare(
            "SELECT f.id FROM formatos f JOIN usuarios u ON u.id = f.usuario_id
              WHERE (f.estado = 'pendiente_usuario' AND f.usuario_id = ?)
                 OR (f.estado = 'pendiente_responsable' AND u.jefe_ficha = ? AND u.jefe_ficha <> '')
              ORDER BY f.id DESC LIMIT 20"
        );
        $stmt->execute([(int) $user['id'], (string) ($user['ficha'] ?? '')]);
        return array_values(array_filter(array_map(fn ($id): ?array => $this->find((int) $id), $stmt->fetchAll(PDO::FETCH_COLUMN))));
    }

    /**
     * Datos del documento: congelados si ya se completó; si no, al momento.
     *
     * @param array<string, mixed> $f
     * @return array<string, mixed>
     */
    public function datos(array $f): array
    {
        if (!empty($f['datos'])) {
            // Lo firmado queda congelado; el folio de Remedy es del ticket y suele llegar después.
            $congelado = (json_decode((string) $f['datos'], true) ?: []) + ['responsable_sugerido' => null];
            if ($f['ticket_id'] !== null) {
                $q = $this->pdo->prepare('SELECT folio_remedy FROM tickets WHERE id = ?');
                $q->execute([(int) $f['ticket_id']]);
                $congelado['remedy'] = (string) ($q->fetchColumn() ?: '');
            }
            return $congelado;
        }
        $c = $f['contenido'];
        $stmt = $this->pdo->prepare(
            'SELECT u.nombre, u.ficha, u.telefono_ext, u.jefe_ficha, u.jefe_nombre,
                    e.tipo_componente, e.marca, e.modelo, e.serie, e.inventario,
                    ug.eps, ug.subdireccion, ug.gerencia, ug.inmueble, e.ubicacion_referencia
               FROM usuarios u
               LEFT JOIN equipos e ON e.id = ?
               LEFT JOIN ubicaciones ug ON ug.id = e.ubicacion_geca_id
              WHERE u.id = ?'
        );
        $stmt->execute([$f['equipo_id'], (int) $f['usuario_id']]);
        $r = $stmt->fetch() ?: [];
        $t = $f['ticket_id'] !== null ? (new TicketService($this->pdo))->find((int) $f['ticket_id']) : null;
        $ciudad = null;
        if ($t !== null && $t['instalacion_id'] !== null) {
            $q = $this->pdo->prepare('SELECT ciudad FROM instalaciones WHERE id = ?');
            $q->execute([(int) $t['instalacion_id']]);
            $ciudad = $q->fetchColumn() ?: null;
        }
        $ubicacion = $t !== null && !empty($t['ubicacion_fisica'])
            ? $t['ubicacion_fisica'] . (!empty($t['referencia']) ? ' · ' . $t['referencia'] : '')
            : trim(($r['inmueble'] ?? '') . (!empty($r['ubicacion_referencia']) ? ' · ' . $r['ubicacion_referencia'] : ''));
        $sugerido = null;
        if (!empty($r['jefe_ficha'])) {
            $q = $this->pdo->prepare('SELECT nombre FROM usuarios WHERE ficha = ?');
            $q->execute([$r['jefe_ficha']]);
            $sugerido = ['ficha' => (string) $r['jefe_ficha'], 'nombre' => (string) ($q->fetchColumn() ?: ($r['jefe_nombre'] ?? ''))];
        }
        $firmaUsuario = $this->firmas((int) $f['id'])['usuario']['firmado_en'] ?? null;
        return [
            'usuario' => (string) ($r['nombre'] ?? ''), 'ficha' => (string) ($r['ficha'] ?? ''),
            'ext' => trim((string) preg_replace('/[\[\]"\']/', '', (string) ($r['telefono_ext'] ?? ''))),
            'organismo' => (string) (($r['eps'] ?? '') !== '' ? $r['eps'] : ($c['organismo_omision'] ?? '')),
            'subdireccion' => (string) ($r['subdireccion'] ?? ''), 'gerencia' => (string) ($r['gerencia'] ?? ''),
            'ubicacion' => $ubicacion,
            'remedy' => (string) ($t['folio_remedy'] ?? ''),
            'tipo' => (string) ($r['tipo_componente'] ?? ''), 'marca' => (string) ($r['marca'] ?? ''), 'modelo' => (string) ($r['modelo'] ?? ''),
            'serie' => (string) ($r['serie'] ?? ''), 'inventario' => (string) (($r['inventario'] ?? '') !== '' ? $r['inventario'] : 'N/A'),
            'lugar' => (string) ($ciudad ?? ($c['lugar_omision'] ?? '')),
            'fecha' => (string) ($firmaUsuario ?? $f['creado_en']),
            'responsable_sugerido' => $sugerido,
        ];
    }

    /** @return array<string, array<string, mixed>> firmas por rol, en orden usuario, responsable, ti */
    public function firmas(int $formatoId): array
    {
        $stmt = $this->pdo->prepare('SELECT f.*, c.nombre AS capturado_por_nombre FROM formato_firmas f LEFT JOIN usuarios c ON c.id = f.capturado_por WHERE f.formato_id = ?');
        $stmt->execute([$formatoId]);
        $por = [];
        foreach ($stmt->fetchAll() as $r) {
            $por[(string) $r['rol']] = $r;
        }
        $out = [];
        foreach (array_keys(self::ROLES) as $rol) {
            if (isset($por[$rol])) {
                $out[$rol] = $por[$rol];
            }
        }
        return $out;
    }

    /** Rol que toca firmar, o null si ya no admite firmas. */
    public static function turno(array $f): ?string
    {
        $rol = array_search($f['estado'], self::PENDIENTE, true);
        return $rol === false ? null : (string) $rol;
    }

    /**
     * ¿Puede este usuario firmar ese rol ahora (en su sesión o capturando en persona)?
     *
     * @param array<string, mixed> $f
     * @param array<string, mixed> $user
     */
    public function puedeFirmar(array $f, string $rol, array $user): bool
    {
        if (self::turno($f) !== $rol) {
            return false;
        }
        $esTi = Roles::esTi($user['rol'] ?? null);
        return match ($rol) {
            'usuario' => (int) $user['id'] === (int) $f['usuario_id'] || $esTi,
            'responsable' => $esTi || (int) $user['id'] === (int) $f['usuario_id']
                || ((string) ($f['jefe_ficha'] ?? '') !== '' && (string) ($user['ficha'] ?? '') === (string) $f['jefe_ficha']),
            'ti' => $esTi,
            default => false,
        };
    }

    // ------------------------------------------------------------------
    //  Firmas
    // ------------------------------------------------------------------

    /**
     * @param array<string, mixed> $actor quien está en sesión
     * @param array<string, mixed> $in png, password, modo (presencial), motivos, ficha, mismo_usuario, motivo, metodo
     */
    public function firmar(int $formatoId, string $rol, array $actor, array $in): void
    {
        $f = $this->find($formatoId) ?? throw new ValidationException(['El formato no existe.']);
        $turno = self::turno($f);
        if ($turno === null) {
            throw new ValidationException(['Este formato ya no admite firmas.']);
        }
        if ($rol !== $turno) {
            throw new ValidationException(['Primero debe firmar el ' . mb_strtolower(self::ROLES[$turno]) . '.']);
        }
        $esTi = Roles::esTi($actor['rol'] ?? null);
        $esTitular = (int) $actor['id'] === (int) $f['usuario_id'];
        $mismo = !empty($in['mismo_usuario']);
        $motivo = null;

        // Quién firma y en qué modo.
        if ($rol === 'usuario') {
            if (!$esTitular && !$esTi) {
                throw new ValidationException(['Solo el usuario del equipo puede firmar aquí (o TI capturando su firma en persona).']);
            }
            $firmante = (int) $f['usuario_id'];
            $modo = $esTitular ? 'sesion' : 'presencial';
        } elseif ($rol === 'responsable') {
            if ($mismo) {
                if (!$esTitular && !$esTi) {
                    throw new ValidationException(['No puedes firmar como responsable de este formato.']);
                }
                $firmante = (int) $f['usuario_id'];
                $modo = $esTitular ? 'sesion' : 'presencial';
            } elseif ($esTi && (($in['modo'] ?? '') === 'presencial' || (string) ($actor['ficha'] ?? '') !== (string) $f['jefe_ficha'])) {
                $firmante = $this->idPorFicha((string) ($in['ficha'] ?? ''));
                if ($firmante === (int) $f['usuario_id']) {
                    throw new ValidationException(['Si el mismo usuario firma como responsable, marca esa opción y escribe el motivo.']);
                }
                $modo = 'presencial';
            } elseif ((string) $f['jefe_ficha'] !== '' && (string) ($actor['ficha'] ?? '') === (string) $f['jefe_ficha']) {
                $firmante = (int) $actor['id'];
                $modo = 'sesion';
            } else {
                throw new ValidationException(['Solo el jefe del usuario puede firmar aquí (o TI capturando la firma en persona).']);
            }
        } else {
            if (!$esTi) {
                throw new ValidationException(['Solo el personal de TI firma como responsable del formateo.']);
            }
            $firmante = (int) $actor['id'];
            $modo = 'sesion';
        }

        $this->verificarPassword($actor, (string) ($in['password'] ?? ''));
        $errors = [];
        if ($rol === 'usuario') {
            $motivos = $this->validarMotivos($f, $in['motivos'] ?? null);
            if ($motivos === []) {
                $errors[] = 'Marca al menos un motivo de formateo.';
            }
        }
        if ($rol === 'responsable' && $mismo) {
            $motivo = trim((string) ($in['motivo'] ?? ''));
            if (mb_strlen($motivo) < 5) {
                $errors[] = 'Escribe el motivo por el que el usuario firma también como responsable (p. ej. jefe ausente).';
            }
        }
        $metodo = null;
        if ($rol === 'ti') {
            $metodo = (string) ($in['metodo'] ?? '');
            if (!in_array($metodo, (array) ($f['contenido']['metodos'] ?? []), true)) {
                $errors[] = 'Elige el método de formateo.';
            }
        }
        if ($errors !== []) {
            throw new ValidationException($errors);
        }
        $png = self::validarFirma((string) ($in['png'] ?? ''));

        $ruta = $this->guardarImagen($png);
        $this->pdo->beginTransaction();
        try {
            if ($rol === 'usuario') {
                $this->pdo->prepare('UPDATE formatos SET motivos = ? WHERE id = ?')->execute([json_encode($motivos), $formatoId]);
            }
            $this->insertarFirma($f, $rol, $firmante, $modo, $ruta, $motivo, (int) $actor['id']);
            $this->avanzar($f, $rol, $metodo);
            $this->pdo->commit();
        } catch (Throwable $e) {
            if ($this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }
            @unlink(STORAGE_PATH . '/' . $ruta);
            throw $e;
        }
    }

    /**
     * Formato firmado en papel: TI sube el escaneo y se registran las firmas faltantes.
     *
     * @param array<string, mixed> $actor
     * @param array<string, mixed> $in archivo (ruta relativa a storage), password, ficha (responsable), mismo_usuario, motivo, metodo, motivos
     */
    public function autografa(int $formatoId, array $actor, array $in): void
    {
        $f = $this->find($formatoId) ?? throw new ValidationException(['El formato no existe.']);
        if (!Roles::esTi($actor['rol'] ?? null)) {
            throw new ValidationException(['Solo TI registra un formato firmado en papel.']);
        }
        if (self::turno($f) === null) {
            throw new ValidationException(['Este formato ya no admite firmas.']);
        }
        $this->verificarPassword($actor, (string) ($in['password'] ?? ''));
        $firmas = $this->firmas($formatoId);
        $errors = [];
        $motivos = $f['motivos_idx'];
        if (!isset($firmas['usuario'])) {
            $motivos = $this->validarMotivos($f, $in['motivos'] ?? null);
            if ($motivos === []) {
                $errors[] = 'Marca los motivos de formateo que vienen en el papel.';
            }
        }
        $metodo = (string) ($in['metodo'] ?? '');
        if (!in_array($metodo, (array) ($f['contenido']['metodos'] ?? []), true)) {
            $errors[] = 'Elige el método de formateo.';
        }
        $responsable = null;
        $motivo = null;
        if (!isset($firmas['responsable'])) {
            if (!empty($in['mismo_usuario'])) {
                $responsable = (int) $f['usuario_id'];
                $motivo = trim((string) ($in['motivo'] ?? ''));
                if (mb_strlen($motivo) < 5) {
                    $errors[] = 'Escribe el motivo por el que el usuario firmó también como responsable.';
                }
            } else {
                try {
                    $responsable = $this->idPorFicha((string) ($in['ficha'] ?? ''));
                } catch (ValidationException $e) {
                    $errors = array_merge($errors, $e->errors());
                }
            }
        }
        if (trim((string) ($in['archivo'] ?? '')) === '') {
            $errors[] = 'Sube el formato firmado (PDF o foto).';
        }
        if ($errors !== []) {
            throw new ValidationException($errors);
        }
        $this->pdo->beginTransaction();
        try {
            $this->pdo->prepare('UPDATE formatos SET motivos = ?, archivo_autografo = ? WHERE id = ?')
                ->execute([json_encode($motivos), (string) $in['archivo'], $formatoId]);
            foreach (['usuario' => (int) $f['usuario_id'], 'responsable' => $responsable, 'ti' => (int) $actor['id']] as $rol => $quien) {
                if (!isset($firmas[$rol])) {
                    $this->insertarFirma($f, $rol, (int) $quien, 'autografa', null, $rol === 'responsable' ? $motivo : null, (int) $actor['id']);
                }
            }
            $f = $this->find($formatoId);
            $this->avanzar($f, 'ti', $metodo);
            $this->pdo->commit();
        } catch (Throwable $e) {
            if ($this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }
            throw $e;
        }
    }

    /** @param array<string, mixed> $actor */
    public function cancelar(int $formatoId, array $actor, string $motivo): void
    {
        $f = $this->find($formatoId) ?? throw new ValidationException(['El formato no existe.']);
        if (!Roles::esTi($actor['rol'] ?? null)) {
            throw new ValidationException(['Solo TI puede cancelar un formato.']);
        }
        if (self::turno($f) === null) {
            throw new ValidationException(['Solo se cancela un formato que aún está en firma.']);
        }
        $motivo = trim($motivo);
        if (mb_strlen($motivo) < 5) {
            throw new ValidationException(['Escribe el motivo de la cancelación.']);
        }
        $this->pdo->prepare("UPDATE formatos SET estado = 'cancelado', cancelado_en = NOW(), motivo_cancelacion = ? WHERE id = ?")
            ->execute([mb_substr($motivo, 0, 255), $formatoId]);
        if ($f['ticket_id'] !== null) {
            $this->bitacora((int) $f['ticket_id'], (int) $actor['id'], "[Sistema] Formato {$f['folio']} cancelado: {$motivo}");
        }
    }

    /** Mensaje si el ticket no puede cerrarse por un formato pendiente; null si puede. */
    public function bloqueoCierre(int $ticketId): ?string
    {
        $f = $this->activo($ticketId);
        return $f === null ? null
            : "El formato {$f['folio']} aún no tiene todas las firmas; complétalo o cancélalo antes de cerrar el ticket.";
    }

    /** @param array<string, mixed> $actor */
    public function guardarInventario(int $equipoId, string $valor, array $actor): void
    {
        if (!Roles::esTi($actor['rol'] ?? null)) {
            throw new ValidationException(['Solo TI captura el número de inventario.']);
        }
        $valor = mb_strtoupper(trim($valor));
        if ($valor !== '' && preg_match('/^[\p{L}\p{N} \/.-]{1,40}$/u', $valor) !== 1) {
            throw new ValidationException(['El número de inventario admite letras, números, espacios, / . y - (máximo 40).']);
        }
        $this->pdo->prepare('UPDATE equipos SET inventario = ? WHERE id = ?')->execute([$valor !== '' && $valor !== 'N/A' ? $valor : null, $equipoId]);
    }

    /** ¿Coincide el código de verificación del documento? */
    public function verificar(string $folio, string $codigo): ?array
    {
        $stmt = $this->pdo->prepare('SELECT id FROM formatos WHERE folio = ? AND codigo = ?');
        $stmt->execute([mb_strtoupper(trim($folio)), mb_strtoupper(trim($codigo))]);
        $id = $stmt->fetchColumn();
        return $id !== false ? $this->find((int) $id) : null;
    }

    /** PNG de la firma validado (o excepción). */
    public static function validarFirma(string $dataUrl): string
    {
        $error = new ValidationException(['La firma no es válida; dibújala de nuevo en el recuadro.']);
        if (!str_starts_with($dataUrl, 'data:image/png;base64,')) {
            throw $error;
        }
        $png = base64_decode(substr($dataUrl, 22), true);
        if ($png === false || strlen($png) > self::MAX_FIRMA) {
            throw $error;
        }
        $info = @getimagesizefromstring($png);
        if ($info === false || $info[2] !== IMAGETYPE_PNG || $info[0] > 2000 || $info[1] > 800) {
            throw $error;
        }
        if (function_exists('imagecreatefromstring')) {
            $im = @imagecreatefromstring($png);
            if ($im === false) {
                throw $error;
            }
            // Debe haber trazo: cuenta puntos oscuros en una muestra.
            $w = imagesx($im);
            $h = imagesy($im);
            $tinta = 0;
            for ($x = 0; $x < $w; $x += 2) {
                for ($y = 0; $y < $h; $y += 2) {
                    $c = imagecolorsforindex($im, imagecolorat($im, $x, $y));
                    if ($c['alpha'] < 100 && ($c['red'] + $c['green'] + $c['blue']) < 384) {
                        $tinta++;
                    }
                }
            }
            if ($tinta < 15) {
                throw new ValidationException(['La firma está en blanco; dibújala en el recuadro.']);
            }
        }
        return $png;
    }

    // ------------------------------------------------------------------
    //  Internos
    // ------------------------------------------------------------------

    /** @param array<string, mixed> $f */
    private function avanzar(array $f, string $rol, ?string $metodo): void
    {
        $nuevo = self::SIGUIENTE[$rol];
        if ($nuevo === 'completo') {
            $f = $this->find((int) $f['id']);
            $datos = $this->datos($f);
            unset($datos['responsable_sugerido']);
            $this->pdo->prepare("UPDATE formatos SET estado = 'completo', metodo = ?, completado_en = NOW(), datos = ? WHERE id = ?")
                ->execute([$metodo, json_encode($datos, JSON_UNESCAPED_UNICODE), (int) $f['id']]);
        } else {
            $this->pdo->prepare('UPDATE formatos SET estado = ? WHERE id = ?')->execute([$nuevo, (int) $f['id']]);
        }
    }

    /** @param array<string, mixed> $f */
    private function insertarFirma(array $f, string $rol, int $firmanteId, string $modo, ?string $imagen, ?string $motivo, int $capturo): void
    {
        $stmt = $this->pdo->prepare('SELECT nombre, ficha FROM usuarios WHERE id = ?');
        $stmt->execute([$firmanteId]);
        $u = $stmt->fetch() ?: ['nombre' => '', 'ficha' => ''];
        $this->pdo->prepare(
            'INSERT INTO formato_firmas (formato_id, rol, firmante_id, nombre, ficha, imagen, modo, motivo, capturado_por, ip) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)'
        )->execute([
            (int) $f['id'], $rol, $firmanteId, (string) $u['nombre'], (string) $u['ficha'], $imagen, $modo, $motivo, $capturo,
            mb_substr((string) ($_SERVER['REMOTE_ADDR'] ?? ''), 0, 45) ?: null,
        ]);
        if ($f['ticket_id'] !== null) {
            $this->bitacora((int) $f['ticket_id'], $capturo, "[Sistema] Formato {$f['folio']}: firmó {$u['nombre']} (F-{$u['ficha']}) como "
                . mb_strtolower(self::ROLES[$rol]) . ' (' . self::MODOS[$modo] . ')' . ($motivo ? ". Motivo: {$motivo}" : '') . '.');
        }
    }

    /** @return list<int> índices válidos de motivos */
    private function validarMotivos(array $f, mixed $motivos): array
    {
        $total = count((array) ($f['contenido']['motivos'] ?? []));
        $out = [];
        foreach ((array) $motivos as $m) {
            $i = (int) $m;
            if (is_numeric($m) && $i >= 0 && $i < $total && !in_array($i, $out, true)) {
                $out[] = $i;
            }
        }
        sort($out);
        return $out;
    }

    private function idPorFicha(string $ficha): int
    {
        $ficha = mb_strtoupper(trim($ficha));
        $stmt = $this->pdo->prepare('SELECT id FROM usuarios WHERE ficha = ? AND activo = 1');
        $stmt->execute([$ficha]);
        $id = $stmt->fetchColumn();
        if ($ficha === '' || $id === false) {
            throw new ValidationException(['No existe un trabajador con esa ficha; revisa el número.']);
        }
        return (int) $id;
    }

    /** @param array<string, mixed> $actor */
    private function verificarPassword(array $actor, string $password): void
    {
        $stmt = $this->pdo->prepare('SELECT password_hash FROM usuarios WHERE id = ?');
        $stmt->execute([(int) $actor['id']]);
        $hash = $stmt->fetchColumn();
        if (!is_string($hash) || $hash === '' || !password_verify($password, $hash)) {
            throw new ValidationException(['La contraseña no es correcta.']);
        }
    }

    private function guardarImagen(string $png): string
    {
        $dir = STORAGE_PATH . '/formatos/firmas';
        if (!is_dir($dir) && !@mkdir($dir, 0775, true) && !is_dir($dir)) {
            throw new \RuntimeException('No se pudo crear la carpeta de firmas.');
        }
        $nombre = 'formatos/firmas/' . bin2hex(random_bytes(16)) . '.png';
        if (file_put_contents(STORAGE_PATH . '/' . $nombre, $png) === false) {
            throw new \RuntimeException('No se pudo guardar la firma.');
        }
        return $nombre;
    }

    private function bitacora(int $ticketId, int $usuarioId, string $mensaje): void
    {
        $this->pdo->prepare('INSERT INTO comentarios (ticket_id, usuario_id, mensaje, es_nota_interna) VALUES (?, ?, ?, 0)')
            ->execute([$ticketId, $usuarioId, $mensaje]);
    }

    /** @param array<string, mixed> $p */
    private static function decodificar(array $p): array
    {
        $p['contenido'] = json_decode((string) $p['contenido'], true) ?: [];
        return $p;
    }
}
