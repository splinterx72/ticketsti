<?php

declare(strict_types=1);

use App\Core\ValidationException;
use App\Services\ArchivoService;
use App\Services\TareaService;

function dir_tareas_prueba(): string
{
    $dir = sys_get_temp_dir() . '/mesa_tareas_' . getmypid();
    if (!is_dir($dir)) {
        mkdir($dir, 0700, true);
    }
    return $dir;
}

/** Simula $_FILES['x'] para un archivo temporal con contenido dado. @return array<string, mixed> */
function subida(string $nombre, string $contenido): array
{
    $tmp = tempnam(sys_get_temp_dir(), 'up');
    file_put_contents($tmp, $contenido);
    return ['name' => $nombre, 'tmp_name' => $tmp, 'size' => strlen($contenido), 'error' => UPLOAD_ERR_OK];
}

function archivos(): ArchivoService
{
    return new ArchivoService(dir_tareas_prueba(), false);
}

function asignacion_de(int $tareaId, array $agente): int
{
    return (int) scalar('SELECT id FROM tarea_asignaciones WHERE tarea_id = ? AND agente_id = ?', [$tareaId, $agente['id']]);
}

function test_folio_formato(): void
{
    $t = equipo_tareas();
    $id = (new TareaService(test_pdo()))->crear($t['sup'], tarea_form([$t['a1']]), []);
    assert_true(preg_match('/^TA-\d{4}-\d{6}$/', (string) scalar('SELECT folio FROM tareas WHERE id = ?', [$id])) === 1, 'folio TA-AAAA-NNNNNN');
}

function test_cada_agente_entrega_lo_suyo(): void
{
    $t = equipo_tareas();
    $svc = new TareaService(test_pdo());
    $id = $svc->crear($t['sup'], tarea_form([$t['a1'], $t['a2']]), []);
    assert_eq($svc->avance($id), ['total' => 2, 'entregadas' => 0, 'aceptadas' => 0], 'avance inicial');

    $foto = archivos()->guardar(subida('etiqueta.jpg', "\xFF\xD8\xFF\xE0" . str_repeat('a', 100)), 'evidencias');
    $svc->cambiarEstado(asignacion_de($id, $t['a1']), $t['a1'], 'entregar', 'Listo', [$foto]);
    assert_eq($svc->avance($id), ['total' => 2, 'entregadas' => 1, 'aceptadas' => 0], '1 de 2 entregadas');
    assert_throws(ValidationException::class, fn () => $svc->cambiarEstado(asignacion_de($id, $t['a1']), $t['a2'], 'entregar', 'no es mía', [$foto]),
        'un agente no entrega la asignación de otro');

    $svc->cambiarEstado(asignacion_de($id, $t['a1']), $t['sup'], 'aceptar', null);
    assert_eq($svc->avance($id)['aceptadas'], 1, 'aceptada por el supervisor');
}

function test_devolver_regresa_a_en_proceso_con_comentario(): void
{
    $t = equipo_tareas();
    $svc = new TareaService(test_pdo());
    $id = $svc->crear($t['sup'], tarea_form([$t['a1']], ['evidencias' => []]), []);
    $asig = asignacion_de($id, $t['a1']);
    $svc->cambiarEstado($asig, $t['a1'], 'entregar', 'Hecho', []);
    assert_throws(ValidationException::class, fn () => $svc->cambiarEstado($asig, $t['sup'], 'devolver', ''), 'devolver exige comentario', 'comentario');
    $svc->cambiarEstado($asig, $t['sup'], 'devolver', 'Falta el monitor 2');
    assert_eq(scalar('SELECT CONCAT(estado, "|", comentario_revision) FROM tarea_asignaciones WHERE id = ?', [$asig]), 'devuelta|Falta el monitor 2', 'devuelta con comentario');
    $svc->cambiarEstado($asig, $t['a1'], 'iniciar', null);
    assert_eq(scalar('SELECT estado FROM tarea_asignaciones WHERE id = ?', [$asig]), 'en_proceso', 'el agente la retoma');
}

function test_entregar_sin_evidencia_requerida_falla(): void
{
    $t = equipo_tareas();
    $svc = new TareaService(test_pdo());
    $id = $svc->crear($t['sup'], tarea_form([$t['a1']]), []);
    assert_throws(ValidationException::class, fn () => $svc->cambiarEstado(asignacion_de($id, $t['a1']), $t['a1'], 'entregar', 'sin fotos', []),
        'la tarea pide evidencias', 'evidencia');
}

