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
 * Formato: reporte "Equipos entregados con estatus" (una fila por componente:
 * CPU, PC, LAPTOP, MONITOR, REPLICADOR, PROYECTOR...). Diseño en
 * docs/superpowers/specs/2026-11-23-geca-por-componente-design.md.
 *
 * Reglas (CLAUDE.md, sección 3.A):
 *  - PROHIBIDO TRUNCATE / DELETE sobre usuarios y equipos.
 *  - Llaves naturales: usuarios.ficha y equipos.serie.
 *  - Flujo: registro en cargas_geca -> todo el inventario a vigente_en_geca = 0
 *    -> por cada fila: ubicación (get-or-create), usuario (UPSERT por ficha,
 *    solo nombre y jefe), equipo (UPSERT por serie).
 *  - En equipos existentes se preservan intactos ubicacion_real_id, real_*,
 *    es_principal y, por no tocarse sus tablas, garantias y tickets.
 *  - Los monitores se "casan" como monitor 1/2 de la computadora de su folio
 *    o, si su folio no trae computadora, de la ÚNICA computadora libre de la
 *    misma ficha; si no, quedan como equipo propio (nunca al azar).
 *  - Resguardo en BAJA: el equipo se guarda sin usuario y no toca usuarios.
 *
 * Toda la carga corre dentro de UNA transacción: si algo falla, la base
 * queda exactamente como estaba antes del lunes.
 */
final class GecaSyncService
{
    /** Las 40 columnas del reporte oficial, en su orden (plantilla descargable). */
    public const HEADERS = [
        'folio', 'fecha', 'numero_orden', 'dia', 'mes', 'año', 'estado_resguardo', 'estado_inventario',
        'numero_contrato', 'nombre', 'ficha', 'eps', 'subdireccion', 'gerencia', 'jefe_ficha', 'jefe_nombre',
        'perfil', 'tipo_componente', 'serie', 'marca', 'modelo', 'lote', 'zona', 'sector', 'inmueble',
        'ubicacion_referencia', 'entidad_federativa', 'municipio', 'usuario_fecha_aceptacion', 'ti_fecha_validacion',
        'tipo_asignacion', 'motivo_fuera_consola', 'fecha_baja', 'pr_folio', 'pr_fecha', 'pr_estado_resguardo',
        'pr_nombre', 'pr_ficha', 'pr_eps', 'pr_zona',
    ];

    /** Encabezados sin los cuales el archivo se rechaza completo. */
    public const REQUIRED = [
        'folio', 'fecha', 'estado_resguardo', 'estado_inventario', 'numero_contrato', 'nombre', 'ficha',
        'eps', 'subdireccion', 'gerencia', 'jefe_nombre', 'perfil', 'tipo_componente', 'serie', 'marca', 'modelo',
        'zona', 'sector', 'inmueble', 'usuario_fecha_aceptacion', 'ti_fecha_validacion', 'fecha_baja',
    ];

    /** Tipos de componente que pueden recibir monitores casados. */
    public const COMPUTADORAS = ['CPU', 'PC', 'LAPTOP', 'CHROMEBOOK', 'MACBOOK'];

