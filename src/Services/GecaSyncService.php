<?php

declare(strict_types=1);

namespace App\Services;

use App\Libs\CsvReader;
use App\Libs\SimpleXLSX;
use DateTimeImmutable;
use Generator;
use PDO;
use PDOStatement;
use Throwable;

/**
 * Motor de sincronización semanal del GECA (estrategia UPSERT).
 *
 * Reglas (CLAUDE.md, sección 3.A):
 *  - PROHIBIDO TRUNCATE / DELETE sobre usuarios y equipos.
 *  - Llaves naturales: usuarios.ficha y equipos.serie.
 *  - Flujo: registro en cargas_geca -> todo el inventario a vigente_en_geca = 0
 *    -> por cada fila: ubicación (get-or-create), usuario (UPSERT por ficha,
 *    solo nombre y jefe_nombre), equipo (UPSERT por serie).
 *  - En equipos existentes se preservan intactos ubicacion_real_id,
 *    es_principal y, por no tocarse sus tablas, garantias y tickets.
 *
 * Toda la carga corre dentro de UNA transacción: si algo falla, la base
 * queda exactamente como estaba antes del lunes.
 */
final class GecaSyncService
{
    /** Los 30 encabezados oficiales del archivo GECA, en orden. */
    public const HEADERS = [
        'folio',
        'fecha',
        'estado_resguardo',
        'numero_contrato',
        'nombre',
        'ficha',
        'jefe_nombre',
        'eps',
        'subdireccion',
        'gerencia',
        'zona',
        'sector',
        'inmueble',
        'serie',
        'estado_inventario',
        'marca',
        'modelo',
        'perfil',
        'usuario_fecha_aceptacion',
        'ti_fecha_validacion',
        'fecha_baja',
        'fecha_carga_resguardo',
        'fuera_consola',
        'motivo_baja',
        'serie_monitor1',
        'marca_monitor1',
        'modelo_monitor1',
        'serie_monitor2',
        'marca_monitor2',
        'modelo_monitor2',
    ];

    /**
     * Variantes aceptadas de cada encabezado, en forma "compacta"
     * (minúsculas, sin acentos, sin espacios ni signos).
     */
    private const ALIASES = [
        'folio'                    => ['folio', 'folioresguardo', 'foliodelresguardo', 'foliogeca'],
        'fecha'                    => ['fecha', 'fecharesguardo', 'fechadelresguardo'],
        'estado_resguardo'         => ['estadoresguardo', 'estadodelresguardo', 'statusresguardo'],
        'numero_contrato'          => ['numerocontrato', 'numerodecontrato', 'numcontrato', 'nocontrato', 'contrato'],
        'nombre'                   => ['nombre', 'nombreusuario', 'nombredelusuario', 'nombreempleado', 'nombredelempleado', 'usuario'],
        'ficha'                    => ['ficha', 'numficha', 'noficha', 'numeroficha', 'numerodeficha', 'fichausuario'],
        'jefe_nombre'              => ['jefenombre', 'jefe', 'nombrejefe', 'nombredeljefe', 'jefeinmediato'],
        'eps'                      => ['eps'],
        'subdireccion'             => ['subdireccion'],
        'gerencia'                 => ['gerencia'],
        'zona'                     => ['zona'],
        'sector'                   => ['sector'],
        'inmueble'                 => ['inmueble', 'edificio'],
        'serie'                    => ['serie', 'numserie', 'noserie', 'numeroserie', 'numerodeserie', 'serieequipo', 'seriecpu'],
        'estado_inventario'        => ['estadoinventario', 'estadodelinventario', 'statusinventario'],
        'marca'                    => ['marca', 'marcaequipo'],
        'modelo'                   => ['modelo', 'modeloequipo'],
        'perfil'                   => ['perfil', 'perfilequipo'],
        'usuario_fecha_aceptacion' => ['usuariofechaaceptacion', 'fechaaceptacionusuario', 'fechaaceptacion', 'fechadeaceptacion'],
        'ti_fecha_validacion'      => ['tifechavalidacion', 'fechavalidacionti', 'fechavalidacion', 'fechadevalidacion'],
        'fecha_baja'               => ['fechabaja', 'fechadebaja'],
        'fecha_carga_resguardo'    => ['fechacargaresguardo', 'fechacargadelresguardo', 'fechacarga', 'fechadecarga'],
        'fuera_consola'            => ['fueraconsola', 'fueradeconsola'],
        'motivo_baja'              => ['motivobaja', 'motivodebaja'],
        'serie_monitor1'           => ['seriemonitor1', 'monitor1serie'],
        'marca_monitor1'           => ['marcamonitor1', 'monitor1marca'],
        'modelo_monitor1'          => ['modelomonitor1', 'monitor1modelo'],
        'serie_monitor2'           => ['seriemonitor2', 'monitor2serie'],
        'marca_monitor2'           => ['marcamonitor2', 'monitor2marca'],
        'modelo_monitor2'          => ['modelomonitor2', 'monitor2modelo'],
    ];