function test_agente_ajeno_no_descarga_evidencia(): void
{
    $t = equipo_tareas();
    $svc = new TareaService(test_pdo());
    $ref = archivos()->guardar(subida('formato.pdf', "%PDF-1.4\n" . str_repeat('x', 50)), 'referencia');
    $id = $svc->crear($t['sup'], tarea_form([$t['a1'], $t['a2']]), [$ref]);
    $img = imagecreatetruecolor(4, 4);
    ob_start();
    imagepng($img);
    $foto = archivos()->guardar(subida('etiqueta.png', (string) ob_get_clean()), 'evidencias');
    $svc->cambiarEstado(asignacion_de($id, $t['a1']), $t['a1'], 'entregar', 'Listo', [$foto]);

    $evid = test_pdo()->query('SELECT * FROM tarea_archivos WHERE asignacion_id IS NOT NULL')->fetch();
    $refRow = test_pdo()->query('SELECT * FROM tarea_archivos WHERE asignacion_id IS NULL')->fetch();
    $otro = usuario('U9');
    assert_true($svc->puedeDescargar($t['a1'], $evid), 'el dueño descarga su evidencia');
    assert_true($svc->puedeDescargar($t['sup'], $evid), 'el supervisor descarga la evidencia');
    assert_true(!$svc->puedeDescargar($t['a2'], $evid), 'otro agente de la misma tarea NO descarga mi evidencia');
    assert_true($svc->puedeDescargar($t['a2'], $refRow), 'los agentes de la tarea descargan el archivo de referencia');
    assert_true(!$svc->puedeDescargar($otro, $refRow), 'alguien ajeno no descarga nada');
    assert_true(!str_contains((string) $evid['ruta'], 'etiqueta'), 'el nombre guardado no usa el nombre original');
}

function test_rechaza_php_y_doble_extension(): void
{
    $a = archivos();
    assert_throws(ValidationException::class, fn () => $a->guardar(subida('shell.php', '<?php echo 1;'), 'evidencias'), '.php rechazado');
    assert_throws(ValidationException::class, fn () => $a->guardar(subida('foto.php.jpg', "<?php system(\$_GET['c']); ?>"), 'evidencias'), 'doble extensión con PHP rechazada');
    assert_throws(ValidationException::class, fn () => $a->guardar(subida('foto.jpg', "%PDF-1.4\n"), 'evidencias'), 'contenido que no coincide con la extensión');
    assert_throws(ValidationException::class, fn () => $a->guardar(subida('grande.pdf', "%PDF-1.4\n" . str_repeat('x', 10 * 1024 * 1024)), 'evidencias'), 'más de 10 MB');
    $ok = $a->guardar(subida('Reporte Final (1).pdf', "%PDF-1.4\n" . str_repeat('x', 50)), 'evidencias');
    assert_true(preg_match('#^evidencias/\d{4}/\d{2}/[0-9a-f]{32}\.pdf$#', $ok['ruta']) === 1, 'ruta aleatoria por año/mes: ' . $ok['ruta']);
    assert_eq($ok['nombre_original'], 'Reporte Final (1).pdf', 'conserva el nombre original para la descarga');
}

function test_supervisor_no_asigna_agente_ajeno(): void
{
    $t = equipo_tareas();
    $ajeno = usuario('AGX', 'agente_ti');
    asignar_agente($ajeno['id'], instalacion('B'));
    assert_throws(ValidationException::class, fn () => (new TareaService(test_pdo()))->crear($t['sup'], tarea_form([$ajeno]), []),
        'agente de otra instalación', 'agentes');
    assert_throws(ValidationException::class, fn () => (new TareaService(test_pdo()))->crear($t['a1'], tarea_form([$t['a2']]), []),
        'un agente no asigna tareas');
}

function test_agente_bloqueado_no_opera(): void
{
    $t = equipo_tareas();
    $svc = new TareaService(test_pdo());
    $id = $svc->crear($t['sup'], tarea_form([$t['a1']], ['evidencias' => []]), []);
    $bloqueado = ['admin_local_cambiado_en' => null] + $t['a1'];
    assert_throws(ValidationException::class, fn () => $svc->cambiarEstado(asignacion_de($id, $t['a1']), $bloqueado, 'iniciar', null),
        'contraseña de admin local por vencer bloquea la operación', 'admin local');
}

