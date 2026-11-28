<?php

declare(strict_types=1);

use App\Core\ValidationException;
use App\Services\CategoriaService;
use App\Services\TicketService;

function test_categorias_sembradas_y_obligatorias_al_crear(): void
{
    $pdo = test_pdo();
    $cats = (new CategoriaService($pdo))->todas(true);
    assert_eq(count($cats), 10, 'las 10 categorías de la lista');
    assert_eq($cats[0]['nombre'], 'Hardware / Equipo de Cómputo', 'en su orden');
    assert_true(str_contains((string) $cats[0]['descripcion'], 'monitores'), 'con su descripción');

    $u = usuario('11111');
    assert_throws(ValidationException::class, fn () => (new TicketService($pdo))->create($u, ticket_form(['categoria_id' => ''])), 'sin categoría no');
    $r = (new TicketService($pdo))->create($u, ticket_form(['categoria_id' => (string) $cats[4]['id']]));
    assert_eq((int) scalar('SELECT categoria_id FROM tickets WHERE id = ?', [$r['id']]), $cats[4]['id'], 'guarda la categoría');
    assert_eq((new TicketService($pdo))->find($r['id'])['categoria_nombre'], 'Redes y Conectividad', 'el detalle la muestra');

    (new CategoriaService($pdo))->guardar($cats[4]['id'], 'Redes y Conectividad', 'x', 5, false);
    assert_throws(ValidationException::class, fn () => (new TicketService($pdo))->create($u, ticket_form(['categoria_id' => (string) $cats[4]['id']])), 'inactiva no se ofrece');
    assert_throws(ValidationException::class, fn () => (new CategoriaService($pdo))->guardar(null, 'Impresión y Escaneo', '', 1, true), 'nombre repetido no');
}

function test_ti_corrige_la_categoria_y_queda_en_bitacora(): void
{
    $pdo = test_pdo();
    $cats = (new CategoriaService($pdo))->todas(true);
    $svc = new TicketService($pdo);
    $r = $svc->create(usuario('11111'), ticket_form());
    $t = $svc->find($r['id']);
    $ag = usuario('AG', 'agente_ti');
    $n = $svc->manage($t, $ag, [
        'estado_id' => (string) $t['estado_id'], 'prioridad' => $t['prioridad'], 'departamento_id' => (string) $t['departamento_id'],
        'agente_asignado_id' => '', 'categoria_id' => (string) $cats[2]['id'],
    ]);
    assert_eq($n, 1, 'un cambio');
    assert_eq((int) scalar('SELECT categoria_id FROM tickets WHERE id = ?', [$r['id']]), $cats[2]['id'], 'categoría corregida');
    assert_eq((int) scalar("SELECT COUNT(*) FROM comentarios WHERE ticket_id = ? AND mensaje LIKE '%categoría: Hardware%Software%'", [$r['id']]), 1, 'queda en la bitácora');
}

function test_tipos_de_ti_solo_para_ti_con_varios_equipos(): void
{
    $pdo = test_pdo();
    $svc = new TicketService($pdo);
    $dueno = usuario('22222');
    $e1 = equipo_geca($dueno, 'SER-A1');
    $e2 = equipo_geca($dueno, 'SER-A2');
    equipo_geca($dueno, 'SER-A3');

    assert_throws(ValidationException::class, fn () => $svc->create(usuario('11111'), ticket_form(['tipo_ticket' => 'levantamiento'])), 'un trabajador no usa tipos de TI');

    $ag = usuario('AG', 'agente_ti');
    $r = $svc->create($ag, ticket_form([
        'tipo_ticket' => 'levantamiento', 'equipos' => [(string) $e1, (string) $e2], 'equipos_series' => "ser-a2\nSER-A3, SER-A1",
    ]));
    assert_eq(count($svc->equiposDelTicket($r['id'])), 3, 'lista + series sin repetir');
    assert_eq(scalar('SELECT agente_asignado_id FROM tickets WHERE id = ?', [$r['id']]), $ag['id'], 'queda asignado a quien lo levanta');
    assert_eq(scalar('SELECT d.nombre FROM tickets t JOIN departamentos d ON d.id = t.departamento_id WHERE t.id = ?', [$r['id']]), 'Control de Resguardos GECA', 'levantamiento va a Resguardos');

    assert_throws(ValidationException::class, fn () => $svc->create($ag, ticket_form(['tipo_ticket' => 'bajas', 'equipos_series' => 'NO-EXISTE'])), 'serie desconocida');
    assert_true(count($svc->buscarEquipos('SER-A')) === 3, 'búsqueda por inicio de serie');
    assert_true(count($svc->buscarEquipos('22222')) === 3, 'búsqueda por ficha');
}