    /**
     * Variantes aceptadas de cada encabezado que se guarda, en forma "compacta"
     * (minúsculas, sin acentos, sin espacios ni signos). dia/mes/año se ignoran.
     */
    private const ALIASES = [
        'folio'                    => ['folio', 'folioresguardo', 'foliodelresguardo', 'foliogeca'],
        'fecha'                    => ['fecha', 'fecharesguardo', 'fechadelresguardo'],
        'numero_orden'             => ['numeroorden', 'numerodeorden', 'orden', 'noorden'],
        'estado_resguardo'         => ['estadoresguardo', 'estadodelresguardo', 'statusresguardo'],
        'estado_inventario'        => ['estadoinventario', 'estadodelinventario', 'statusinventario'],
        'numero_contrato'          => ['numerocontrato', 'numerodecontrato', 'numcontrato', 'nocontrato', 'contrato'],
        'nombre'                   => ['nombre', 'nombreusuario', 'nombredelusuario', 'nombreempleado', 'nombredelempleado'],
        'ficha'                    => ['ficha', 'numficha', 'noficha', 'numeroficha', 'numerodeficha', 'fichausuario'],
        'eps'                      => ['eps'],
        'subdireccion'             => ['subdireccion'],
        'gerencia'                 => ['gerencia'],
        'jefe_ficha'               => ['jefeficha', 'fichajefe', 'fichadeljefe'],
        'jefe_nombre'              => ['jefenombre', 'jefe', 'nombrejefe', 'nombredeljefe', 'jefeinmediato'],
        'perfil'                   => ['perfil', 'perfilequipo'],
        'tipo_componente'          => ['tipocomponente', 'tipodecomponente', 'componente', 'tipoequipo', 'tipodeequipo', 'tipobien', 'tipodebien', 'tipoactivo'],
        'serie'                    => ['serie', 'numserie', 'noserie', 'numeroserie', 'numerodeserie'],
        'marca'                    => ['marca'],
        'modelo'                   => ['modelo'],
        'lote'                     => ['lote'],
        'zona'                     => ['zona'],
        'sector'                   => ['sector'],
        'inmueble'                 => ['inmueble', 'edificio'],
        'ubicacion_referencia'     => ['ubicacionreferencia', 'referencia', 'referenciaubicacion'],
        'entidad_federativa'       => ['entidadfederativa', 'entidad', 'estado'],
        'municipio'                => ['municipio'],
        'usuario_fecha_aceptacion' => ['usuariofechaaceptacion', 'fechaaceptacionusuario', 'fechaaceptacion', 'fechadeaceptacion'],
        'ti_fecha_validacion'      => ['tifechavalidacion', 'fechavalidacionti', 'fechavalidacion', 'fechadevalidacion'],
        'tipo_asignacion'          => ['tipoasignacion', 'tipodeasignacion'],
        'motivo_fuera_consola'     => ['motivofueraconsola', 'motivofueradeconsola', 'fueraconsola', 'fueradeconsola'],
        'fecha_baja'               => ['fechabaja', 'fechadebaja'],
        'pr_folio'                 => ['prfolio'],
        'pr_fecha'                 => ['prfecha'],
        'pr_estado_resguardo'      => ['prestadoresguardo'],
        'pr_nombre'                => ['prnombre'],
        'pr_ficha'                 => ['prficha'],
        'pr_eps'                   => ['preps'],
        'pr_zona'                  => ['przona'],
    ];