    /** Longitudes máximas de columna (evita errores en modo SQL estricto). */
    private const MAX_LEN = [
        'folio' => 40, 'estado_resguardo' => 60, 'numero_contrato' => 60,
        'nombre' => 150, 'ficha' => 20, 'jefe_nombre' => 150,
        'eps' => 80, 'subdireccion' => 120, 'gerencia' => 120, 'zona' => 100, 'sector' => 100, 'inmueble' => 150,
        'serie' => 60, 'estado_inventario' => 60, 'marca' => 60, 'modelo' => 80, 'perfil' => 80,
        'fuera_consola' => 40, 'motivo_baja' => 255,
        'serie_monitor1' => 60, 'marca_monitor1' => 60, 'modelo_monitor1' => 80,
        'serie_monitor2' => 60, 'marca_monitor2' => 60, 'modelo_monitor2' => 80,
    ];

    private const DATE_FIELDS = ['fecha'];

    private const DATETIME_FIELDS = [
        'usuario_fecha_aceptacion', 'ti_fecha_validacion', 'fecha_baja', 'fecha_carga_resguardo',
    ];

    private const MAX_WARNINGS = 200;

    private PDOStatement $stmtUbicacion;
    private PDOStatement $stmtUsuario;
    private PDOStatement $stmtEquipo;

    /** @var array<string, int> */
    private array $ubicacionCache = [];

    /** @var array<string, int> */
    private array $usuarioCache = [];

    /** @var array<string, int> series vistas en este archivo => fila */
    private array $seriesVistas = [];

    /** @var list<string> */
    private array $warnings = [];

    private int $warningsOmitidas = 0;

    /** @var array<string, int> */
    private array $stats = [];

    public function __construct(private readonly PDO $pdo)
    {
    }

