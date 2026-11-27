<?php

declare(strict_types=1);

use App\Core\ValidationException;
use App\Services\FormatoService;
use App\Services\TicketService;

const CLAVE_F = 'Clave#Segura2026';

/** PNG de una firma dibujada (trazo real, no en blanco). */
function firma_png(): string
{
    $im = imagecreatetruecolor(230, 50);
    imagesavealpha($im, true);
    imagefill($im, 0, 0, imagecolorallocatealpha($im, 255, 255, 255, 127));
    $negro = imagecolorallocate($im, 0, 0, 0);
    imagesetthickness($im, 2);
    imageline($im, 10, 40, 80, 10, $negro);
    imageline($im, 80, 10, 150, 35, $negro);
    imageline($im, 150, 35, 220, 12, $negro);
    ob_start();
    imagepng($im);
    return 'data:image/png;base64,' . base64_encode((string) ob_get_clean());
}

function con_clave(array $u): array
{
    test_pdo()->prepare('UPDATE usuarios SET password_hash = ? WHERE id = ?')->execute([password_hash(CLAVE_F, PASSWORD_DEFAULT), $u['id']]);
    return $u;
}

/** Trabajador con equipo GECA, su jefe, un agente de la instalación y un ticket con ese equipo. */
function escenario_formato(): array
{
    $e = ejemplos_usuario();
    (new \App\Services\UbicacionFisicaService(test_pdo()))->mapearInmueble('Torre Ejecutiva', $e['km']);
    test_pdo()->prepare("UPDATE instalaciones SET ciudad = 'Ciudad del Carmen, Campeche' WHERE id = ?")->execute([$e['km']]);
    $jefe = con_clave(usuario('330981', 'usuario', 'jefe@pemex.com'));
    $w = con_clave(usuario('386650', 'usuario', 'alexis@pemex.com'));
    test_pdo()->prepare("UPDATE usuarios SET nombre = 'ALEXIS JIMENEZ HERNANDEZ', telefono_ext = '80174257', jefe_ficha = '330981', jefe_nombre = 'REYNA MARIA RUEDA' WHERE id = ?")->execute([$w['id']]);
    $w['nombre'] = 'ALEXIS JIMENEZ HERNANDEZ';
    $eq = equipo_geca($w, '5CD4221L1H');
    test_pdo()->prepare("UPDATE equipos SET tipo_componente = 'LAPTOP', marca = 'HP', modelo = 'ProBook 450 G10' WHERE id = ?")->execute([$eq]);
    test_pdo()->prepare("UPDATE ubicaciones SET eps = 'PETRÓLEOS MEXICANOS', subdireccion = 'SUBDIRECCIÓN DE COORDINACIÓN FINANCIERA', gerencia = 'GERENCIA DE FINANZAS'")->execute();
    $ag = con_clave(usuario('510441', 'agente_ti', 'jacqueline@pemex.com'));
    asignar_agente($ag['id'], $e['km']);
    $r = (new TicketService(test_pdo()))->create($w, ticket_form([
        'equipo_id' => (string) $eq, 'componente_afectado' => 'equipo',
        'reportada_ubicacion_fisica_id' => (string) $e['holkanaab'], 'reportada_nivel_id' => (string) nivel_id('PB'),
        'reportada_ala' => 'norte', 'reportada_referencia' => 'Cubículo 4',
    ]));
    return $e + ['jefe' => $jefe, 'w' => $w, 'eq' => $eq, 'ag' => $ag, 'ticket' => $r['id']];
}

function test_plantilla_del_anio_se_crea_con_el_texto_oficial(): void
{
    $p = (new FormatoService(test_pdo()))->plantillaVigente('formateo');
    assert_eq((int) $p['anio'], (int) date('Y'), 'versión del año en curso');
    assert_eq(count($p['contenido']['motivos']), 4, 'los 4 motivos del formato oficial');
    assert_true(str_contains(implode(' ', $p['contenido']['parrafos']), 'II.1.27'), 'trae las políticas');
    assert_eq($p['contenido']['metodos'], ['Imagen institucional', 'Instalación limpia', 'Restablecimiento de fábrica'], 'métodos de formateo');
}

