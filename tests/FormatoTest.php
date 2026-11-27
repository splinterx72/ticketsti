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
