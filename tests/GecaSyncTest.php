<?php

declare(strict_types=1);

use App\Services\GecaSyncException;
use App\Services\GecaSyncService;

/** Encabezados del reporte "Equipos entregados con estatus", en su orden real. */
const GECA_COLUMNAS = [
    'folio', 'fecha', 'numero_orden', 'dia', 'mes', 'año', 'estado_resguardo', 'estado_inventario',
    'numero_contrato', 'nombre', 'ficha', 'eps', 'subdireccion', 'gerencia', 'jefe_ficha', 'jefe_nombre',
    'perfil', 'tipo_componente', 'serie', 'marca', 'modelo', 'lote', 'zona', 'sector', 'inmueble',
    'ubicacion_referencia', 'entidad_federativa', 'municipio', 'usuario_fecha_aceptacion', 'ti_fecha_validacion',
    'tipo_asignacion', 'motivo_fuera_consola', 'fecha_baja', 'pr_folio', 'pr_fecha', 'pr_estado_resguardo',
    'pr_nombre', 'pr_ficha', 'pr_eps', 'pr_zona',
];

/** Una fila del reporte con valores por defecto. @return array<string, string> */
function geca_fila(string $folio, string $ficha, string $tipo, string $serie, array $extra = []): array
{
    return $extra + [
        'folio' => $folio, 'fecha' => '18/11/2026 01:13:10 p. m.', 'numero_orden' => 'C5-OS4-ZC-PMX',
        'dia' => '18', 'mes' => '11', 'año' => '2026', 'estado_resguardo' => 'CONCLUIDO', 'estado_inventario' => 'ACTIVO',
        'numero_contrato' => 'PMX-2024-15-5', 'nombre' => "PERSONA {$ficha}", 'ficha' => $ficha,
        'eps' => 'PETRÓLEOS MEXICANOS', 'subdireccion' => 'SUBDIRECCION DE TI', 'gerencia' => 'GERENCIA DE REDES',
        'jefe_ficha' => '330981', 'jefe_nombre' => 'JEFE DE PRUEBA', 'perfil' => 'PC USO GENERAL',
        'tipo_componente' => $tipo, 'serie' => $serie, 'marca' => 'HP', 'modelo' => "Modelo {$tipo}", 'lote' => 'PEMEX-BE-0065',
        'zona' => 'CENTRO', 'sector' => 'AZCAPOTZALCO', 'inmueble' => 'TORRE EJECUTIVA', 'ubicacion_referencia' => 'T.E. PISO 01',
        'entidad_federativa' => 'CIUDAD DE MEXICO', 'municipio' => 'MIGUEL HIDALGO',
        'usuario_fecha_aceptacion' => '18/11/2026 01:13:10 p. m.', 'ti_fecha_validacion' => '19/11/2026 09:00:00 a. m.',
        'tipo_asignacion' => 'ASIGNACIÓN PERSONAL', 'motivo_fuera_consola' => '', 'fecha_baja' => '',
        'pr_folio' => '', 'pr_fecha' => '', 'pr_estado_resguardo' => '', 'pr_nombre' => '', 'pr_ficha' => '', 'pr_eps' => '', 'pr_zona' => '',
    ];
}

/** Escribe el CSV y ejecuta la carga. @param list<array<string, string>> $filas @param list<string>|null $columnas */
function cargar_geca(array $filas, ?array $columnas = null): array
{
    $columnas ??= GECA_COLUMNAS;
    $path = tempnam(sys_get_temp_dir(), 'geca') . '.csv';
    $h = fopen($path, 'wb');
    fputcsv($h, $columnas, ',', '"', '');
    foreach ($filas as $f) {
        fputcsv($h, array_map(static fn (string $c): string => $f[$c] ?? '', $columnas), ',', '"', '');
    }
    fclose($h);
    try {
        return (new GecaSyncService(test_pdo()))->sync($path, 'Equipos_entregados.csv', null);
    } finally {
        @unlink($path);
    }
}

/** @return array<string, mixed>|null */
function equipo_por_serie(string $serie): ?array
{
    $s = test_pdo()->prepare('SELECT e.*, u.ficha FROM equipos e LEFT JOIN usuarios u ON u.id = e.usuario_id WHERE e.serie = ?');
    $s->execute([$serie]);
    return $s->fetch() ?: null;
}

