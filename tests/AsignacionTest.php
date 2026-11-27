<?php

declare(strict_types=1);

use App\Services\AsignadorService;

/** Agente listo para recibir tickets (activo, con admin local al día) en la instalación. */
function agente_listo(string $ficha, int $instalacionId, string $rol = 'agente_ti'): array
{
    $a = usuario($ficha, $rol);
    test_pdo()->prepare('UPDATE usuarios SET admin_local_cambiado_en = CURDATE() WHERE id = ?')->execute([$a['id']]);
    asignar_agente($a['id'], $instalacionId);
    return $a;
}

/** Ticket abierto asignado con prioridad dada (para simular carga). */
function carga(int $agenteId, int $instalacionId, string $prioridad, int $veces = 1): void
{
    $sol = usuario('S' . bin2hex(random_bytes(3)));
    for ($i = 0; $i < $veces; $i++) {
        $t = ticket_de($sol, $instalacionId, $agenteId);
        test_pdo()->prepare('UPDATE tickets SET prioridad = ? WHERE id = ?')->execute([$prioridad, $t['id']]);
    }
}

function ticket_nuevo(int $instalacionId, int $deptoId = 1, string $prioridad = 'media'): int
{
    $t = ticket_de(usuario('N' . bin2hex(random_bytes(3))), $instalacionId);
    test_pdo()->prepare('UPDATE tickets SET departamento_id = ?, prioridad = ? WHERE id = ?')->execute([$deptoId, $prioridad, $t['id']]);
    return (int) $t['id'];
}

function test_sugiere_al_de_menor_carga_ponderada_por_prioridad(): void
{
    $inst = instalacion('Km 4.5');
    $a = agente_listo('A1', $inst);
    $b = agente_listo('B1', $inst);
    carga($a['id'], $inst, 'critica');      // 4 puntos
    carga($b['id'], $inst, 'baja', 3);      // 3 puntos
    $s = (new AsignadorService(test_pdo()))->sugerir(ticket_nuevo($inst));
    assert_eq($s['id'] ?? null, $b['id'], 'tres bajas pesan menos que una crítica');
    assert_true(str_contains((string) $s['motivo'], 'carga'), 'la sugerencia explica el motivo');
}

function test_solo_agentes_vigentes_disponibles_y_activos_de_la_instalacion(): void
{
    $inst = instalacion('Km 4.5');
    $otra = instalacion('Laguna');
    $svc = new AsignadorService(test_pdo());

    agente_listo('FUERA', $otra);
    $vac = agente_listo('VAC', $inst);
    $svc->guardarEstado($vac['id'], 'vacaciones', date('Y-m-d', strtotime('+5 days')), $vac['id']);
    $inactivo = agente_listo('INA', $inst);
    test_pdo()->prepare('UPDATE usuarios SET activo = 0 WHERE id = ?')->execute([$inactivo['id']]);
    $bloq = agente_listo('BLQ', $inst);
    test_pdo()->prepare('UPDATE usuarios SET admin_local_cambiado_en = NULL WHERE id = ?')->execute([$bloq['id']]);
    $prest = usuario('PRE', 'agente_ti');
    test_pdo()->prepare('UPDATE usuarios SET admin_local_cambiado_en = CURDATE() WHERE id = ?')->execute([$prest['id']]);
    asignar_agente($prest['id'], $inst, 'prestamo', date('Y-m-d', strtotime('-10 days')), date('Y-m-d', strtotime('-1 day')));

    assert_eq($svc->sugerir(ticket_nuevo($inst)), null, 'nadie elegible: sin sugerencia');

    test_pdo()->prepare('UPDATE agente_estado SET hasta = ? WHERE usuario_id = ?')->execute([date('Y-m-d', strtotime('-1 day')), $vac['id']]);
    assert_eq($svc->sugerir(ticket_nuevo($inst))['id'] ?? null, $vac['id'], 'al pasar la fecha "hasta" vuelve a estar disponible');
    assert_eq($svc->sugerir(ticket_de(usuario('X9'), null)['id']), null, 'ticket sin instalación: sin sugerencia');
}

function test_estado_valida_la_fecha(): void
{
    $a = agente_listo('A1', instalacion('Km 4.5'));
    $svc = new AsignadorService(test_pdo());
    assert_throws(\App\Core\ValidationException::class, fn () => $svc->guardarEstado($a['id'], 'vacaciones', date('Y-m-d', strtotime('-1 day')), $a['id']), 'fecha pasada no');
    assert_throws(\App\Core\ValidationException::class, fn () => $svc->guardarEstado($a['id'], 'dormido', null, $a['id']), 'estado inválido no');
    $svc->guardarEstado($a['id'], 'fuera', date('Y-m-d'), $a['id']);
    assert_eq($svc->estadoDe($a['id'])['estado'], 'fuera', 'hasta hoy inclusive sigue fuera');
}

function test_especialidades_filtran_por_departamento(): void
{
    $inst = instalacion('Km 4.5');
    $svc = new AsignadorService(test_pdo());
    $gar = agente_listo('GAR', $inst);
    $svc->guardarEspecialidades($gar['id'], [3]);            // Garantías
    $general = agente_listo('GEN', $inst);                    // sin especialidades: atiende todo
    carga($general['id'], $inst, 'alta');

    assert_eq($svc->sugerir(ticket_nuevo($inst, 3))['id'] ?? null, $gar['id'], 'garantía: el especialista (menos carga)');
    assert_eq($svc->sugerir(ticket_nuevo($inst, 1))['id'] ?? null, $general['id'], 'soporte en sitio: el especialista en garantías no entra');
}