    /**
     * Ejecuta la sincronización completa.
     *
     * @param string   $filePath     ruta temporal del archivo subido
     * @param string   $originalName nombre original (define el formato por extensión)
     * @param int|null $usuarioTiId  agente que realiza la carga
     *
     * @return array<string, mixed> resumen de la carga
     */
    public function sync(string $filePath, string $originalName, ?int $usuarioTiId): array
    {
        $start = microtime(true);
        $this->reset();

        [$rows, $formatInfo] = $this->openRows($filePath, $originalName);

        // 1) Localiza y valida encabezados ANTES de tocar la base de datos.
        $headerRow = null;
        while ($rows->valid()) {
            $cells = $rows->current();
            $rows->next();
            if (!self::isBlankRow($cells)) {
                $headerRow = $cells;
                break;
            }
        }
        if ($headerRow === null) {
            throw new GecaSyncException('El archivo está vacío: no se encontró la fila de encabezados.');
        }
        $map = $this->mapHeaders($headerRow);

        $lockName = $this->lockName();
        if ((int) $this->pdo->query('SELECT GET_LOCK(' . $this->pdo->quote($lockName) . ', 0)')->fetchColumn() !== 1) {
            throw new GecaSyncException('Ya hay otra carga GECA en proceso. Espera a que termine e inténtalo de nuevo.');
        }

        $this->extendLimits();
        $this->prepareStatements();

        try {
            $this->pdo->beginTransaction();

            // 2) Registro de auditoría.
            $stmt = $this->pdo->prepare(
                'INSERT INTO cargas_geca (nombre_archivo, usuario_ti_id) VALUES (?, ?)'
            );
            $stmt->execute([mb_substr($originalName, 0, 255), $usuarioTiId]);
            $cargaId = (int) $this->pdo->lastInsertId();

            // 3) Todo el inventario pasa a "no vigente" (sin alterar actualizado_en).
            $this->pdo->exec('UPDATE equipos SET vigente_en_geca = 0, actualizado_en = actualizado_en');

            // 4) UPSERT fila por fila.
            while ($rows->valid()) {
                $lineNumber = (int) $rows->key();
                $cells = $rows->current();
                $rows->next();

                if (self::isBlankRow($cells)) {
                    continue;
                }
                $this->stats['total']++;
                $this->processRow($this->extract($cells, $map), $lineNumber, $cargaId);
            }

            if ($this->stats['total'] === 0) {
                throw new GecaSyncException('El archivo solo contiene encabezados; no hay registros que cargar.');
            }

            $noVigentes = (int) $this->pdo->query('SELECT COUNT(*) FROM equipos WHERE vigente_en_geca = 0')->fetchColumn();

            $upd = $this->pdo->prepare(
                'UPDATE cargas_geca
                    SET total_registros = ?, nuevos_insertados = ?, actualizados = ?, marcados_no_vigentes = ?
                  WHERE id = ?'
            );
            $upd->execute([
                $this->stats['total'],
                $this->stats['equipos_nuevos'],
                $this->stats['equipos_actualizados'],
                $noVigentes,
                $cargaId,
            ]);

            $this->pdo->commit();
        } catch (Throwable $e) {
            if ($this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }
            throw $e;
        } finally {
            $this->pdo->query('SELECT RELEASE_LOCK(' . $this->pdo->quote($lockName) . ')');
        }

        if ($this->warningsOmitidas > 0) {
            $this->warnings[] = "… y {$this->warningsOmitidas} advertencias más no mostradas.";
        }

        return [
            'carga_id'              => $cargaId,
            'archivo'               => $originalName,
            'formato'               => $formatInfo,
            'total_registros'       => $this->stats['total'],
            'nuevos_insertados'     => $this->stats['equipos_nuevos'],
            'actualizados'          => $this->stats['equipos_actualizados'],
            'marcados_no_vigentes'  => $noVigentes,
            'omitidos'              => $this->stats['omitidos'],
            'usuarios_nuevos'       => $this->stats['usuarios_nuevos'],
            'usuarios_actualizados' => $this->stats['usuarios_actualizados'],
            'ubicaciones_nuevas'    => $this->stats['ubicaciones_nuevas'],
            'advertencias'          => $this->warnings,
            'segundos'              => round(microtime(true) - $start, 2),
            'memoria_pico_mb'       => round(memory_get_peak_usage(true) / 1048576, 1),
        ];
    }

    /**
     * Contenido de la plantilla CSV oficial (encabezados en orden).
     */
    public static function templateCsv(): string
    {
        return "\xEF\xBB\xBF" . implode(',', self::HEADERS) . "\r\n";
    }

    // ------------------------------------------------------------------
    //  Lectura del archivo
    // ------------------------------------------------------------------