function test_monitores_del_mismo_folio_se_casan_con_su_cpu(): void
{
    cargar_geca([
        geca_fila('100', '581253', 'MONITOR', 'MON-A', ['marca' => 'HP', 'modelo' => 'E24mv G4']),
        geca_fila('100', '581253', 'CPU', 'CPU-A'),
        geca_fila('100', '581253', 'MONITOR', 'MON-B', ['modelo' => 'P24']),
    ]);
    $cpu = equipo_por_serie('CPU-A');
    assert_eq([$cpu['tipo_componente'], $cpu['ficha']], ['CPU', '581253'], 'CPU cargado a su ficha');
    assert_eq([$cpu['serie_monitor1'], $cpu['marca_monitor1'], $cpu['modelo_monitor1']], ['MON-A', 'HP', 'E24mv G4'], 'monitor 1 casado');
    assert_eq([$cpu['serie_monitor2'], $cpu['modelo_monitor2']], ['MON-B', 'P24'], 'monitor 2 casado');
    assert_eq(equipo_por_serie('MON-A'), null, 'el monitor casado no es un equipo aparte');
}

function test_monitor_de_laptop_en_otro_folio_se_casa_con_su_unica_laptop(): void
{
    cargar_geca([
        geca_fila('200', '576384', 'LAPTOP', 'LAP-1', ['perfil' => 'LAPTOP COMPLETA']),
        geca_fila('201', '576384', 'MONITOR', 'MON-L1'),
        geca_fila('201', '576384', 'REPLICADOR', 'REP-1', ['perfil' => 'REPLICADOR DE PUERTOS PARA LAPTOP COMPLETA']),
    ]);
    assert_eq(equipo_por_serie('LAP-1')['serie_monitor1'], 'MON-L1', 'el monitor del otro folio se casa con la laptop');
    $rep = equipo_por_serie('REP-1');
    assert_eq([$rep['tipo_componente'] ?? null, $rep['ficha'] ?? null], ['REPLICADOR', '576384'], 'el replicador es un equipo propio del trabajador');
    assert_eq($rep['serie_monitor1'] ?? null, null, 'el replicador no recibe monitores');
    assert_eq(equipo_por_serie('MON-L1'), null, 'monitor casado no queda suelto');
}

function test_monitor_con_varias_computadoras_libres_queda_como_equipo_propio(): void
{
    cargar_geca([
        geca_fila('300', '111111', 'LAPTOP', 'LAP-X'),
        geca_fila('301', '111111', 'LAPTOP', 'LAP-Y'),
        geca_fila('302', '111111', 'MONITOR', 'MON-X'),
        geca_fila('303', '222222', 'MONITOR', 'MON-SOLO'),
        geca_fila('304', '222222', 'PROYECTOR', 'PROY-1', ['perfil' => 'VIDEO PROYECTOR FIJO']),
    ]);
    assert_eq(equipo_por_serie('LAP-X')['serie_monitor1'], null, 'no se casa al azar (laptop X)');
    assert_eq(equipo_por_serie('LAP-Y')['serie_monitor1'], null, 'no se casa al azar (laptop Y)');
    $mon = equipo_por_serie('MON-X');
    assert_eq([$mon['tipo_componente'] ?? null, $mon['ficha'] ?? null], ['MONITOR', '111111'], 'el monitor ambiguo es un equipo propio');
    assert_eq(equipo_por_serie('MON-SOLO')['tipo_componente'] ?? null, 'MONITOR', 'sin computadora: monitor propio (el proyector no es computadora)');
    assert_eq(equipo_por_serie('PROY-1')['tipo_componente'] ?? null, 'PROYECTOR', 'proyector como equipo propio');
}