function test_emitir_llena_los_datos_solos(): void
{
    $s = escenario_formato();
    $svc = new FormatoService(test_pdo());
    $id = $svc->emitir((int) $s['ticket'], $s['ag']);
    $f = $svc->find($id);
    assert_true(preg_match('/^FF-\d{4}-\d{6}$/', (string) $f['folio']) === 1, 'folio FF-AAAA-NNNNNN');
    assert_eq($f['estado'], 'pendiente_usuario', 'espera la firma del usuario');
    $d = $svc->datos($f);
    assert_eq([$d['usuario'], $d['ficha'], $d['ext']], ['ALEXIS JIMENEZ HERNANDEZ', '386650', '80174257'], 'usuario, ficha y extensión limpia');
    assert_eq([$d['organismo'], $d['subdireccion'], $d['gerencia']], ['PETRÓLEOS MEXICANOS', 'SUBDIRECCIÓN DE COORDINACIÓN FINANCIERA', 'GERENCIA DE FINANZAS'], 'datos del GECA');
    assert_true(str_contains($d['ubicacion'], 'Edificio Holkanaab') && str_contains($d['ubicacion'], 'Cubículo 4'), 'ubicación física y referencia');
    assert_eq([$d['tipo'], $d['marca'], $d['modelo'], $d['serie'], $d['inventario']], ['LAPTOP', 'HP', 'ProBook 450 G10', '5CD4221L1H', 'N/A'], 'equipo (inventario N/A si no se ha capturado)');
    assert_eq($d['lugar'], 'Ciudad del Carmen, Campeche', 'ciudad de la instalación');
    assert_eq($d['remedy'], '', 'Remedy en blanco hasta que llegue');
    assert_eq($d['responsable_sugerido'], ['ficha' => '330981', 'nombre' => 'Persona 330981'], 'sugiere al jefe inmediato por su ficha');

    test_pdo()->prepare("UPDATE tickets SET folio_remedy = 'WO0000014931785' WHERE id = ?")->execute([$s['ticket']]);
    $svc->guardarInventario((int) $s['eq'], 'PMX-123456', $s['ag']);
    $d = $svc->datos($svc->find($id));
    assert_eq([$d['remedy'], $d['inventario']], ['WO0000014931785', 'PMX-123456'], 'mientras no se complete, refleja lo capturado después');
    assert_throws(ValidationException::class, fn () => $svc->emitir((int) $s['ticket'], $s['ag']), 'un solo formato activo por ticket', 'ya tiene');
}