function test_recordatorio_tarea_vence_manana(): void
{
    $GLOBALS['__app_config']['mail']['driver'] = 'smtp';
    \App\Services\Mail\Notifier::reiniciarCola();
    try {
        $t = equipo_tareas();
        $svc = new TareaService(test_pdo());
        $id = $svc->crear($t['sup'], tarea_form([$t['a1'], $t['a2']], ['evidencias' => []]), []);
        test_pdo()->prepare('UPDATE tareas SET entrega_en = ? WHERE id = ?')->execute([date('Y-m-d 18:00:00', strtotime('+1 day')), $id]);
        $svc->cambiarEstado(asignacion_de($id, $t['a2']), $t['a2'], 'entregar', 'ya', []);
        $rec = new \App\Services\Recordatorios(test_pdo());
        $rec->ejecutar(date('Y-m-d'));
        $rec->ejecutar(date('Y-m-d'));
        $destinos = array_column(array_filter(\App\Services\Mail\Notifier::cola(), static fn (array $m): bool => str_contains($m['subject'], 'vence mañana')), 'to');
        assert_eq($destinos, ['ag1@pemex.com'], 'solo al agente que no ha entregado, una sola vez');
    } finally {
        \App\Services\Mail\Notifier::reiniciarCola();
        $GLOBALS['__app_config']['mail']['driver'] = 'off';
    }
}

function test_lista_del_supervisor_con_avance(): void
{
    $t = equipo_tareas();
    $svc = new TareaService(test_pdo());
    $id = $svc->crear($t['sup'], tarea_form([$t['a1'], $t['a2']], ['evidencias' => []]), []);
    $svc->cambiarEstado(asignacion_de($id, $t['a1']), $t['a1'], 'entregar', 'ok', []);
    $lista = $svc->asignadasPor($t['sup']);
    assert_eq(count($lista), 1, 'el supervisor ve su tarea');
    assert_eq([(int) $lista[0]['total'], (int) $lista[0]['entregadas'], (int) $lista[0]['por_revisar']], [2, 1, 1], 'avance en la lista');
}

function test_entrega_simultanea_no_se_duplica(): void
{
    $t = equipo_tareas();
    $svc = new TareaService(test_pdo());
    $id = $svc->crear($t['sup'], tarea_form([$t['a1']], ['evidencias' => []]), []);
    $asig = asignacion_de($id, $t['a1']);
    $svc->cambiarEstado($asig, $t['a1'], 'iniciar', null);

    // Otra petición (doble clic) entrega primero y tarda en confirmar.
    $c = test_db_config();
    $sql = "BEGIN; SELECT id FROM tarea_asignaciones WHERE id = {$asig} FOR UPDATE;"
         . " UPDATE tarea_asignaciones SET estado = 'entregada', entregado_en = NOW() WHERE id = {$asig}; DO SLEEP(1.5); COMMIT;";
    $p = proc_open(['mariadb', '-h', $c['host'], '-u', $c['username'], '-p' . $c['password'], $c['database'], '-e', $sql], [], $pipes);
    usleep(400000);
    assert_throws(ValidationException::class, fn () => $svc->cambiarEstado($asig, $t['a1'], 'entregar', 'segunda vez', []),
        'la segunda entrega simultánea se rechaza');
    proc_close($p);
    assert_eq(scalar('SELECT COUNT(comentario_entrega) FROM tarea_asignaciones WHERE id = ?', [$asig]), 0, 'la segunda entrega no sobrescribió la primera');
}