function test_resguardo_en_baja_queda_sin_usuario_y_no_crea_ficha(): void
{
    cargar_geca([geca_fila('400', '999001', 'CPU', 'CPU-B', ['nombre' => 'PERSONA ORIGINAL'])]);
    $id = (int) equipo_por_serie('CPU-B')['id'];
    test_pdo()->prepare('UPDATE equipos SET es_principal = 1, real_nivel_id = 2 WHERE id = ?')->execute([$id]);

    $r = cargar_geca([
        geca_fila('401', '999001', 'CPU', 'CPU-B', ['estado_resguardo' => 'BAJA', 'estado_inventario' => 'DISPONIBLE', 'fecha_baja' => '20/11/2026 10:00:00 a. m.', 'nombre' => 'NOMBRE NUEVO']),
        geca_fila('402', '999002', 'LAPTOP', 'LAP-B', ['estado_resguardo' => 'BAJA', 'estado_inventario' => 'DISPONIBLE']),
        geca_fila('402', '999002', 'MONITOR', 'MON-LB', ['estado_resguardo' => 'BAJA', 'estado_inventario' => 'DISPONIBLE']),
    ]);
    $cpu = equipo_por_serie('CPU-B');
    assert_eq([$cpu['usuario_id'], $cpu['estado_resguardo'], (int) $cpu['vigente_en_geca']], [null, 'BAJA', 1], 'en baja: vigente en GECA pero sin trabajador');
    assert_eq([(int) $cpu['es_principal'], (int) $cpu['real_nivel_id']], [1, 2], 'se preservan los datos propios de la app');
    assert_eq(scalar("SELECT nombre FROM usuarios WHERE ficha = '999001'"), 'PERSONA ORIGINAL', 'una fila en baja no actualiza al trabajador');
    assert_eq(scalar("SELECT COUNT(*) FROM usuarios WHERE ficha = '999002'"), 0, 'una fila en baja no crea fichas');
    assert_eq(equipo_por_serie('LAP-B')['serie_monitor1'], 'MON-LB', 'en baja, el monitor del mismo folio se casa igual');
    assert_eq($r['usuarios_nuevos'], 0, 'resumen sin usuarios nuevos');
}

function test_guarda_columnas_nuevas_y_resguardo_anterior(): void
{
    cargar_geca([geca_fila('500', '581253', 'PC', 'PC-1', [
        'motivo_fuera_consola' => 'No conectado a RED (por requerimiento de la solución o plataforma)',
        'tipo_asignacion' => 'SALAS DE JUNTAS', 'ubicacion_referencia' => 'EDIFICIO TECNICO ADMINSTRATIVO / PISO 2 UASIPA',
        'pr_folio' => '30557', 'pr_fecha' => '21/08/2024 10:36:34 a. m.', 'pr_estado_resguardo' => 'BAJA',
        'pr_nombre' => 'EUNICE HUERTA', 'pr_ficha' => '328729', 'pr_eps' => 'PETRÓLEOS MEXICANOS', 'pr_zona' => 'CENTRO',
    ])]);
    $e = equipo_por_serie('PC-1');
    assert_eq([$e['numero_orden'] ?? null, $e['lote'] ?? null, $e['tipo_asignacion'] ?? null], ['C5-OS4-ZC-PMX', 'PEMEX-BE-0065', 'SALAS DE JUNTAS'], 'orden, lote y tipo de asignación');
    assert_eq([$e['entidad_federativa'] ?? null, $e['municipio'] ?? null, $e['ubicacion_referencia'] ?? null],
        ['CIUDAD DE MEXICO', 'MIGUEL HIDALGO', 'EDIFICIO TECNICO ADMINSTRATIVO / PISO 2 UASIPA'], 'entidad, municipio y referencia');
    assert_eq($e['fuera_consola'], 'No conectado a RED (por requerimiento de la solución o plataforma)', 'motivo fuera de consola completo');
    assert_eq([$e['pr_folio'] ?? null, $e['pr_fecha'] ?? null, $e['pr_estado_resguardo'] ?? null, $e['pr_nombre'] ?? null, $e['pr_ficha'] ?? null, $e['pr_eps'] ?? null, $e['pr_zona'] ?? null],
        ['30557', '2024-08-21 10:36:34', 'BAJA', 'EUNICE HUERTA', '328729', 'PETRÓLEOS MEXICANOS', 'CENTRO'], 'resguardo anterior');
    assert_eq([$e['fecha_resguardo'], $e['ti_fecha_validacion']], ['2026-11-18', '2026-11-19 09:00:00'], 'fechas en español');
    assert_eq(scalar("SELECT CONCAT(jefe_ficha, '|', jefe_nombre) FROM usuarios WHERE ficha = '581253'"), '330981|JEFE DE PRUEBA', 'ficha y nombre del jefe');
}

