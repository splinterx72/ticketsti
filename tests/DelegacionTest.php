<?php

declare(strict_types=1);

use App\Core\ValidationException;
use App\Services\DelegacionService;

function test_el_jefe_autoriza_a_varias_personas_y_revoca(): void
{
    $jefe = usuario('J1');
    $sec = usuario('S1');
    $aux = usuario('S2');
    $svc = new DelegacionService(test_pdo());
    $svc->autorizar($jefe, (int) $jefe['id'], 'S1', true, null);
    $svc->autorizar($jefe, (int) $jefe['id'], 's2 ', false, date('Y-m-d', strtotime('+10 days')));
    assert_eq(array_column($svc->deJefe((int) $jefe['id']), 'delegado_ficha'), ['S1', 'S2'], 'dos personas autorizadas');
    assert_eq(array_column($svc->jefesDe((int) $sec['id']), 'id'), [$jefe['id']], 'la secretaria ve a su jefe');
    assert_true($svc->puedeLevantarPor((int) $aux['id'], (int) $jefe['id']), 'la auxiliar también');

    $svc->autorizar($jefe, (int) $jefe['id'], 'S1', false, null);
    assert_eq(scalar('SELECT COUNT(*) FROM delegaciones WHERE jefe_id = ?', [$jefe['id']]), 2, 'reautorizar actualiza, no duplica');

    $id = (int) scalar('SELECT id FROM delegaciones WHERE delegado_id = ?', [$aux['id']]);
    $svc->revocar($jefe, $id);
    assert_true(!$svc->puedeLevantarPor((int) $aux['id'], (int) $jefe['id']), 'revocada');
}

function test_reglas_de_autorizacion(): void
{
    $jefe = usuario('J1');
    $svc = new DelegacionService(test_pdo());
    assert_throws(ValidationException::class, fn () => $svc->autorizar($jefe, (int) $jefe['id'], 'J1', true, null), 'a sí mismo no', 'ti mismo');
    assert_throws(ValidationException::class, fn () => $svc->autorizar($jefe, (int) $jefe['id'], 'NOEXISTE', true, null), 'ficha inexistente');
    $pend = usuario('P1');
    test_pdo()->prepare('UPDATE usuarios SET registrado = 0 WHERE id = ?')->execute([$pend['id']]);
    assert_throws(ValidationException::class, fn () => $svc->autorizar($jefe, (int) $jefe['id'], 'P1', true, null), 'la persona debe tener su cuenta activada', 'activ');
    assert_throws(ValidationException::class, fn () => $svc->autorizar($jefe, (int) $jefe['id'], 'P1', true, '2000-01-01'), 'fecha pasada');

    $otro = usuario('X1');
    usuario('S1');
    assert_throws(ValidationException::class, fn () => $svc->autorizar($otro, (int) $jefe['id'], 'S1', true, null), 'nadie autoriza por otro jefe activo', 'jefe');
    $sup = usuario('SUP', 'supervisor');
    assert_throws(ValidationException::class, fn () => $svc->autorizar($sup, (int) $jefe['id'], 'S1', true, null), 'TI no autoriza si el jefe ya activó su cuenta', 'jefe');

    $jefeSinCuenta = usuario('J2');
    test_pdo()->prepare('UPDATE usuarios SET registrado = 0 WHERE id = ?')->execute([$jefeSinCuenta['id']]);
    $svc->autorizar($sup, (int) $jefeSinCuenta['id'], 'S1', true, null);
    assert_true($svc->puedeLevantarPor((int) scalar("SELECT id FROM usuarios WHERE ficha = 'S1'"), (int) $jefeSinCuenta['id']), 'TI la registra cuando el jefe no ha activado su cuenta');
    $svc->autorizar(usuario('ADM', 'admin_ti'), (int) $jefe['id'], 'S1', true, null);
    assert_true(true, 'el admin siempre puede');
}