function test_firmas_en_orden_con_sus_casos(): void
{
    $s = escenario_formato();
    $svc = new FormatoService(test_pdo());
    $id = $svc->emitir((int) $s['ticket'], $s['ag']);

    assert_throws(ValidationException::class, fn () => $svc->firmar($id, 'responsable', $s['jefe'], ['png' => firma_png(), 'password' => CLAVE_F]), 'el jefe no firma antes que el usuario', 'usuario');
    assert_throws(ValidationException::class, fn () => $svc->firmar($id, 'usuario', $s['w'], ['png' => firma_png(), 'password' => CLAVE_F, 'motivos' => []]), 'sin motivo no', 'motivo');
    assert_throws(ValidationException::class, fn () => $svc->firmar($id, 'usuario', $s['w'], ['png' => firma_png(), 'password' => 'mala', 'motivos' => [1]]), 'contraseña', 'contraseña');
    assert_throws(ValidationException::class, fn () => $svc->firmar($id, 'usuario', $s['w'], ['png' => 'data:image/png;base64,AAAA', 'password' => CLAVE_F, 'motivos' => [1]]), 'firma inválida', 'firma');
    assert_throws(ValidationException::class, fn () => $svc->firmar($id, 'usuario', $s['jefe'], ['png' => firma_png(), 'password' => CLAVE_F, 'motivos' => [1]]), 'otro trabajador no firma por el usuario');

    $svc->firmar($id, 'usuario', $s['w'], ['png' => firma_png(), 'password' => CLAVE_F, 'motivos' => [1]]);
    assert_eq($svc->find($id)['estado'], 'pendiente_responsable', 'sigue el responsable');
    assert_eq($svc->find($id)['motivos_lista'], ['Falla del Sistema operativo o reasignación.'], 'motivo marcado');

    // Otro jefe de área firma en persona en el dispositivo del técnico.
    $otroJefe = usuario('316054', 'usuario');
    test_pdo()->prepare("UPDATE usuarios SET nombre = 'REYNA MARIA RUEDA NADA', registrado = 0 WHERE id = ?")->execute([$otroJefe['id']]);
    assert_throws(ValidationException::class, fn () => $svc->firmar($id, 'responsable', $s['ag'], ['png' => firma_png(), 'password' => CLAVE_F, 'modo' => 'presencial', 'ficha' => '999999']), 'ficha inexistente', 'ficha');
    $svc->firmar($id, 'responsable', $s['ag'], ['png' => firma_png(), 'password' => CLAVE_F, 'modo' => 'presencial', 'ficha' => '316054']);
    $firmas = $svc->firmas($id);
    assert_eq([$firmas['responsable']['nombre'], $firmas['responsable']['ficha'], $firmas['responsable']['modo'], (int) $firmas['responsable']['capturado_por']], ['REYNA MARIA RUEDA NADA', '316054', 'presencial', $s['ag']['id']], 'nombre desde la base por ficha; queda quién capturó');

    assert_throws(ValidationException::class, fn () => $svc->firmar($id, 'ti', $s['w'], ['png' => firma_png(), 'password' => CLAVE_F, 'metodo' => 'Imagen institucional']), 'un trabajador no firma como TI');
    assert_throws(ValidationException::class, fn () => $svc->firmar($id, 'ti', $s['ag'], ['png' => firma_png(), 'password' => CLAVE_F, 'metodo' => 'Otro']), 'método válido', 'método');
    $svc->firmar($id, 'ti', $s['ag'], ['png' => firma_png(), 'password' => CLAVE_F, 'metodo' => 'Imagen institucional']);
    $f = $svc->find($id);
    assert_eq([$f['estado'], $f['metodo']], ['completo', 'Imagen institucional'], 'completo con el método');

    // Congelado: cambios posteriores no alteran el documento firmado.
    test_pdo()->prepare("UPDATE usuarios SET telefono_ext = '99999' WHERE id = ?")->execute([$s['w']['id']]);
    assert_eq($svc->datos($svc->find($id))['ext'], '80174257', 'el documento firmado no cambia');
    assert_eq(scalar("SELECT COUNT(*) FROM comentarios WHERE ticket_id = ? AND mensaje LIKE '[Sistema] Formato FF-%: firmó %'", [$s['ticket']]), 3, 'cada firma queda en el seguimiento');
}

function test_el_usuario_firma_dos_veces_si_no_hay_responsable(): void
{
    $s = escenario_formato();
    $svc = new FormatoService(test_pdo());
    $id = $svc->emitir((int) $s['ticket'], $s['ag']);
    $svc->firmar($id, 'usuario', $s['w'], ['png' => firma_png(), 'password' => CLAVE_F, 'motivos' => [0, 3]]);
    assert_throws(ValidationException::class, fn () => $svc->firmar($id, 'responsable', $s['w'], ['png' => firma_png(), 'password' => CLAVE_F, 'mismo_usuario' => '1', 'motivo' => '']), 'debe explicar por qué', 'motivo');
    $svc->firmar($id, 'responsable', $s['w'], ['png' => firma_png(), 'password' => CLAVE_F, 'mismo_usuario' => '1', 'motivo' => 'Jefe de vacaciones, urgente']);
    $r = $svc->firmas($id)['responsable'];
    assert_eq([$r['ficha'], $r['motivo']], ['386650', 'Jefe de vacaciones, urgente'], 'firma el mismo usuario y queda el motivo');
}

