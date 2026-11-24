<?php

declare(strict_types=1);

use App\Core\ValidationException;
use App\Services\Alcance;
use App\Services\AsignacionService;
use App\Services\Mail\Notifier;
use App\Services\Recordatorios;
use App\Services\ZonaService;

/** Zona Norte con Km y Laguna, Zona Marina con una plataforma; coordinador de Norte. */
function zonas_ejemplo(): array
{
    $e = ejemplos_usuario();
    $plataforma = instalacion('Plataforma Akal');
    $zonas = new ZonaService(test_pdo());
    $norte = $zonas->guardarZona(null, 'Zona Norte');
    $marina = $zonas->guardarZona(null, 'Zona Marina');
    $zonas->asignarZona($e['km'], $norte);
    $zonas->asignarZona($e['laguna'], $norte);
    $zonas->asignarZona($plataforma, $marina);
    $admin = usuario('ADM', 'admin_ti', 'adm@pemex.com');
    $coord = usuario('COORD', 'coordinador', 'coord@pemex.com');
    (new AsignacionService(test_pdo()))->asignarCoordinador($admin, $coord['id'], $norte, 'titular', null, null);
    return $e + compact('plataforma', 'norte', 'marina', 'admin', 'coord');
}

function test_coordinador_ve_las_instalaciones_de_sus_zonas(): void
{
    $d = zonas_ejemplo();
    $alcance = new Alcance(test_pdo());
    $esperadas = [$d['km'], $d['laguna']];
    sort($esperadas);
    assert_eq($alcance->instalacionesDe($d['coord']['id']), $esperadas, 've Km y Laguna (Zona Norte), no la plataforma');
    assert_eq($alcance->instalacionesSupervisadas($d['coord']['id']), $esperadas, 'actúa como supervisor en su zona');

    $suplente = usuario('COORD2', 'coordinador');
    insert('coordinador_zonas', ['coordinador_id' => $suplente['id'], 'zona_id' => $d['marina'], 'tipo' => 'suplencia',
        'desde' => date('Y-m-d', strtotime('-10 days')), 'hasta' => date('Y-m-d', strtotime('-1 day'))]);
    assert_eq($alcance->instalacionesDe($suplente['id']), [], 'suplencia vencida: nada');
    assert_eq(array_column((new ZonaService(test_pdo()))->coordinadoresDe($d['km']), 'id'), [$d['coord']['id']], 'coordinador vigente de la instalación');
}

function test_coordinador_asigna_supervisores_solo_en_su_zona(): void
{
    $d = zonas_ejemplo();
    $sup = usuario('SUP', 'supervisor');
    $svc = new AsignacionService(test_pdo());
    $svc->asignarSupervisor($d['coord'], $sup['id'], $d['km'], 'titular', null, null);
    assert_eq(array_column((new Alcance(test_pdo()))->supervisoresDe($d['km']), 'id'), [$sup['id']], 'supervisor asignado por el coordinador');
    assert_throws(ValidationException::class, fn () => $svc->asignarSupervisor($d['coord'], $sup['id'], $d['plataforma'], 'titular', null, null),
        'no en otra zona', 'tu zona');
    assert_throws(ValidationException::class, fn () => $svc->asignarSupervisor($sup, $sup['id'], $d['laguna'], 'titular', null, null),
        'un supervisor no asigna supervisores');
    assert_throws(ValidationException::class, fn () => $svc->asignarCoordinador($d['coord'], $d['coord']['id'], $d['marina'], 'titular', null, null),
        'solo el admin asigna coordinadores', 'Admin TI');

    $asig = (int) scalar('SELECT id FROM supervisor_instalaciones WHERE supervisor_id = ?', [$sup['id']]);
    $svc->quitar($d['coord'], 'supervisor_instalaciones', $asig);
    assert_eq((new Alcance(test_pdo()))->supervisoresDe($d['km']), [], 'el coordinador también puede quitarlo');
}

function test_semaforo_del_coordinador_incluye_supervisores_y_agentes(): void
{
    $d = zonas_ejemplo();
    $sup = usuario('SUP', 'supervisor');
    asignar_supervisor($sup['id'], $d['km']);
    $ag = usuario('AG', 'agente_ti');
    asignar_agente($ag['id'], $d['laguna']);
    $ajeno = usuario('AJ', 'agente_ti');
    asignar_agente($ajeno['id'], $d['plataforma']);
    $ids = array_column((new Alcance(test_pdo()))->agentesDe($d['coord']['id']), 'id');
    sort($ids);
    $esperados = [$sup['id'], $ag['id']];
    sort($esperados);
    assert_eq($ids, $esperados, 'supervisores y agentes de su zona, no los de otra');
}

function test_avisos_de_admin_local_del_supervisor_van_al_coordinador(): void
{
    $d = zonas_ejemplo();
    $sup = usuario('SUP', 'supervisor', 'sup@pemex.com');
    asignar_supervisor($sup['id'], $d['km']);
    $supMarina = usuario('SUPM', 'supervisor', 'supm@pemex.com');
    asignar_supervisor($supMarina['id'], $d['plataforma']);
    $vence8 = date('Y-m-d', strtotime('-3 months +8 days'));
    test_pdo()->prepare('UPDATE usuarios SET admin_local_cambiado_en = ? WHERE id IN (?, ?)')->execute([$vence8, $sup['id'], $supMarina['id']]);

    $GLOBALS['__app_config']['mail']['driver'] = 'smtp';
    Notifier::reiniciarCola();
    try {
        (new Recordatorios(test_pdo()))->ejecutar(date('Y-m-d'));
        $porDestino = [];
        foreach (Notifier::cola() as $m) {
            $porDestino[$m['to']][] = $m['subject'] ?? '';
        }
        ksort($porDestino);
        assert_eq(array_keys($porDestino), ['adm@pemex.com', 'coord@pemex.com', 'sup@pemex.com', 'supm@pemex.com'],
            'cada supervisor recibe su aviso; el de Norte va a su coordinador y el de Marina (sin coordinador) al Admin TI');
        assert_eq(count($porDestino['coord@pemex.com']), 1, 'el coordinador solo recibe el de su zona');
    } finally {
        Notifier::reiniciarCola();
        $GLOBALS['__app_config']['mail']['driver'] = 'off';
    }
}

function test_ticket_sin_supervisor_avisa_al_coordinador_antes_que_al_admin(): void
{
    $d = zonas_ejemplo();
    $GLOBALS['__app_config']['mail']['driver'] = 'smtp';
    Notifier::reiniciarCola();
    try {
        $w = usuario('W1');
        test_pdo()->prepare('UPDATE usuarios SET habitual_ubicacion_fisica_id = ? WHERE id = ?')->execute([$d['carga'], $w['id']]);
        Notifier::usuarioRegistrado($w['id']);
        assert_eq(array_column(Notifier::cola(), 'to'), ['coord@pemex.com'], 'sin supervisor vigente: al coordinador de la zona');
    } finally {
        Notifier::reiniciarCola();
        $GLOBALS['__app_config']['mail']['driver'] = 'off';
    }
}