function test_vencida_no_cuenta(): void
{
    $jefe = usuario('J1');
    $sec = usuario('S1');
    $svc = new DelegacionService(test_pdo());
    $svc->autorizar($jefe, (int) $jefe['id'], 'S1', true, date('Y-m-d'));
    assert_true($svc->puedeLevantarPor((int) $sec['id'], (int) $jefe['id']), 'vigente hasta hoy inclusive');
    test_pdo()->exec("UPDATE delegaciones SET hasta = CURDATE() - INTERVAL 1 DAY");
    assert_true(!$svc->puedeLevantarPor((int) $sec['id'], (int) $jefe['id']), 'vencida');
    assert_eq($svc->jefesDe((int) $sec['id']), [], 'ya no aparece en su lista');
}

function test_quien_ve_los_tickets_del_jefe(): void
{
    $jefe = usuario('J1');
    $todos = usuario('S1');
    $propios = usuario('S2');
    $svc = new DelegacionService(test_pdo());
    $svc->autorizar($jefe, (int) $jefe['id'], 'S1', true, null);
    $svc->autorizar($jefe, (int) $jefe['id'], 'S2', false, null);
    $deJefe = ticket_de($jefe, null);
    $deS2 = ticket_de($jefe, null);
    test_pdo()->prepare('UPDATE tickets SET creado_por_id = ? WHERE id = ?')->execute([$propios['id'], $deS2['id']]);
    $t = static fn (array $x): array => ['usuario_solicitante_id' => $jefe['id'], 'creado_por_id' => scalar('SELECT creado_por_id FROM tickets WHERE id = ?', [$x['id']])];

    assert_true($svc->puedeVerTicket($todos, $t($deJefe)), 'con "ver todos": todos los del jefe');
    assert_true(!$svc->puedeVerTicket($propios, $t($deJefe)), 'sin "ver todos": no los que levantó el jefe');
    assert_true($svc->puedeVerTicket($propios, $t($deS2)), 'pero sí los que ella levantó');
    assert_true(!$svc->puedeVerTicket(usuario('Z9'), $t($deJefe)), 'nadie más');
}

function test_quien_levanto_el_ticket_lo_sigue_viendo_aunque_le_quiten_el_permiso(): void
{
    $jefe = usuario('J1');
    $sec = usuario('S1');
    $t = ticket_de($jefe, null);
    test_pdo()->prepare('UPDATE tickets SET creado_por_id = ? WHERE id = ?')->execute([$sec['id'], $t['id']]);
    assert_true((new DelegacionService(test_pdo()))->puedeVerTicket($sec, ['usuario_solicitante_id' => $jefe['id'], 'creado_por_id' => $sec['id']]), 'sin delegación vigente, sigue viendo el que levantó');
}

function test_la_secretaria_levanta_el_ticket_a_nombre_del_jefe(): void
{
    $jefe = usuario('J1', 'usuario', 'jefe@pemex.com');
    $sec = usuario('S1', 'usuario', 'sec@pemex.com');
    $eqJefe = equipo_geca($jefe, 'CPU-JEFE');
    $eqSec = equipo_geca($sec, 'CPU-SEC');
    (new DelegacionService(test_pdo()))->autorizar($jefe, (int) $jefe['id'], 'S1', true, null);
    $svc = new \App\Services\TicketService(test_pdo());

    $r = $svc->create($sec, ticket_form(['para_usuario_id' => (string) $jefe['id'], 'equipo_id' => (string) $eqJefe, 'componente_afectado' => 'equipo']));
    $t = $svc->find($r['id']);
    assert_eq([(int) $t['usuario_solicitante_id'], (int) $t['creado_por_id'], (int) $t['equipo_id']], [$jefe['id'], $sec['id'], $eqJefe], 'a nombre del jefe, con su equipo, y queda quién lo levantó');
    assert_eq($t['creado_por_nombre'] ?? null, 'Persona S1', 'el detalle trae el nombre de quien lo levantó');

    assert_throws(ValidationException::class, fn () => $svc->create($sec, ticket_form(['para_usuario_id' => (string) $jefe['id'], 'equipo_id' => (string) $eqSec, 'componente_afectado' => 'equipo'])), 'con el equipo de la secretaria no', 'equipo');
    $otro = usuario('J2');
    assert_throws(ValidationException::class, fn () => $svc->create($sec, ticket_form(['para_usuario_id' => (string) $otro['id']])), 'sin autorización no', 'autoriz');

    $propio = $svc->create($sec, ticket_form(['para_usuario_id' => (string) $sec['id']]));
    assert_eq(scalar('SELECT creado_por_id FROM tickets WHERE id = ?', [$propio['id']]), null, 'para mí: ticket normal');
}