    /**
     * @return array{0: Generator<int, list<string>>, 1: string}
     */
    private function openRows(string $filePath, string $originalName): array
    {
        $ext = strtolower(pathinfo($originalName, PATHINFO_EXTENSION));

        if ($ext === 'csv' || $ext === 'txt') {
            $csv = CsvReader::open($filePath);
            $delims = [',' => 'coma', ';' => 'punto y coma', "\t" => 'tabulador', '|' => 'barra'];
            $info = sprintf('CSV (%s, %s)', $delims[$csv->delimiter()] ?? $csv->delimiter(), $csv->encoding());
            return [$csv->readRows(), $info];
        }

        if ($ext === 'xlsx') {
            if (!$this->looksLikeZip($filePath)) {
                throw new GecaSyncException('El archivo tiene extensión .xlsx pero no es un libro de Excel válido.');
            }
            $xlsx = SimpleXLSX::parse($filePath);
            $names = $xlsx->sheetNames();
            return [$xlsx->readRows(0), 'XLSX (hoja "' . ($names[0] ?? '1') . '")'];
        }

        if ($ext === 'xls') {
            throw new GecaSyncException(
                'El formato .xls (Excel 97-2003) no está soportado. En Excel usa "Guardar como" > "Libro de Excel (.xlsx)" o "CSV UTF-8".'
            );
        }

        throw new GecaSyncException('Formato no soportado. Sube un archivo .xlsx o .csv.');
    }

    private function looksLikeZip(string $filePath): bool
    {
        $h = fopen($filePath, 'rb');
        if ($h === false) {
            return false;
        }
        $magic = (string) fread($h, 4);
        fclose($h);
        return $magic === "PK\x03\x04";
    }

    /**
     * @param list<string> $headerRow
     * @return array<string, int> encabezado canónico => índice de columna
     */
    private function mapHeaders(array $headerRow): array
    {
        $lookup = [];
        foreach (self::ALIASES as $canonical => $aliases) {
            $lookup[self::compact($canonical)] = $canonical;
            foreach ($aliases as $alias) {
                $lookup[$alias] = $canonical;
            }
        }

        $map = [];
        $duplicated = [];
        foreach ($headerRow as $index => $raw) {
            $key = self::compact((string) $raw);
            if ($key === '' || !isset($lookup[$key])) {
                continue;
            }
            $canonical = $lookup[$key];
            if (isset($map[$canonical])) {
                $duplicated[] = $canonical;
                continue;
            }
            $map[$canonical] = (int) $index;
        }

        $missing = array_values(array_diff(self::HEADERS, array_keys($map)));
        if ($missing !== []) {
            throw new GecaSyncException(
                'Faltan ' . count($missing) . ' encabezado(s) obligatorio(s) del GECA: '
                . implode(', ', $missing)
                . '. Descarga la plantilla para ver los 30 encabezados esperados.'
            );
        }
        if ($duplicated !== []) {
            throw new GecaSyncException(
                'Encabezados repetidos en el archivo: ' . implode(', ', array_unique($duplicated)) . '.'
            );
        }

        return $map;
    }

    /**
     * @param list<string>       $cells
     * @param array<string, int> $map
     * @return array<string, string>
     */
    private function extract(array $cells, array $map): array
    {
        $data = [];
        foreach ($map as $field => $index) {
            $value = $cells[$index] ?? '';
            // Normaliza espacios (incluye NBSP que Excel suele dejar).
            $value = str_replace("\u{00A0}", ' ', $value);
            $value = trim((string) preg_replace('/\s+/u', ' ', $value));
            $data[$field] = $value;
        }
        return $data;
    }

    // ------------------------------------------------------------------
    //  Procesamiento por fila
    // ------------------------------------------------------------------