function test_el_jefe_firma_desde_su_sesion(): void
{
    $s = escenario_formato();
    $svc = new FormatoService(test_pdo());
    $id = $svc->emitir((int) $s['ticket'], $s['ag']);
    $svc->firmar($id, 'usuario', $s['w'], ['png' => firma_png(), 'password' => CLAVE_F, 'motivos' => [1]]);
    assert_true($svc->puedeFirmar($svc->find($id), 'responsable', $s['jefe']), 'su jefe inmediato puede firmar en su sesión');
    assert_true(!$svc->puedeFirmar($svc->find($id), 'responsable', usuario('X1')), 'cualquiera no');
    $svc->firmar($id, 'responsable', $s['jefe'], ['png' => firma_png(), 'password' => CLAVE_F]);
    assert_eq($svc->firmas($id)['responsable']['modo'], 'sesion', 'firmó en su sesión');
}

function test_candado_de_cierre_y_folio_remedy(): void
{
    $s = escenario_formato();
    $svc = new FormatoService(test_pdo());
    $id = $svc->emitir((int) $s['ticket'], $s['ag']);
    $tickets = new TicketService(test_pdo());
    $cerrado = (int) scalar('SELECT id FROM estados WHERE es_cierre = 1 ORDER BY id LIMIT 1');
    $cerrar = fn () => $tickets->manage($tickets->find((int) $s['ticket']), $s['ag'], [
        'estado_id' => (string) $cerrado, 'prioridad' => 'media', 'departamento_id' => '1', 'agente_asignado_id' => (string) $s['ag']['id'],
    ]);
    assert_throws(ValidationException::class, $cerrar, 'no se cierra con el formato pendiente', 'formato');

    // Autógrafa: se firmó en papel y TI sube el escaneo.
    $svc->autografa($id, $s['ag'], ['archivo' => 'formatos/escaneo.pdf', 'password' => CLAVE_F, 'ficha' => '330981', 'metodo' => 'Instalación limpia', 'motivos' => [2]]);
    $f = $svc->find($id);
    assert_eq([$f['estado'], $f['archivo_autografo']], ['completo', 'formatos/escaneo.pdf'], 'completo con el escaneo');
    assert_eq(array_column($svc->firmas($id), 'modo'), ['autografa', 'autografa', 'autografa'], 'las tres firmas en papel');

    (new \App\Services\Mail\CorreoAjustes(test_pdo()))->guardar('remedy@pemex.com', '', (int) $s['ag']['id']);
    assert_throws(ValidationException::class, $cerrar, 'sin folio Remedy no se cierra (la instalación envía a Remedy)', 'Remedy');
    $t = $tickets->find((int) $s['ticket']);
    $tickets->manage($t, $s['ag'], ['estado_id' => (string) $t['estado_id'], 'prioridad' => 'media', 'departamento_id' => '1', 'agente_asignado_id' => (string) $s['ag']['id'], 'folio_remedy' => ' WO0000014931785 ']);
    assert_eq(scalar('SELECT folio_remedy FROM tickets WHERE id = ?', [$s['ticket']]), 'WO0000014931785', 'TI captura el folio cuando llega');
    $cerrar();
    assert_eq((int) scalar('SELECT estado_id FROM tickets WHERE id = ?', [$s['ticket']]), $cerrado, 'ya se puede cerrar');
}