function test_avisos_y_acceso_del_ticket_delegado(): void
{
    $GLOBALS['__app_config']['mail']['driver'] = 'smtp';
    $GLOBALS['__app_config']['mail']['mesa_institucional'] = '';
    \App\Services\Mail\Notifier::reiniciarCola();
    try {
        $jefe = usuario('J1', 'usuario', 'jefe@pemex.com');
        $sec = usuario('S1', 'usuario', 'sec@pemex.com');
        test_pdo()->prepare("UPDATE usuarios SET telefono_ext = '4455' WHERE id = ?")->execute([$sec['id']]);
        (new DelegacionService(test_pdo()))->autorizar($jefe, (int) $jefe['id'], 'S1', true, null);
        $ag = usuario('AG', 'agente_ti', 'ag@pemex.com');
        $r = (new \App\Services\TicketService(test_pdo()))->create($sec, ticket_form(['para_usuario_id' => (string) $jefe['id']]));
        \App\Services\Mail\Notifier::ticketCreado($r['id']);
        $porDestino = array_column(\App\Services\Mail\Notifier::cola(), 'text', 'to');
        assert_true(isset($porDestino['jefe@pemex.com'], $porDestino['sec@pemex.com']), 'confirmación al jefe y copia a quien lo levantó');
        assert_true(str_contains((string) ($porDestino['ag@pemex.com'] ?? ''), 'Levantado por: Persona S1 (ext. 4455)'), 'TI ve quién lo levantó y cómo localizarla');
        assert_true(!str_contains((string) $porDestino['sec@pemex.com'], 'Extensión:'), 'la copia a la secretaria es breve');

        $t = (new \App\Services\TicketService(test_pdo()))->find($r['id']);
        assert_eq(\App\Controllers\TicketController::acceso($sec, $t), [true, false], 'la secretaria lo ve (como trabajadora)');
        assert_eq(\App\Controllers\TicketController::acceso(usuario('Z9'), $t)[0], false, 'otro trabajador no');

        \App\Services\Mail\Notifier::reiniciarCola();
        \App\Services\Mail\Notifier::comentario($r['id'], (int) $ag['id'], 'Voy en camino', false);
        $dest = array_column(\App\Services\Mail\Notifier::cola(), 'to');
        sort($dest);
        assert_eq($dest, ['jefe@pemex.com', 'sec@pemex.com'], 'la respuesta de TI llega al jefe y a quien lo levantó');
    } finally {
        \App\Services\Mail\Notifier::reiniciarCola();
        $GLOBALS['__app_config']['mail']['driver'] = 'off';
    }
}

function test_mis_tickets_incluye_los_de_mis_jefes(): void
{
    $jefe = usuario('J1');
    $sec = usuario('S1');
    (new DelegacionService(test_pdo()))->autorizar($jefe, (int) $jefe['id'], 'S1', true, null);
    ticket_de($jefe, null);
    ticket_de($sec, null);
    [$sql, $p] = DelegacionService::filtroSql((int) $sec['id']);
    assert_eq((int) scalar("SELECT COUNT(*) FROM tickets t WHERE {$sql}", $p), 1, 'el filtro trae el ticket del jefe');
}
