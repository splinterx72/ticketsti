<?php

declare(strict_types=1);

use App\Services\Alcance;

function test_prestamo_hasta_hoy_incluido_y_ayer_excluido(): void
{
    $a = instalacion('Zona Industrial Km 4.5');
    $b = instalacion('Zona Industrial Laguna Azul');
    $c = instalacion('Terminal Marítima');
    $agente = usuario('AG1', 'agente_ti');
    $hoy = date('Y-m-d');
    asignar_agente($agente['id'], $a);
    asignar_agente($agente['id'], $b, 'prestamo', date('Y-m-d', strtotime('-5 days')), $hoy);
    asignar_agente($agente['id'], $c, 'prestamo', date('Y-m-d', strtotime('-5 days')), date('Y-m-d', strtotime('-1 day')));

    $alcance = new Alcance(test_pdo());
    $ids = $alcance->instalacionesDe($agente['id']);
    sort($ids);
    assert_eq($ids, [$a, $b], 'préstamo que vence hoy sigue vigente; el de ayer ya no');
    assert_eq($alcance->instalacionesDe($agente['id'], date('Y-m-d', strtotime('+1 day'))), [$a], 'mañana regresa solo a su base');
    assert_eq($alcance->instalacionesBase($agente['id']), [$a], 'instalación base');
}

function test_supervisor_ve_sus_instalaciones_y_admin_todas(): void
{
    $a = instalacion('A');
    $b = instalacion('B');
    $c = instalacion('C');
    $sup = usuario('SUP1', 'supervisor');
    $admin = usuario('ADM1', 'admin_ti');
    asignar_supervisor($sup['id'], $a);
    asignar_supervisor($sup['id'], $b, 'suplencia', date('Y-m-d', strtotime('-1 day')), date('Y-m-d', strtotime('+3 days')));
    asignar_supervisor($sup['id'], $c, 'suplencia', null, date('Y-m-d', strtotime('-2 days')));

    $alcance = new Alcance(test_pdo());
    $ids = $alcance->instalacionesDe($sup['id']);
    sort($ids);
    assert_eq($ids, [$a, $b], 'supervisor: titular + suplencia vigente');
    $todas = $alcance->instalacionesDe($admin['id']);
    sort($todas);
    assert_eq($todas, [$a, $b, $c], 'admin ve todas');
    assert_true($alcance->veTodo($admin), 'admin veTodo');
    assert_true(!$alcance->veTodo($sup), 'supervisor no veTodo');

    $ag = usuario('AG2', 'agente_ti');
    asignar_agente($ag['id'], $a);
    $prestado = usuario('AG3', 'agente_ti');
    asignar_agente($prestado['id'], $c);
    asignar_agente($prestado['id'], $b, 'prestamo', null, date('Y-m-d', strtotime('+1 day')));
    $agentes = $alcance->agentesDe($sup['id']);
    $porId = array_column($agentes, 'prestado', 'id');
    assert_eq(count($agentes), 2, 'supervisor ve a su agente y al prestado');
    assert_eq($porId[$ag['id']] ?? null, false, 'agente base no está prestado');
    assert_eq($porId[$prestado['id']] ?? null, true, 'agente prestado marcado');
    assert_eq(array_column($alcance->supervisoresDe($a), 'id'), [$sup['id']], 'supervisores de la instalación A');
}

function test_ticket_sin_instalacion_visible_a_todo_ti_no_a_usuario(): void
{
    $a = instalacion('A');
    $solicitante = usuario('U1');
    $otro = usuario('U2');
    $agente = usuario('AG1', 'agente_ti');
    asignar_agente($agente['id'], $a);
    $t = ticket_de($solicitante, null);

    $alcance = new Alcance(test_pdo());
    assert_true($alcance->puedeVerTicket($agente, $t), 'agente ve ticket sin instalación');
    assert_true($alcance->puedeVerTicket($solicitante, $t), 'el solicitante ve su ticket');
    assert_true(!$alcance->puedeVerTicket($otro, $t), 'otro usuario no lo ve');
}

function test_ticket_asignado_visible_aunque_otra_instalacion(): void
{
    $a = instalacion('A');
    $b = instalacion('B');
    $solicitante = usuario('U1');
    $agente = usuario('AG1', 'agente_ti');
    asignar_agente($agente['id'], $a);
    $ajeno = ticket_de($solicitante, $b);
    $asignado = ticket_de($solicitante, $b, $agente['id']);

    $alcance = new Alcance(test_pdo());
    assert_true(!$alcance->puedeVerTicket($agente, $ajeno), 'no ve tickets de otra instalación');
    assert_true($alcance->puedeVerTicket($agente, $asignado), 'sí ve el que tiene asignado');
}

function test_filtro_sql_coincide_con_puedeVerTicket(): void
{
    $a = instalacion('A');
    $b = instalacion('B');
    $solicitante = usuario('U1');
    $agente = usuario('AG1', 'agente_ti');
    $sup = usuario('SUP1', 'supervisor');
    $admin = usuario('ADM1', 'admin_ti');
    asignar_agente($agente['id'], $a);
    asignar_supervisor($sup['id'], $b);
    $tickets = [
        ticket_de($solicitante, $a), ticket_de($solicitante, $b), ticket_de($solicitante, null),
        ticket_de($solicitante, $b, $agente['id']), ticket_de($solicitante, $a, $sup['id']), ticket_de($solicitante, $b),
    ];

    $alcance = new Alcance(test_pdo());
    foreach ([$agente, $sup, $admin] as $u) {
        [$where, $params] = $alcance->filtroTicketsSql($u, 't');
        $stmt = test_pdo()->prepare("SELECT t.id FROM tickets t WHERE {$where} ORDER BY t.id");
        $stmt->execute($params);
        $porSql = array_map('intval', $stmt->fetchAll(PDO::FETCH_COLUMN));
        $porPhp = array_values(array_map(
            static fn (array $t): int => $t['id'],
            array_filter($tickets, static fn (array $t): bool => $alcance->puedeVerTicket($u, $t))
        ));
        assert_eq($porSql, $porPhp, "filtro SQL = puedeVerTicket para {$u['rol']}");
    }
}