function test_solicitud_de_formateo_genera_el_formato(): void
{
    $s = escenario_formato();
    $tickets = new TicketService(test_pdo());
    assert_throws(ValidationException::class, fn () => $tickets->create($s['w'], ticket_form([
        'tipo_ticket' => 'formateo', 'reportada_ubicacion_fisica_id' => (string) $s['holkanaab'], 'reportada_nivel_id' => (string) nivel_id('PB'), 'reportada_ala' => 'norte',
    ])), 'la solicitud de formateo exige elegir el equipo', 'equipo');
    $r = $tickets->create($s['w'], ticket_form([
        'tipo_ticket' => 'formateo', 'equipo_id' => (string) $s['eq'], 'componente_afectado' => 'equipo',
        'reportada_ubicacion_fisica_id' => (string) $s['holkanaab'], 'reportada_nivel_id' => (string) nivel_id('PB'), 'reportada_ala' => 'norte',
    ]));
    assert_eq(scalar("SELECT estado FROM formatos WHERE ticket_id = ?", [$r['id']]), 'pendiente_usuario', 'el formato nace con el ticket');
}

function test_cancelar_libera_el_ticket(): void
{
    $s = escenario_formato();
    $svc = new FormatoService(test_pdo());
    $id = $svc->emitir((int) $s['ticket'], $s['ag']);
    assert_throws(ValidationException::class, fn () => $svc->cancelar($id, $s['w'], 'ya no'), 'un trabajador no cancela');
    $svc->cancelar($id, $s['ag'], 'Se resolvió sin formatear');
    assert_eq($svc->find($id)['estado'], 'cancelado', 'cancelado');
    assert_eq($svc->bloqueoCierre((int) $s['ticket']), null, 'ya no bloquea el cierre');
    $svc->emitir((int) $s['ticket'], $s['ag']);
    assert_true($svc->bloqueoCierre((int) $s['ticket']) !== null, 'se puede emitir otro');
}

function test_avisa_por_correo_a_quien_le_toca_firmar(): void
{
    $GLOBALS['__app_config']['mail']['driver'] = 'smtp';
    \App\Services\Mail\Notifier::reiniciarCola();
    try {
        $s = escenario_formato();
        $svc = new FormatoService(test_pdo());
        $id = $svc->emitir((int) $s['ticket'], $s['ag']);
        \App\Services\Mail\Notifier::formatoTurno($id);
        assert_eq(array_column(\App\Services\Mail\Notifier::cola(), 'to'), ['alexis@pemex.com'], 'primero al usuario');
        \App\Services\Mail\Notifier::reiniciarCola();
        $svc->firmar($id, 'usuario', $s['w'], ['png' => firma_png(), 'password' => CLAVE_F, 'motivos' => [1]]);
        \App\Services\Mail\Notifier::formatoTurno($id);
        $cola = \App\Services\Mail\Notifier::cola();
        assert_eq(array_column($cola, 'to'), ['jefe@pemex.com'], 'luego a su jefe inmediato');
        assert_true(str_contains($cola[0]['text'], 'ALEXIS JIMENEZ HERNANDEZ') && str_contains($cola[0]['text'], '5CD4221L1H'), 'dice de quién y qué equipo');
        \App\Services\Mail\Notifier::reiniciarCola();
        $svc->firmar($id, 'responsable', $s['jefe'], ['png' => firma_png(), 'password' => CLAVE_F]);
        \App\Services\Mail\Notifier::formatoTurno($id);
        assert_eq(array_column(\App\Services\Mail\Notifier::cola(), 'to'), ['jacqueline@pemex.com'], 'al final a TI (agente de la instalación)');
    } finally {
        \App\Services\Mail\Notifier::reiniciarCola();
    }
}

