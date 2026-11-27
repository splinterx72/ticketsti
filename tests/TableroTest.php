<?php

declare(strict_types=1);

use App\Services\TableroService;

/** Ticket abierto reportado en un lugar físico. */
function ticket_en(array $sol, int $inst, int $ubic, ?int $nivel, ?int $agente = null): int
{
    $t = ticket_de($sol, $inst, $agente);
    test_pdo()->prepare('UPDATE tickets SET reportada_ubicacion_fisica_id = ?, reportada_nivel_id = ?, reportada_ala = ? WHERE id = ?')
        ->execute([$ubic, $nivel, $nivel !== null ? 'norte' : 'na', $t['id']]);
    return $t['id'];
}

function test_ruta_del_agente_por_instalacion_edificio_y_nivel(): void
{
    $e = ejemplos_usuario();
    $ag = usuario('AG', 'agente_ti');
    asignar_agente($ag['id'], $e['km']);
    asignar_agente($ag['id'], $e['laguna']);
    $sol = usuario('U1');
    ticket_en($sol, $e['km'], $e['holkanaab'], nivel_id('1'), $ag['id']);
    ticket_en($sol, $e['km'], $e['holkanaab'], nivel_id('1'));
    ticket_en($sol, $e['km'], $e['holkanaab'], nivel_id('2'));
    ticket_en($sol, $e['laguna'], $e['carga'], null);
    $otraInst = instalacion('Ajena');
    ticket_de($sol, $otraInst);

    $ruta = (new TableroService(test_pdo()))->rutaAgente($ag);
    $plano = [];
    foreach ($ruta as $inst) {
        foreach ($inst['lugares'] as $l) {
            $plano[] = [$inst['nombre'], $l['ubicacion'], $l['nivel'], $l['total'], $l['mios'], $l['sin_asignar']];
        }
    }
    assert_eq($plano, [
        ['Zona Industrial Km 4.5', 'Edificio Holkanaab', '1', 2, 1, 1],
        ['Zona Industrial Km 4.5', 'Edificio Holkanaab', '2', 1, 0, 1],
        ['Zona Industrial Laguna Azul', 'Area de Carga', null, 1, 0, 1],
    ], 'agrupado por instalación › edificio › nivel, solo de sus instalaciones');
    $lugarCarga = $ruta[1]['lugares'][0];
    assert_true(str_contains((string) $lugarCarga['indicaciones'], 'botas'), 'incluye las indicaciones del lugar');
    assert_eq([$lugarCarga['ubicacion_id'], $lugarCarga['nivel_id']], [$e['carga'], null], 'ids para el enlace a la bandeja filtrada');
}

function test_panel_de_supervision_indicadores_series_y_distribuciones(): void
{
    $e = ejemplos_usuario();
    $sup = usuario('SUP', 'supervisor');
    asignar_supervisor($sup['id'], $e['km']);
    $ag = usuario('AG', 'agente_ti');
    asignar_agente($ag['id'], $e['km']);
    $sol = usuario('U1');
    $a = ticket_en($sol, $e['km'], $e['holkanaab'], nivel_id('1'), $ag['id']);
    $b = ticket_en($sol, $e['km'], $e['holkanaab'], nivel_id('2'));
    $c = ticket_en($sol, $e['km'], $e['holkanaab'], nivel_id('2'));
    ticket_en($sol, $e['laguna'], $e['carga'], null);   // otra instalación: no cuenta
    $cerrado = (int) scalar('SELECT id FROM estados WHERE es_cierre = 1 LIMIT 1');
    test_pdo()->prepare("UPDATE tickets SET estado_id = ?, cerrado_en = NOW(), atendido_en = NOW(), creado_en = NOW() - INTERVAL 2 HOUR WHERE id = ?")->execute([$cerrado, $c]);
    test_pdo()->prepare("UPDATE tickets SET creado_en = NOW() - INTERVAL 5 HOUR, tipo_ticket = 'solicitud' WHERE id = ?")->execute([$b]);
    (new \App\Services\UbicacionFisicaService(test_pdo()))->mapearInmueble('Torre Ejecutiva', $e['km']);
    $w = usuario('W1');
    $cpu = equipo_geca($w, 'CPU-P1');
    test_pdo()->prepare("UPDATE equipos SET tipo_componente = 'CPU' WHERE id = ?")->execute([$cpu]);
    $lap = equipo_geca($w, 'LAP-P1');
    test_pdo()->prepare("UPDATE equipos SET tipo_componente = 'LAPTOP' WHERE id = ?")->execute([$lap]);

    $p = (new \App\Services\TableroService(test_pdo()))->panel([$e['km']]);
    assert_eq([$p['kpi']['abiertos'], $p['kpi']['sin_asignar'], $p['kpi']['cerrados_7d'], $p['kpi']['equipos']], [2, 1, 1, 2], 'indicadores de Km');
    assert_eq(count($p['serie']['dias']), 30, '30 días en la tendencia');
    assert_eq([array_sum($p['serie']['creados']), array_sum($p['serie']['cerrados'])], [3, 1], 'creados y cerrados en el periodo');
    assert_eq($p['sla'], ['en_tiempo' => 1, 'por_vencer' => 0, 'vencido' => 1], 'abiertos por semáforo: uno en tiempo, uno vencido (5 h sin atender, media = 4 h)');
    assert_eq($p['por_tipo'], ['incidencia' => 1, 'solicitud' => 1], 'abiertos por tipo');
    assert_eq($p['equipos_tipo'], ['CPU' => 1, 'LAPTOP' => 1], 'equipos por tipo');
    assert_eq(array_column($p['por_lugar'], 'abiertos', 'ubicacion'), ['Edificio Holkanaab' => 2], 'abiertos por edificio');
    assert_eq($p['kpi']['cumplimiento'], 100, 'cumplimiento de resolución de los cerrados (30 días)');
}