    /**
     * @param array<string, string> $row
     */
    private function processRow(array $row, int $line, int $cargaId): void
    {
        $serie = self::normalizeKey($row['serie']);
        if ($serie === '') {
            $this->stats['omitidos']++;
            $this->warn("Fila {$line}: sin número de serie; registro omitido.");
            return;
        }
        if (mb_strlen($serie) > self::MAX_LEN['serie']) {
            $this->stats['omitidos']++;
            $this->warn("Fila {$line}: la serie \"{$serie}\" excede 60 caracteres; registro omitido.");
            return;
        }
        $serieKey = mb_strtoupper($serie);
        if (isset($this->seriesVistas[$serieKey])) {
            $this->warn("Fila {$line}: la serie {$serie} ya venía en la fila {$this->seriesVistas[$serieKey]}; se conservan los datos de la última.");
        }
        $this->seriesVistas[$serieKey] = $line;

        $ubicacionId = $this->resolveUbicacion($row);
        $usuarioId = $this->resolveUsuario($row, $line);

        $p = [
            ':serie'                    => $serie,
            ':usuario_id'               => $usuarioId,
            ':ubicacion_geca_id'        => $ubicacionId,
            ':ubicacion_real_id'        => $ubicacionId,
            ':folio_resguardo'          => $this->text($row, 'folio'),
            ':fecha_resguardo'          => $this->date($row, 'fecha', $line, false),
            ':estado_resguardo'         => $this->text($row, 'estado_resguardo'),
            ':numero_contrato'          => $this->text($row, 'numero_contrato'),
            ':estado_inventario'        => $this->text($row, 'estado_inventario'),
            ':marca'                    => $this->text($row, 'marca'),
            ':modelo'                   => $this->text($row, 'modelo'),
            ':perfil'                   => $this->text($row, 'perfil'),
            ':usuario_fecha_aceptacion' => $this->date($row, 'usuario_fecha_aceptacion', $line, true),
            ':ti_fecha_validacion'      => $this->date($row, 'ti_fecha_validacion', $line, true),
            ':fecha_baja'               => $this->date($row, 'fecha_baja', $line, true),
            ':fecha_carga_resguardo'    => $this->date($row, 'fecha_carga_resguardo', $line, true),
            ':fuera_consola'            => $this->text($row, 'fuera_consola'),
            ':motivo_baja'              => $this->text($row, 'motivo_baja'),
            ':serie_monitor1'           => $this->text($row, 'serie_monitor1'),
            ':marca_monitor1'           => $this->text($row, 'marca_monitor1'),
            ':modelo_monitor1'          => $this->text($row, 'modelo_monitor1'),
            ':serie_monitor2'           => $this->text($row, 'serie_monitor2'),
            ':marca_monitor2'           => $this->text($row, 'marca_monitor2'),
            ':modelo_monitor2'          => $this->text($row, 'modelo_monitor2'),
            ':ultima_carga_geca_id'     => $cargaId,
        ];

        $this->stmtEquipo->execute($p);
        // MySQL/MariaDB: 1 = insertado, 2 = actualizado, 0 = sin cambios.
        if ($this->stmtEquipo->rowCount() === 1) {
            $this->stats['equipos_nuevos']++;
        } else {
            $this->stats['equipos_actualizados']++;
        }
    }

    /**
     * Inserta o recupera la ubicación oficial. Los vacíos se guardan como ''
     * para que el índice único (eps..inmueble) no admita duplicados por NULL.
     *
     * @param array<string, string> $row
     */
    private function resolveUbicacion(array $row): ?int
    {
        $parts = [];
        foreach (['eps', 'subdireccion', 'gerencia', 'zona', 'sector', 'inmueble'] as $f) {
            $parts[$f] = mb_substr($row[$f], 0, self::MAX_LEN[$f]);
        }
        if (implode('', $parts) === '') {
            return null;
        }

        $key = mb_strtolower(implode("\x1F", $parts));
        if (isset($this->ubicacionCache[$key])) {
            return $this->ubicacionCache[$key];
        }

        $this->stmtUbicacion->execute(array_values($parts));
        if ($this->stmtUbicacion->rowCount() === 1) {
            $this->stats['ubicaciones_nuevas']++;
        }
        $id = (int) $this->pdo->lastInsertId();

        return $this->ubicacionCache[$key] = $id;
    }