function test_el_folio_remedy_que_llega_despues_aparece_aunque_ya_este_firmado(): void
{
    $s = escenario_formato();
    $svc = new FormatoService(test_pdo());
    $id = $svc->emitir((int) $s['ticket'], $s['ag']);
    $svc->autografa($id, $s['ag'], ['archivo' => 'escaneos/x.pdf', 'password' => CLAVE_F, 'ficha' => '330981', 'metodo' => 'Instalación limpia', 'motivos' => [1]]);
    assert_eq($svc->datos($svc->find($id))['remedy'], '', 'aún sin folio');
    test_pdo()->prepare("UPDATE tickets SET folio_remedy = 'WO0000014931785' WHERE id = ?")->execute([$s['ticket']]);
    assert_eq($svc->datos($svc->find($id))['remedy'], 'WO0000014931785', 'el folio de Remedy se toma del ticket aun después de firmado');
}

function textos_de(array $p, array $cambios = []): array
{
    $c = $p['contenido'];
    $lineas = static fn (array $l): string => implode("\n", $l);
    return $cambios + [
        'titulo' => $p['titulo'], 'encabezado' => $lineas($c['encabezado']), 'lugar_omision' => $c['lugar_omision'],
        'organismo_omision' => $c['organismo_omision'], 'seccion_usuario' => $c['seccion_usuario'], 'nota_resguardo' => $c['nota_resguardo'],
        'intro' => $c['intro'], 'parrafos' => $lineas($c['parrafos']), 'subtitulo' => $c['subtitulo'], 'parrafos2' => $lineas($c['parrafos2']),
        'manifiesto' => $c['manifiesto'], 'titulo_motivos' => $c['titulo_motivos'], 'motivos' => $lineas($c['motivos']),
        'notas' => $lineas($c['notas']), 'metodos' => $lineas($c['metodos']),
        'firma_usuario' => $c['firmas']['usuario'], 'firma_responsable' => $c['firmas']['responsable'], 'firma_ti' => $c['firmas']['ti'],
    ];
}

function test_editar_la_plantilla_no_cambia_lo_ya_firmado(): void
{
    $s = escenario_formato();
    $svc = new FormatoService(test_pdo());
    $admin = usuario('ADM', 'admin_ti');
    $p = $svc->plantillaVigente('formateo');

    // Sin documentos: se corrige en la misma versión.
    $mismo = $svc->guardarPlantilla((int) $p['id'], textos_de($p, ['notas' => "*Nota uno\n**Nota dos\n***Texto completo de la nota tres."]), [], $admin);
    assert_eq($mismo, (int) $p['id'], 'sin formatos emitidos se edita en su lugar');

    $id = $svc->emitir((int) $s['ticket'], $s['ag']);
    $nueva = $svc->guardarPlantilla((int) $p['id'], textos_de($svc->plantilla((int) $p['id']), ['titulo' => 'Formato de autorización de formateo de equipo (rev. 2)']), [], $admin);
    assert_true($nueva !== (int) $p['id'], 'con documentos emitidos se crea otra versión');
    assert_eq((int) $svc->plantilla($nueva)['version'], 2, 'versión 2 del mismo año');
    assert_eq($svc->find($id)['titulo'], 'Formato de autorización de formateo de equipo', 'el formato ya emitido conserva su versión');
    assert_eq((int) $svc->plantillaVigente('formateo')['id'], $nueva, 'los nuevos usan la versión más reciente');
    assert_eq($svc->plantilla($nueva)['contenido']['notas'][2], '***Texto completo de la nota tres.', 'conserva la corrección anterior');

    assert_throws(ValidationException::class, fn () => $svc->guardarPlantilla($nueva, textos_de($svc->plantilla($nueva), ['motivos' => '']), [], $admin), 'sin motivos no', 'motivo');
    assert_throws(ValidationException::class, fn () => $svc->guardarPlantilla($nueva, textos_de($svc->plantilla($nueva)), [], $s['ag']), 'solo el Admin TI', 'Admin');
}