function test_ocupado_penaliza_y_mismo_edificio_ayuda_y_empate_reparte(): void
{
    $e = ejemplos_usuario();
    $svc = new AsignadorService(test_pdo());
    $a = agente_listo('A1', $e['km']);
    $b = agente_listo('B1', $e['km']);

    // Empate: reparte al que lleva más tiempo sin recibir (b nunca ha recibido).
    carga($a['id'], $e['km'], 'media');
    test_pdo()->exec('UPDATE tickets SET cerrado_en = NOW(), estado_id = (SELECT id FROM estados WHERE es_cierre = 1 LIMIT 1)');
    assert_eq($svc->sugerir(ticket_nuevo($e['km']))['id'] ?? null, $b['id'], 'empate: al que lleva más tiempo sin recibir');

    // Mismo edificio: b tiene un abierto en Holkanaab y el nuevo es ahí; a tiene igual carga.
    carga($a['id'], $e['km'], 'media');
    $tb = ticket_de(usuario('SB'), $e['km'], $b['id']);
    test_pdo()->prepare('UPDATE tickets SET reportada_ubicacion_fisica_id = ? WHERE id = ?')->execute([$e['holkanaab'], $tb['id']]);
    $nuevo = ticket_nuevo($e['km']);
    test_pdo()->prepare('UPDATE tickets SET reportada_ubicacion_fisica_id = ? WHERE id = ?')->execute([$e['holkanaab'], $nuevo]);
    $s = $svc->sugerir($nuevo);
    assert_eq($s['id'] ?? null, $b['id'], 'ya va a ese edificio');
    assert_true(str_contains((string) $s['motivo'], 'edificio'), 'el motivo lo dice');

    $svc->guardarEstado($b['id'], 'ocupado', null, $b['id']);
    assert_eq($svc->sugerir($nuevo)['id'] ?? null, $a['id'], 'ocupado pesa más que el bono de edificio');
}

function test_al_crear_segun_modo_de_la_instalacion(): void
{
    $inst = instalacion('Km 4.5');
    $a = agente_listo('A1', $inst);
    $svc = new AsignadorService(test_pdo());

    $t1 = ticket_nuevo($inst);
    assert_eq($svc->alCrear($t1), null, 'sugerida (por omisión): no asigna');
    assert_eq(scalar('SELECT sugerido_agente_id FROM tickets WHERE id = ?', [$t1]), $a['id'], 'guarda la sugerencia');
    assert_eq(scalar('SELECT agente_asignado_id FROM tickets WHERE id = ?', [$t1]), null, 'sigue sin asignar');

    $svc->guardarModo($inst, 'automatica');
    $t2 = ticket_nuevo($inst);
    assert_eq($svc->alCrear($t2), $a['id'], 'automática: asigna');
    assert_eq(scalar('SELECT agente_asignado_id FROM tickets WHERE id = ?', [$t2]), $a['id'], 'queda asignado');
    assert_eq(scalar("SELECT COUNT(*) FROM comentarios WHERE ticket_id = ? AND mensaje LIKE '[Sistema] Asignado automáticamente%'", [$t2]), 1, 'queda en la bitácora con el motivo');

    $svc->guardarModo($inst, 'manual');
    $t3 = ticket_nuevo($inst);
    assert_eq($svc->alCrear($t3), null, 'manual: nada');
    assert_eq(scalar('SELECT sugerido_agente_id FROM tickets WHERE id = ?', [$t3]), null, 'manual: sin sugerencia');
}

function test_crear_ticket_aplica_la_asignacion(): void
{
    $e = ejemplos_usuario();
    $a = agente_listo('A1', $e['laguna']);
    $jose = usuario('12345', 'usuario', 'jose@pemex.com');
    $r = (new \App\Services\TicketService(test_pdo()))->create($jose, ticket_form([
        'reportada_ubicacion_fisica_id' => (string) $e['carga'], 'reportada_nivel_id' => (string) nivel_id('Único'),
        'reportada_ala' => 'na', 'reportada_referencia' => 'x',
    ]));
    assert_eq(scalar('SELECT sugerido_agente_id FROM tickets WHERE id = ?', [$r['id']]), $a['id'], 'el ticket nuevo trae la sugerencia');
    assert_true(array_key_exists('asignado_a', $r) && $r['asignado_a'] === null, 'en modo sugerida no se asigna');
}

function test_quien_cambia_la_disponibilidad_de_un_agente(): void
{
    $km = instalacion('Km 4.5');
    $laguna = instalacion('Laguna');
    $a = agente_listo('A1', $km);
    $sup = usuario('SUP', 'supervisor');
    asignar_supervisor($sup['id'], $km);
    $supOtra = usuario('SUP2', 'supervisor');
    asignar_supervisor($supOtra['id'], $laguna);
    $svc = new AsignadorService(test_pdo());
    assert_true($svc->puedeGestionar($a, $a['id']), 'el propio agente');
    assert_true($svc->puedeGestionar($sup, $a['id']), 'el supervisor de su instalación');
    assert_true(!$svc->puedeGestionar($supOtra, $a['id']), 'el supervisor de otra instalación no');
    assert_true(!$svc->puedeGestionar(agente_listo('A2', $km), $a['id']), 'otro agente no');
    assert_true($svc->puedeGestionar(usuario('ADM', 'admin_ti'), $a['id']), 'el admin sí');
}