function test_hoja_de_reportes_con_meta_porcentaje_y_aviso_al_100(): void
{
    $t = equipo_tareas();
    $svc = new TareaService(test_pdo());
    $id = $svc->crear($t['sup'], tarea_form([$t['a1'], $t['a2']], ['meta_unidad' => 'equipos', 'meta_cantidad' => '10', 'evidencias' => []]), []);
    $a1 = asignacion_de($id, $t['a1']);

    assert_throws(ValidationException::class, fn () => $svc->reportar($a1, $t['a2'], 'no es mía', 3), 'solo el agente asignado');
    $r = $svc->reportar($a1, $t['a1'], 'Formateé 4 equipos del nivel 1', 4);
    assert_eq([$r['porcentaje'], $r['completa']], [40, false], '40 %');
    assert_eq(scalar('SELECT estado FROM tarea_asignaciones WHERE id = ?', [$a1]), 'en_proceso', 'al reportar pasa a en proceso');

    assert_throws(ValidationException::class, fn () => $svc->cambiarEstado($a1, $t['a1'], 'entregar', '', []), 'cierre antes del 100 % pide motivo');

    $foto = archivos()->guardar(subida('nivel2.jpg', "\xFF\xD8\xFF\xE0" . str_repeat('b', 100)), 'evidencias');
    $r = $svc->reportar($a1, $t['a1'], 'Nivel 2 completo', 7, [$foto]);
    assert_eq([$r['porcentaje'], $r['completa']], [100, true], 'tope en 100 % y avisa');
    assert_eq($svc->reportar($a1, $t['a1'], 'Revisión final', 0)['completa'], false, 'el aviso sale una sola vez');
    assert_eq((int) scalar('SELECT COUNT(*) FROM tarea_archivos WHERE avance_id IS NOT NULL'), 1, 'la evidencia queda ligada a su reporte');

    $d = $svc->detalle($id);
    assert_eq([$d['hecho'], $d['meta_total'], $d['porcentaje']], [11, 20, 55], 'avance total de la tarea (2 agentes × 10)');
    assert_eq(count($d['asignaciones'][0]['avances']) + count($d['asignaciones'][1]['avances']), 3, 'reportes en la hoja');

    $svc->cambiarEstado($a1, $t['a1'], 'entregar', '', []);
    assert_eq(scalar('SELECT estado FROM tarea_asignaciones WHERE id = ?', [$a1]), 'entregada', 'cierre al 100 % sin motivo');
    assert_throws(ValidationException::class, fn () => $svc->reportar($a1, $t['a1'], 'tarde', 1), 'cerrada no admite reportes');

    $lista = $svc->asignadasPor($t['sup']);
    assert_eq((int) $lista[0]['hecho'], 11, 'la lista del supervisor trae lo hecho');
}

function test_meta_otro_pide_descripcion_y_sin_meta_no_hay_porcentaje(): void
{
    $t = equipo_tareas();
    $svc = new TareaService(test_pdo());
    assert_throws(ValidationException::class, fn () => $svc->crear($t['sup'], tarea_form([$t['a1']], ['meta_unidad' => 'otro', 'meta_cantidad' => '5']), []), 'otro sin descripción');
    assert_throws(ValidationException::class, fn () => $svc->crear($t['sup'], tarea_form([$t['a1']], ['meta_unidad' => 'equipos', 'meta_cantidad' => '0']), []), 'cantidad 0');
    $id = $svc->crear($t['sup'], tarea_form([$t['a1']], ['evidencias' => []]), []);
    $r = $svc->reportar(asignacion_de($id, $t['a1']), $t['a1'], 'Avance sin meta', 9);
    assert_eq($r['porcentaje'], null, 'sin meta no hay %');
    assert_eq((int) scalar('SELECT cantidad FROM tarea_avances'), 0, 'sin meta la cantidad no cuenta');
}

function test_ticket_de_ti_suma_sus_equipos_a_la_tarea(): void
{
    $t = equipo_tareas();
    $svc = new TareaService(test_pdo());
    $id = $svc->crear($t['sup'], tarea_form([$t['a1']], ['meta_unidad' => 'equipos', 'meta_cantidad' => '2', 'evidencias' => []]), []);
    $dueno = usuario('22222');
    $e1 = equipo_geca($dueno, 'SER-T1');
    $e2 = equipo_geca($dueno, 'SER-T2');
    $r = (new \App\Services\TicketService(test_pdo()))->create($t['a1'], ticket_form([
        'tipo_ticket' => 'tarea', 'equipos' => [(string) $e1, (string) $e2], 'tarea_asignacion_id' => (string) asignacion_de($id, $t['a1']),
    ]));
    assert_eq($r['tarea_completa'], asignacion_de($id, $t['a1']), 'con el ticket llegó al 100 %');
    assert_eq((int) scalar('SELECT ticket_id FROM tarea_avances'), $r['id'], 'el reporte apunta al ticket');
    assert_throws(ValidationException::class, fn () => (new \App\Services\TicketService(test_pdo()))->create($t['a2'], ticket_form([
        'tipo_ticket' => 'tarea', 'tarea_asignacion_id' => (string) asignacion_de($id, $t['a1']),
    ])), 'no se suma a la tarea de otro');
}