function test_version_del_anio_siguiente(): void
{
    $svc = new FormatoService(test_pdo());
    $admin = usuario('ADM', 'admin_ti');
    $p = $svc->plantillaVigente('formateo');
    test_pdo()->prepare("UPDATE formato_plantillas SET logo_izq = 'formatos/logos/a.png' WHERE id = ?")->execute([$p['id']]);
    $siguiente = (int) date('Y') + 1;
    $id = $svc->nuevoAnio('formateo', $siguiente, $admin);
    $n = $svc->plantilla($id);
    assert_eq([(int) $n['anio'], (int) $n['version'], $n['logo_izq']], [$siguiente, 1, 'formatos/logos/a.png'], 'copia textos y logos para cambiarlos');
    assert_eq((int) $svc->plantillaVigente('formateo')['id'], (int) $p['id'], 'no se usa hasta que llegue ese año');
    assert_throws(ValidationException::class, fn () => $svc->nuevoAnio('formateo', $siguiente, $admin), 'no se duplica el año', 'Ya existe');
    assert_eq(count($svc->listado()), 2, 'el listado muestra ambas versiones');
}

function test_logos_solo_imagenes_validas(): void
{
    $svc = new FormatoService(test_pdo());
    $png = tempnam(sys_get_temp_dir(), 'logo');
    $im = imagecreatetruecolor(400, 80);
    imagefill($im, 0, 0, imagecolorallocate($im, 255, 255, 255));
    imagestring($im, 5, 10, 30, 'LOGO', imagecolorallocate($im, 120, 20, 40));
    imagepng($im, $png);
    $ruta = $svc->guardarLogo(['name' => 'logo.png', 'tmp_name' => $png, 'error' => UPLOAD_ERR_OK, 'size' => filesize($png)], false);
    assert_true(str_starts_with($ruta, 'formatos/logos/') && is_file(STORAGE_PATH . '/' . $ruta), 'se guarda en storage/formatos/logos');
    @unlink(STORAGE_PATH . '/' . $ruta);

    $txt = tempnam(sys_get_temp_dir(), 'logo');
    file_put_contents($txt, '<?php echo 1;');
    assert_throws(ValidationException::class, fn () => $svc->guardarLogo(['name' => 'logo.png', 'tmp_name' => $txt, 'error' => UPLOAD_ERR_OK, 'size' => 13], false), 'no es imagen', 'imagen');
}

function test_ciudad_de_la_instalacion_para_lugar_y_fecha(): void
{
    $svc = new \App\Services\UbicacionFisicaService(test_pdo());
    $id = $svc->guardarInstalacion(null, 'Zona Industrial Km 4.5', true, '  Ciudad del Carmen,  Campeche ');
    assert_eq(scalar('SELECT ciudad FROM instalaciones WHERE id = ?', [$id]), 'Ciudad del Carmen, Campeche', 'se guarda limpia');
    $svc->guardarInstalacion($id, 'Zona Industrial Km 4.5', true);
    assert_eq(scalar('SELECT ciudad FROM instalaciones WHERE id = ?', [$id]), 'Ciudad del Carmen, Campeche', 'si no se manda, no se borra');
    $svc->guardarInstalacion($id, 'Zona Industrial Km 4.5', true, '');
    assert_eq(scalar('SELECT ciudad FROM instalaciones WHERE id = ?', [$id]), null, 'vacía = sin ciudad (usa la del formato)');
}