    /**
     * UPSERT por ficha. En existentes solo cambian nombre y jefe_nombre:
     * nunca password_hash, email, rol, registrado ni ubicacion_habitual_id.
     *
     * @param array<string, string> $row
     */
    private function resolveUsuario(array $row, int $line): ?int
    {
        $ficha = self::normalizeKey($row['ficha']);
        if ($ficha === '') {
            return null;
        }
        if (mb_strlen($ficha) > self::MAX_LEN['ficha']) {
            $this->warn("Fila {$line}: la ficha \"{$ficha}\" excede 20 caracteres; el equipo quedó sin usuario.");
            return null;
        }

        $fichaKey = mb_strtoupper($ficha);
        if (isset($this->usuarioCache[$fichaKey])) {
            return $this->usuarioCache[$fichaKey];
        }

        $nombre = mb_substr($row['nombre'], 0, self::MAX_LEN['nombre']);
        $keepNombre = $nombre === '' ? 1 : 0;
        if ($nombre === '') {
            $nombre = 'SIN NOMBRE EN GECA';
            $this->warn("Fila {$line}: la ficha {$ficha} no trae nombre.");
        }
        $jefe = $row['jefe_nombre'] === '' ? null : mb_substr($row['jefe_nombre'], 0, self::MAX_LEN['jefe_nombre']);

        $this->stmtUsuario->execute([$ficha, $nombre, $jefe, $keepNombre]);
        $affected = $this->stmtUsuario->rowCount();
        if ($affected === 1) {
            $this->stats['usuarios_nuevos']++;
        } elseif ($affected === 2) {
            $this->stats['usuarios_actualizados']++;
        }
        $id = (int) $this->pdo->lastInsertId();

        return $this->usuarioCache[$fichaKey] = $id;
    }