    /** Longitudes máximas de columna (evita errores en modo SQL estricto). */
    private const MAX_LEN = [
        'folio' => 40, 'estado_resguardo' => 60, 'numero_contrato' => 60, 'numero_orden' => 60,
        'nombre' => 150, 'ficha' => 20, 'jefe_ficha' => 20, 'jefe_nombre' => 150,
        'eps' => 80, 'subdireccion' => 120, 'gerencia' => 120, 'zona' => 100, 'sector' => 100, 'inmueble' => 150,
        'serie' => 60, 'estado_inventario' => 60, 'marca' => 60, 'modelo' => 80, 'perfil' => 80,
        'tipo_componente' => 30, 'lote' => 60, 'ubicacion_referencia' => 150, 'entidad_federativa' => 80,
        'municipio' => 100, 'tipo_asignacion' => 80, 'motivo_fuera_consola' => 120,
        'pr_folio' => 40, 'pr_estado_resguardo' => 60, 'pr_nombre' => 150, 'pr_ficha' => 20, 'pr_eps' => 80, 'pr_zona' => 100,
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

    /** @var array<string, list<array{0: string, 1: string, 2: string}>> serie de computadora => monitores [serie, marca, modelo] */
    private array $monitoresDe = [];

    /** @var array<string, true> series de monitores casados (no se guardan como equipo) */
    private array $monitorCasado = [];

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

        // 1) Encabezados y casado de monitores ANTES de tocar la base de datos.
        [$rows, $formatInfo] = $this->openRows($filePath, $originalName);
        $map = $this->mapHeaders($this->headerRow($rows));
        $this->planCasados($rows, $map);
        unset($rows);

        // 2) Segunda pasada: se vuelve a leer el archivo para el UPSERT.
        [$rows] = $this->openRows($filePath, $originalName);
        $this->headerRow($rows);

        $lockName = $this->lockName();
        if ((int) $this->pdo->query('SELECT GET_LOCK(' . $this->pdo->quote($lockName) . ', 0)')->fetchColumn() !== 1) {
            throw new GecaSyncException('Ya hay otra carga GECA en proceso. Espera a que termine e inténtalo de nuevo.');
        }

        $this->extendLimits();
        $this->prepareStatements();

        try {
            $this->pdo->beginTransaction();

            // 3) Registro de auditoría.
            $stmt = $this->pdo->prepare(
                'INSERT INTO cargas_geca (nombre_archivo, usuario_ti_id) VALUES (?, ?)'
            );
            $stmt->execute([mb_substr($originalName, 0, 255), $usuarioTiId]);
            $cargaId = (int) $this->pdo->lastInsertId();

            // 4) Todo el inventario pasa a "no vigente" (sin alterar actualizado_en).
            $this->pdo->exec('UPDATE equipos SET vigente_en_geca = 0, actualizado_en = actualizado_en');

            // 5) UPSERT fila por fila.
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
            'monitores_casados'     => $this->stats['monitores_casados'],
            'en_baja'               => $this->stats['en_baja'],
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

    /**
     * Primera fila no vacía (encabezados); deja el generador en la fila siguiente.
     *
     * @param Generator<int, list<string>> $rows
     * @return list<string>
     */
    private function headerRow(Generator $rows): array
    {
        while ($rows->valid()) {
            $cells = $rows->current();
            $rows->next();
            if (!self::isBlankRow($cells)) {
                return $cells;
            }
        }
        throw new GecaSyncException('El archivo está vacío: no se encontró la fila de encabezados.');
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
        $desconocidos = [];
        foreach ($headerRow as $index => $raw) {
            $key = self::compact((string) $raw);
            if ($key === '' || !isset($lookup[$key])) {
                if ($key !== '' && !in_array($key, ['dia', 'mes', 'ano'], true)) {
                    $desconocidos[] = '"' . mb_substr(trim((string) $raw), 0, 60) . '"';
                }
                continue;
            }
            $canonical = $lookup[$key];
            if (isset($map[$canonical])) {
                $duplicated[] = $canonical;
                continue;
            }
            $map[$canonical] = (int) $index;
        }

        $missing = array_values(array_diff(self::REQUIRED, array_keys($map)));
        if ($missing !== []) {
            throw new GecaSyncException(
                'Faltan ' . count($missing) . ' encabezado(s) obligatorio(s) del GECA: '
                . implode(', ', $missing)
                . '. Sube el reporte "Equipos entregados con estatus" del GECA (descarga la plantilla para ver las columnas).'
                . ($desconocidos === [] ? ' El archivo no trae columnas adicionales.'
                    : ' Columnas del archivo que no se reconocieron: ' . implode(', ', array_slice($desconocidos, 0, 15))
                      . (count($desconocidos) > 15 ? ' y ' . (count($desconocidos) - 15) . ' más' : '') . '.')
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
        $data = array_fill_keys(array_keys(self::ALIASES), '');
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
        if (isset($this->monitorCasado[$serieKey])) {
            $this->stats['monitores_casados']++;
            return;
        }
        if (isset($this->seriesVistas[$serieKey])) {
            $this->warn("Fila {$line}: la serie {$serie} ya venía en la fila {$this->seriesVistas[$serieKey]}; se conservan los datos de la última.");
        }
        $this->seriesVistas[$serieKey] = $line;

        $enBaja = self::enBaja($row);
        if ($enBaja) {
            $this->stats['en_baja']++;
        }
        $ubicacionId = $this->resolveUbicacion($row);
        $usuarioId = $enBaja ? null : $this->resolveUsuario($row, $line);
        $monitores = $this->monitoresDe[$serieKey] ?? [];

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
            ':fuera_consola'            => $this->text($row, 'motivo_fuera_consola'),
            ':tipo_componente'          => $this->text(['tipo_componente' => mb_strtoupper($row['tipo_componente'])], 'tipo_componente'),
            ':numero_orden'             => $this->text($row, 'numero_orden'),
            ':lote'                     => $this->text($row, 'lote'),
            ':ubicacion_referencia'     => $this->text($row, 'ubicacion_referencia'),
            ':entidad_federativa'       => $this->text($row, 'entidad_federativa'),
            ':municipio'                => $this->text($row, 'municipio'),
            ':tipo_asignacion'          => $this->text($row, 'tipo_asignacion'),
            ':pr_folio'                 => $this->text($row, 'pr_folio'),
            ':pr_fecha'                 => $this->date($row, 'pr_fecha', $line, true),
            ':pr_estado_resguardo'      => $this->text($row, 'pr_estado_resguardo'),
            ':pr_nombre'                => $this->text($row, 'pr_nombre'),
            ':pr_ficha'                 => $this->text($row, 'pr_ficha'),
            ':pr_eps'                   => $this->text($row, 'pr_eps'),
            ':pr_zona'                  => $this->text($row, 'pr_zona'),
            ':serie_monitor1'           => $monitores[0][0] ?? null,
            ':marca_monitor1'           => $monitores[0][1] ?? null,
            ':modelo_monitor1'          => $monitores[0][2] ?? null,
            ':serie_monitor2'           => $monitores[1][0] ?? null,
            ':marca_monitor2'           => $monitores[1][1] ?? null,
            ':modelo_monitor2'          => $monitores[1][2] ?? null,
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

    // ------------------------------------------------------------------
    //  Casado de monitores (pasada 1, sin tocar la base de datos)
    // ------------------------------------------------------------------

    /**
     * Decide a qué computadora va cada monitor:
     *  1. A la computadora de su mismo folio (máximo 2 monitores).
     *  2. Si su folio no trae computadora y no está en baja: a la ÚNICA computadora
     *     "libre" (folio sin monitores, no en baja) de la misma ficha, si en total
     *     son 2 monitores o menos.
     *  3. Si no, el monitor queda como equipo propio.
     *
     * @param Generator<int, list<string>> $rows
     * @param array<string, int>           $map
     */
    private function planCasados(Generator $rows, array $map): void
    {
        $iSerie = $map['serie'];
        $iTipo = $map['tipo_componente'];
        $iFolio = $map['folio'];
        $iFicha = $map['ficha'];
        $iMarca = $map['marca'];
        $iModelo = $map['modelo'];
        $iEstado = $map['estado_resguardo'];

        /** @var array<string, array{c: list<string>, m: list<array{0: string, 1: string, 2: string}>, f: string, b: bool}> $folios */
        $folios = [];
        // while (no foreach): el generador ya avanzó más allá de los encabezados.
        for (; $rows->valid(); $rows->next()) {
            $cells = $rows->current();
            $serie = self::normalizeKey((string) ($cells[$iSerie] ?? ''));
            $folio = trim((string) ($cells[$iFolio] ?? ''));
            if ($serie === '' || $folio === '' || mb_strlen($serie) > self::MAX_LEN['serie']) {
                continue;
            }
            $tipo = mb_strtoupper(trim((string) ($cells[$iTipo] ?? '')));
            $esMonitor = $tipo === 'MONITOR';
            if (!$esMonitor && !in_array($tipo, self::COMPUTADORAS, true)) {
                continue;
            }
            $f = &$folios[$folio];
            $f ??= ['c' => [], 'm' => [], 'f' => mb_strtoupper(self::normalizeKey((string) ($cells[$iFicha] ?? ''))), 'b' => false];
            if (self::enBaja(['estado_resguardo' => trim((string) ($cells[$iEstado] ?? ''))])) {
                $f['b'] = true;
            }
            if ($esMonitor) {
                $f['m'][] = [
                    $serie,
                    mb_substr(trim((string) ($cells[$iMarca] ?? '')), 0, self::MAX_LEN['marca']),
                    mb_substr(trim((string) ($cells[$iModelo] ?? '')), 0, self::MAX_LEN['modelo']),
                ];
            } else {
                $f['c'][] = mb_strtoupper($serie);
            }
            unset($f);
        }

        // 1) Mismo folio.
        $libres = [];   // ficha => [serie de computadora libre, ...]
        $sueltos = [];  // ficha => [monitor, ...]
        foreach ($folios as $folio => $f) {
            if ($f['c'] !== []) {
                if (count($f['c']) > 1 && $f['m'] !== []) {
                    $this->warn("Folio {$folio}: trae varias computadoras; sus monitores se casan con la primera ({$f['c'][0]}).");
                }
                if ($f['m'] === []) {
                    if (!$f['b'] && count($f['c']) === 1 && $f['f'] !== '') {
                        $libres[$f['f']][] = $f['c'][0];
                    }
                    continue;
                }
                $this->casar($f['c'][0], $f['m'], (string) $folio);
                continue;
            }
            if ($f['m'] !== [] && !$f['b'] && $f['f'] !== '') {
                $sueltos[$f['f']] = array_merge($sueltos[$f['f']] ?? [], $f['m']);
            }
        }

        // 2) Folio sin computadora: la única computadora libre de la misma ficha.
        foreach ($sueltos as $ficha => $monitores) {
            $candidatas = $libres[$ficha] ?? [];
            if (count($candidatas) === 1 && count($monitores) <= 2) {
                $this->casar($candidatas[0], $monitores, null);
            }
        }
    }

    /**
     * @param list<array{0: string, 1: string, 2: string}> $monitores
     */
    private function casar(string $computadora, array $monitores, ?string $folio): void
    {
        foreach ($monitores as $i => $m) {
            if ($i >= 2) {
                $this->warn("Folio {$folio}: más de 2 monitores; el monitor {$m[0]} queda como equipo propio.");
                continue;
            }
            $this->monitoresDe[$computadora][] = $m;
            $this->monitorCasado[mb_strtoupper($m[0])] = true;
        }
    }

    /** @param array<string, string> $row */
    private static function enBaja(array $row): bool
    {
        return mb_strtoupper(trim($row['estado_resguardo'] ?? '')) === 'BAJA';
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
     * UPSERT por ficha. En existentes solo cambian nombre y jefe (ficha y nombre):
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
        $jefeFicha = self::normalizeKey($row['jefe_ficha']);
        $jefeFicha = $jefeFicha === '' ? null : mb_substr($jefeFicha, 0, self::MAX_LEN['jefe_ficha']);

        $this->stmtUsuario->execute([$ficha, $nombre, $jefeFicha, $jefe, $keepNombre]);
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
            'INSERT INTO usuarios (ficha, nombre, jefe_ficha, jefe_nombre, registrado)
             VALUES (?, ?, ?, ?, 0)
             ON DUPLICATE KEY UPDATE
                 id          = LAST_INSERT_ID(id),
                 nombre      = IF(? = 1, nombre, VALUES(nombre)),
                 jefe_ficha  = VALUES(jefe_ficha),
                 jefe_nombre = VALUES(jefe_nombre)'
        );

        // ubicacion_real_id, real_* y es_principal SOLO se fijan al insertar;
        // no aparecen en ON DUPLICATE KEY UPDATE para preservarlos.
        $campos = [
            'folio_resguardo', 'fecha_resguardo', 'estado_resguardo', 'numero_contrato',
            'estado_inventario', 'marca', 'modelo', 'perfil',
            'usuario_fecha_aceptacion', 'ti_fecha_validacion', 'fecha_baja', 'fuera_consola',
            'tipo_componente', 'numero_orden', 'lote', 'ubicacion_referencia', 'entidad_federativa', 'municipio',
            'tipo_asignacion', 'pr_folio', 'pr_fecha', 'pr_estado_resguardo', 'pr_nombre', 'pr_ficha', 'pr_eps', 'pr_zona',
            'serie_monitor1', 'marca_monitor1', 'modelo_monitor1', 'serie_monitor2', 'marca_monitor2', 'modelo_monitor2',
            'ultima_carga_geca_id',
        ];
        $update = implode(",\n                 ", array_map(static fn (string $c): string => "{$c} = VALUES({$c})", $campos));
        $this->stmtEquipo = $this->pdo->prepare(
            'INSERT INTO equipos (serie, usuario_id, ubicacion_geca_id, ubicacion_real_id, es_principal, '
            . implode(', ', $campos) . ', vigente_en_geca)
             VALUES (:serie, :usuario_id, :ubicacion_geca_id, :ubicacion_real_id, 0, :'
            . implode(', :', $campos) . ', 1)
             ON DUPLICATE KEY UPDATE
                 usuario_id        = VALUES(usuario_id),
                 ubicacion_geca_id = VALUES(ubicacion_geca_id),
                 ' . $update . ',
                 vigente_en_geca   = 1'
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
        $this->monitoresDe = [];
        $this->monitorCasado = [];
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
            'monitores_casados'     => 0,
            'en_baja'               => 0,
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