function test_texto_del_formato_vigente_y_etiquetas_editables(): void
{
    $svc = new FormatoService(test_pdo());
    $p = $svc->plantillaVigente('formateo');
    $c = $p['contenido'];
    assert_eq($c['etiquetas']['organismo'], 'Dirección/Organización', 'redacción del formato vigente');
    assert_eq($c['etiquetas']['inventario'], 'Inventario PEMEX o Compañía propietaria', 'columna de inventario');
    assert_eq($c['etiquetas']['motivo'], 'Descripción del Motivo del Borrado', 'tabla de motivos');
    assert_eq($c['titulo_motivos'], 'Motivo de Formateo Mencionado:', 'título de motivos');
    assert_eq($c['notas'][2], '***Cuando el equipo se retira de la empresa por baja en garantía, baja por término de contrato o desincorporación de equipo PEMEX se aplica borrado seguro.', 'nota *** completa');
    assert_eq([$c['leyenda_firma'], $c['formato_lugar']], ['', '{lugar}, a {fecha}'], 'sin "Nombre, ficha y firma" y fecha con coma');
    assert_eq(FormatoService::lugarYFecha($c, 'Ciudad del Carmen, Campeche', '2026-07-06 10:00:00'), 'Ciudad del Carmen, Campeche, a 06 de julio de 2026', 'como en el formato oficial');

    $in = textos_de($p, ['etiqueta_organismo' => 'Dirección / Organismo', 'leyenda_firma' => 'Nombre, ficha y firma']);
    $svc->guardarPlantilla((int) $p['id'], $in, [], usuario('ADM', 'admin_ti'));
    $c = $svc->plantilla((int) $p['id'])['contenido'];
    assert_eq([$c['etiquetas']['organismo'], $c['etiquetas']['ficha'], $c['leyenda_firma']], ['Dirección / Organismo', 'Ficha', 'Nombre, ficha y firma'], 'el admin cambia etiquetas; las demás se conservan');
}

function test_versiones_anteriores_sin_etiquetas_se_ven_como_se_firmaron(): void
{
    $viejo = FormatoService::etiquetas([]);
    assert_eq([$viejo['organismo'], $viejo['inventario']], ['Dirección /Organismo:', 'Inventario PEMEX (Cuando Aplique)'], 'redacción anterior');
    assert_eq(FormatoService::lugarYFecha([], 'Ciudad del Carmen, Campeche', '2026-04-23'), 'Ciudad del Carmen, Campeche a 23 de abril de 2026', 'fecha como antes');
    assert_eq(FormatoService::leyendaFirma([]), 'Nombre, ficha y firma', 'conserva su renglón');
}

function test_version_anterior_sin_documentos_se_actualiza_sola_al_texto_vigente(): void
{
    $viejo = FormatoService::DEFAULT['formateo']['contenido'];
    unset($viejo['etiquetas'], $viejo['leyenda_firma'], $viejo['formato_lugar']);
    $viejo['notas'][2] = '***Cuando el equipo se retira de la empresa por baja en garantía, baja por término de contrato o desincorporación de';
    test_pdo()->prepare("INSERT INTO formato_plantillas (clave, anio, version, titulo, contenido, logo_izq) VALUES ('formateo', ?, 1, 'Formato de autorización de formateo de equipo', ?, 'formatos/logos/x.png')")
        ->execute([(int) date('Y'), json_encode($viejo, JSON_UNESCAPED_UNICODE)]);
    $p = (new FormatoService(test_pdo()))->plantillaVigente('formateo');
    assert_eq($p['contenido']['etiquetas']['organismo'] ?? null, 'Dirección/Organización', 'sin documentos: toma el texto vigente');
    assert_true(str_ends_with($p['contenido']['notas'][2], 'se aplica borrado seguro.'), 'con la nota completa');
    assert_eq($p['logo_izq'], 'formatos/logos/x.png', 'conserva los logos ya subidos');
}

function test_version_anterior_con_documentos_no_se_toca(): void
{
    $s = escenario_formato();
    $svc = new FormatoService(test_pdo());
    $svc->emitir((int) $s['ticket'], $s['ag']);
    $p = $svc->plantillaVigente('formateo');
    $viejo = $p['contenido'];
    unset($viejo['etiquetas']);
    test_pdo()->prepare('UPDATE formato_plantillas SET contenido = ? WHERE id = ?')->execute([json_encode($viejo, JSON_UNESCAPED_UNICODE), $p['id']]);
    assert_true(!isset($svc->plantillaVigente('formateo')['contenido']['etiquetas']), 'lo firmado conserva su texto');
}