    private function prepareStatements(): void
    {
        $this->stmtUbicacion = $this->pdo->prepare(
            'INSERT INTO ubicaciones (eps, subdireccion, gerencia, zona, sector, inmueble)
             VALUES (?, ?, ?, ?, ?, ?)
             ON DUPLICATE KEY UPDATE id = LAST_INSERT_ID(id)'
        );

        $this->stmtUsuario = $this->pdo->prepare(
            'INSERT INTO usuarios (ficha, nombre, jefe_nombre, registrado)
             VALUES (?, ?, ?, 0)
             ON DUPLICATE KEY UPDATE
                 id          = LAST_INSERT_ID(id),
                 nombre      = IF(? = 1, nombre, VALUES(nombre)),
                 jefe_nombre = VALUES(jefe_nombre)'
        );

        // ubicacion_real_id y es_principal SOLO se fijan al insertar;
        // no aparecen en ON DUPLICATE KEY UPDATE para preservarlos.
        $this->stmtEquipo = $this->pdo->prepare(
            'INSERT INTO equipos (
                 serie, usuario_id, ubicacion_geca_id, ubicacion_real_id, es_principal,
                 folio_resguardo, fecha_resguardo, estado_resguardo, numero_contrato,
                 estado_inventario, marca, modelo, perfil,
                 usuario_fecha_aceptacion, ti_fecha_validacion, fecha_baja, fecha_carga_resguardo,
                 fuera_consola, motivo_baja,
                 serie_monitor1, marca_monitor1, modelo_monitor1,
                 serie_monitor2, marca_monitor2, modelo_monitor2,
                 ultima_carga_geca_id, vigente_en_geca
             ) VALUES (
                 :serie, :usuario_id, :ubicacion_geca_id, :ubicacion_real_id, 0,
                 :folio_resguardo, :fecha_resguardo, :estado_resguardo, :numero_contrato,
                 :estado_inventario, :marca, :modelo, :perfil,
                 :usuario_fecha_aceptacion, :ti_fecha_validacion, :fecha_baja, :fecha_carga_resguardo,
                 :fuera_consola, :motivo_baja,
                 :serie_monitor1, :marca_monitor1, :modelo_monitor1,
                 :serie_monitor2, :marca_monitor2, :modelo_monitor2,
                 :ultima_carga_geca_id, 1
             )
             ON DUPLICATE KEY UPDATE
                 usuario_id               = VALUES(usuario_id),
                 ubicacion_geca_id        = VALUES(ubicacion_geca_id),
                 folio_resguardo          = VALUES(folio_resguardo),
                 fecha_resguardo          = VALUES(fecha_resguardo),
                 estado_resguardo         = VALUES(estado_resguardo),
                 numero_contrato          = VALUES(numero_contrato),
                 estado_inventario        = VALUES(estado_inventario),
                 marca                    = VALUES(marca),
                 modelo                   = VALUES(modelo),
                 perfil                   = VALUES(perfil),
                 usuario_fecha_aceptacion = VALUES(usuario_fecha_aceptacion),
                 ti_fecha_validacion      = VALUES(ti_fecha_validacion),
                 fecha_baja               = VALUES(fecha_baja),
                 fecha_carga_resguardo    = VALUES(fecha_carga_resguardo),
                 fuera_consola            = VALUES(fuera_consola),
                 motivo_baja              = VALUES(motivo_baja),
                 serie_monitor1           = VALUES(serie_monitor1),
                 marca_monitor1           = VALUES(marca_monitor1),
                 modelo_monitor1          = VALUES(modelo_monitor1),
                 serie_monitor2           = VALUES(serie_monitor2),
                 marca_monitor2           = VALUES(marca_monitor2),
                 modelo_monitor2          = VALUES(modelo_monitor2),
                 ultima_carga_geca_id     = VALUES(ultima_carga_geca_id),
                 vigente_en_geca          = 1'
        );
    }

    // ------------------------------------------------------------------
    //  Normalización de valores
    // ------------------------------------------------------------------

    /** @param array<string, string> $row */
    private function text(array $row, string $field): ?string
    {
        $value = $row[$field] ?? '';
        if ($value === '') {
            return null;
        }
        $max = self::MAX_LEN[$field] ?? 255;
        return mb_strlen($value) > $max ? mb_substr($value, 0, $max) : $value;
    }

    /**
     * Acepta fechas de Excel (serial numérico), dd/mm/aaaa [hh:mm[:ss]] [a. m./p. m.],
     * aaaa-mm-dd [hh:mm[:ss]] y variantes con guion o punto.
     *
     * @param array<string, string> $row
     */
    private function date(array $row, string $field, int $line, bool $withTime): ?string
    {
        $value = $row[$field] ?? '';
        if ($value === '' || preg_match('/^0{1,4}[-\/.]0{1,2}[-\/.]0{1,4}/', $value) === 1 || $value === '0') {
            return null;
        }

        $parsed = self::parseDate($value);
        if ($parsed === null) {
            $this->warn("Fila {$line}: fecha no reconocida en \"{$field}\" ({$value}); se guardó vacía.");
            return null;
        }
        return $withTime ? $parsed->format('Y-m-d H:i:s') : $parsed->format('Y-m-d');
    }

    public static function parseDate(string $value): ?DateTimeImmutable
    {
        $value = trim($value);

        // Serial de Excel (1 = 1900-01-01 ... 2958465 = 9999-12-31).
        if (preg_match('/^\d{1,6}(\.\d+)?$/', $value) === 1) {
            $serial = (float) $value;
            if ($serial >= 1 && $serial < 2958466) {
                return new DateTimeImmutable(SimpleXLSX::excelSerialToDateTime($serial));
            }
            return null;
        }

        // "a. m." / "p. m." de Excel en español -> AM / PM
        $normalized = (string) preg_replace(
            ['/\s*a\.?\s*m\.?$/iu', '/\s*p\.?\s*m\.?$/iu', '/T/', '/\.\d+$/'],
            [' AM', ' PM', ' ', ''],
            $value
        );
        $normalized = (string) preg_replace('/\s+/', ' ', $normalized);

        $formats = [
            'd/m/Y H:i:s', 'd/m/Y H:i', 'd/m/Y h:i:s A', 'd/m/Y h:i A', 'd/m/Y g:i:s A', 'd/m/Y g:i A', 'd/m/Y',
            'd-m-Y H:i:s', 'd-m-Y H:i', 'd-m-Y',
            'd.m.Y H:i:s', 'd.m.Y',
            'Y-m-d H:i:s', 'Y-m-d H:i', 'Y-m-d',
            'Y/m/d H:i:s', 'Y/m/d H:i', 'Y/m/d',
            'd/m/y H:i', 'd/m/y',
        ];
        foreach ($formats as $format) {
            $dt = DateTimeImmutable::createFromFormat('!' . $format, $normalized);
            if ($dt === false) {
                continue;
            }
            $errors = DateTimeImmutable::getLastErrors();
            if ($errors !== false && ($errors['warning_count'] > 0 || $errors['error_count'] > 0)) {
                continue;
            }
            $year = (int) $dt->format('Y');
            if ($year < 1900 || $year > 2100) {
                continue;
            }
            return $dt;
        }
        return null;
    }

    /**
     * Llaves naturales (ficha / serie): sin espacios internos y sin el ".0"
     * que agrega Excel al guardar números.
     */
    private static function normalizeKey(string $value): string
    {
        $value = trim((string) preg_replace('/\s+/u', '', $value));
        if (preg_match('/^\d+\.0+$/', $value) === 1) {
            $value = substr($value, 0, (int) strpos($value, '.'));
        }
        return $value;
    }

    /** Forma compacta de un encabezado: "Número de Serie" -> "numerodeserie". */
    private static function compact(string $header): string
    {
        $header = str_replace("\u{FEFF}", '', $header);
        $header = mb_strtolower(trim($header));
        $header = strtr($header, [
            'á' => 'a', 'é' => 'e', 'í' => 'i', 'ó' => 'o', 'ú' => 'u', 'ü' => 'u', 'ñ' => 'n',
            'à' => 'a', 'è' => 'e', 'ì' => 'i', 'ò' => 'o', 'ù' => 'u',
        ]);
        return (string) preg_replace('/[^a-z0-9]/', '', $header);
    }

    /** @param list<string> $cells */
    private static function isBlankRow(array $cells): bool
    {
        foreach ($cells as $c) {
            if (trim(str_replace("\u{00A0}", '', (string) $c)) !== '') {
                return false;
            }
        }
        return true;
    }

    // ------------------------------------------------------------------
    //  Utilidades
    // ------------------------------------------------------------------

    private function warn(string $message): void
    {
        if (count($this->warnings) < self::MAX_WARNINGS) {
            $this->warnings[] = $message;
        } else {
            $this->warningsOmitidas++;
        }
    }

    private function reset(): void
    {
        $this->ubicacionCache = [];
        $this->usuarioCache = [];
        $this->seriesVistas = [];
        $this->warnings = [];
        $this->warningsOmitidas = 0;
        $this->stats = [
            'total'                 => 0,
            'equipos_nuevos'        => 0,
            'equipos_actualizados'  => 0,
            'omitidos'              => 0,
            'usuarios_nuevos'       => 0,
            'usuarios_actualizados' => 0,
            'ubicaciones_nuevas'    => 0,
        ];
    }

    /** Los locks de MySQL son globales en el servidor compartido: se incluye la BD. */
    private function lockName(): string
    {
        $db = (string) $this->pdo->query('SELECT DATABASE()')->fetchColumn();
        return 'geca_sync_' . md5($db);
    }

    private function extendLimits(): void
    {
        $seconds = (int) (function_exists('config') ? config('geca.max_execution_time', 300) : 300);
        if (function_exists('set_time_limit')) {
            @set_time_limit($seconds);
        }
        // Si el agente cierra el navegador, la carga termina (o revierte) limpia.
        ignore_user_abort(true);
    }
}