function test_lista_de_equipos_un_ticket_por_equipo_o_solo_registro(): void
{
    $t = equipo_tareas();
    $pdo = test_pdo();
    $svc = new TareaService($pdo);
    $dueno = usuario('22222');
    foreach (['L-1', 'L-2', 'L-3', 'L-4'] as $s) {
        equipo_geca($dueno, $s);
    }
    assert_throws(ValidationException::class, fn () => $svc->crear($t['sup'], tarea_form([$t['a1']], ['meta_unidad' => 'equipos', 'equipos_series' => "L-1\nNO-EXISTE", 'evidencias' => []]), []), 'serie desconocida');
    $id = $svc->crear($t['sup'], tarea_form([$t['a1'], $t['a2']], ['meta_unidad' => 'equipos', 'meta_cantidad' => '', 'equipos_series' => "l-1\nL-2, L-3\nL-4", 'evidencias' => []]), []);
    assert_eq((int) scalar('SELECT meta_cantidad FROM tareas WHERE id = ?', [$id]), 4, 'la meta es el número de equipos de la lista');
    $a1 = asignacion_de($id, $t['a1']);
    $a2 = asignacion_de($id, $t['a2']);
    $eq = static fn (string $s): string => (string) scalar('SELECT id FROM equipos WHERE serie = ?', [$s]);
    $cat = (int) scalar('SELECT MIN(id) FROM categorias');

    // Con ticket: uno por cada equipo, con los datos de la tarea.
    $r = $svc->intervenir($a1, $t['a1'], [$eq('L-1'), $eq('L-2')], 'Formateo', true, $cat);
    assert_eq(count($r['tickets']), 2, 'dos equipos → dos tickets');
    $tk = $pdo->prepare('SELECT t.tipo_ticket, t.titulo, t.agente_asignado_id, (SELECT COUNT(*) FROM ticket_equipos te WHERE te.ticket_id = t.id) AS n FROM tickets t WHERE t.id = ?');
    $tk->execute([$r['tickets'][0]['id']]);
    $row = $tk->fetch();
    assert_eq([$row['tipo_ticket'], (int) $row['n'], (int) $row['agente_asignado_id']], ['tarea', 1, $t['a1']['id']], 'ticket tipo Tarea, un equipo, asignado al agente');
    assert_true(str_contains((string) $row['titulo'], 'L-1') || str_contains((string) $row['titulo'], 'L-2'), 'el título lleva la serie');
    assert_eq((int) scalar('SELECT COUNT(*) FROM tarea_equipos WHERE ticket_id IS NOT NULL'), 2, 'cada equipo ligado a su ticket');
    assert_throws(ValidationException::class, fn () => $svc->intervenir($a2, $t['a2'], [$eq('L-1')], '', false), 'un equipo ya intervenido no se vuelve a marcar');

    // Sin ticket: solo el registro en la hoja; otro agente puede tomar los demás.
    $r = $svc->intervenir($a2, $t['a2'], [$eq('L-3')], 'Revisado', false);
    assert_eq([$r['tickets'], $r['completa']], [[], false], 'sin tickets');
    assert_eq($svc->totalesLista($id), ['total' => 4, 'atendidos' => 3], '3 de 4');
    assert_throws(ValidationException::class, fn () => $svc->cambiarEstado($a1, $t['a1'], 'entregar', '', []), 'al 75 % el cierre pide motivo');

    $r = $svc->intervenir($a1, $t['a1'], [$eq('L-4')], '', true, $cat);
    assert_true($r['completa'], 'con el último equipo llega al 100 % y avisa');
    $d = $svc->detalle($id);
    assert_eq([$d['hecho'], $d['meta_total'], $d['porcentaje']], [4, 4, 100], 'avance de la tarea por la lista');
    assert_eq((int) $svc->asignadasPor($t['sup'])[0]['lista_hecho'], 4, 'la lista del supervisor trae lo intervenido');
}