function test_upsert_preserva_datos_y_marca_no_vigentes(): void
{
    cargar_geca([
        geca_fila('600', '700001', 'LAPTOP', 'LAP-U'),
        geca_fila('601', '700001', 'MONITOR', 'MON-U1'),
        geca_fila('601', '700001', 'MONITOR', 'MON-U2'),
        geca_fila('602', '700001', 'LAPTOP', 'LAP-OTRA'),
        geca_fila('603', '700002', 'CPU', 'CPU-SE-VA'),
    ]);
    assert_eq(equipo_por_serie('MON-U1')['tipo_componente'] ?? null, 'MONITOR', 'primera carga: dos laptops libres, monitor propio');
    $lap = (int) equipo_por_serie('LAP-U')['id'];
    test_pdo()->prepare('UPDATE equipos SET es_principal = 1, real_ala = ? WHERE id = ?')->execute(['norte', $lap]);
    test_pdo()->exec("UPDATE usuarios SET email = 'x@pemex.com', registrado = 1 WHERE ficha = '700001'");

    // La segunda laptop se fue: ahora los monitores ya se pueden casar con LAP-U.
    cargar_geca([
        geca_fila('600', '700001', 'LAPTOP', 'LAP-U', ['nombre' => 'NOMBRE CORREGIDO']),
        geca_fila('601', '700001', 'MONITOR', 'MON-U1'),
        geca_fila('601', '700001', 'MONITOR', 'MON-U2'),
    ]);
    $e = equipo_por_serie('LAP-U');
    assert_eq([$e['serie_monitor1'], $e['serie_monitor2']], ['MON-U1', 'MON-U2'], 'segunda carga: monitores casados');
    assert_eq([(int) $e['es_principal'], $e['real_ala']], [1, 'norte'], 'se preservan es_principal y ubicación real');
    assert_eq((int) equipo_por_serie('MON-U1')['vigente_en_geca'], 0, 'el monitor que era equipo propio queda no vigente (no se borra)');
    assert_eq((int) equipo_por_serie('CPU-SE-VA')['vigente_en_geca'], 0, 'equipo ausente del archivo: no vigente');
    assert_eq(scalar("SELECT CONCAT(nombre, '|', email, '|', registrado) FROM usuarios WHERE ficha = '700001'"), 'NOMBRE CORREGIDO|x@pemex.com|1', 'usuario: nombre actualizado, cuenta intacta');
}

function test_falta_encabezado_obligatorio_no_toca_nada(): void
{
    $sin = array_values(array_diff(GECA_COLUMNAS, ['tipo_componente']));
    assert_throws(GecaSyncException::class, fn () => cargar_geca([geca_fila('700', '1', 'CPU', 'CPU-Z')], $sin), 'sin tipo_componente se rechaza', 'tipo_componente');
    assert_eq(scalar('SELECT COUNT(*) FROM cargas_geca'), 0, 'no se registró la carga');
    assert_eq(scalar('SELECT COUNT(*) FROM equipos'), 0, 'no se cargó nada');
}

function test_plantilla_lista_las_columnas_del_reporte(): void
{
    $plantilla = trim(str_replace("\xEF\xBB\xBF", '', GecaSyncService::templateCsv()));
    assert_eq(explode(',', $plantilla), GECA_COLUMNAS, 'la plantilla es el encabezado del reporte');
}

function test_sin_equipo_principal_la_computadora_va_primero(): void
{
    cargar_geca([
        geca_fila('800', '810001', 'LAPTOP', 'ZZZ-LAPTOP'),
        geca_fila('801', '810001', 'REPLICADOR', 'AAA-REPLICADOR'),
        geca_fila('802', '810001', 'PROYECTOR', 'BBB-PROYECTOR'),
    ]);
    $uid = (int) scalar("SELECT id FROM usuarios WHERE ficha = '810001'");
    $lista = (new \App\Services\TicketService(test_pdo()))->equiposDeUsuario($uid);
    assert_eq(array_column($lista, 'serie'), ['ZZZ-LAPTOP', 'AAA-REPLICADOR', 'BBB-PROYECTOR'], 'la computadora se preselecciona antes que accesorios');
}
