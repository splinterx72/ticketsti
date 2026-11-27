<?php

declare(strict_types=1);

use App\Core\ValidationException;
use App\Services\AvisoService;

function test_enterado_quita_el_pendiente_solo_para_quien_lo_lee(): void
{
    $admin = usuario('ADM', 'admin_ti');
    $svc = new AvisoService(test_pdo());
    $id = $svc->guardar(null, aviso_form(['titulo' => 'Simulacro', 'importancia' => 'urgente', 'requiere_confirmacion' => '1']), $admin);
    $info = $svc->guardar(null, aviso_form(['titulo' => 'Solo informativo', 'importancia' => 'informativo', 'requiere_confirmacion' => '']), $admin);
    $u1 = usuario('U1');
    $u2 = usuario('U2');

    $porId = array_column($svc->vigentesPara($u1), null, 'id');
    assert_eq([(int) $porId[$id]['requiere_confirmacion'], $porId[$id]['leido_en']], [1, null], 'el urgente pide confirmación y está sin leer');
    assert_eq((int) $porId[$info]['requiere_confirmacion'], 0, 'el informativo no pide confirmación');
    assert_eq($svc->pendientesDe($u1), 1, 'un aviso pendiente de confirmar');

    $svc->marcarLeido($id, $u1);
    $svc->marcarLeido($id, $u1);
    assert_true(array_column($svc->vigentesPara($u1), 'leido_en', 'id')[$id] !== null, 'U1 ya lo leyó');
    assert_eq($svc->pendientesDe($u1), 0, 'U1 sin pendientes');
    assert_eq($svc->pendientesDe($u2), 1, 'U2 sigue con el pendiente');
    assert_eq(scalar('SELECT COUNT(*) FROM aviso_lecturas'), 1, 'marcar dos veces no duplica');
}

function test_no_puede_confirmar_un_aviso_que_no_le_toca(): void
{
    $e = ejemplos_usuario();
    $sup = usuario('SUP', 'supervisor');
    asignar_supervisor($sup['id'], $e['laguna']);
    $svc = new AvisoService(test_pdo());
    $id = $svc->guardar(null, aviso_form(['alcance' => 'instalaciones', 'instalaciones' => [(string) $e['laguna']], 'requiere_confirmacion' => '1']), $sup);
    $enKm = usuario('U2');
    test_pdo()->prepare('UPDATE usuarios SET habitual_ubicacion_fisica_id = ? WHERE id = ?')->execute([$e['holkanaab'], $enKm['id']]);
    assert_throws(ValidationException::class, fn () => $svc->marcarLeido($id, $enKm), 'aviso de otra instalación');
}

function test_reporte_de_lectura_para_el_autor(): void
{
    $e = ejemplos_usuario();
    $sup = usuario('SUP', 'supervisor');
    asignar_supervisor($sup['id'], $e['laguna']);
    $svc = new AvisoService(test_pdo());
    $id = $svc->guardar(null, aviso_form(['alcance' => 'instalaciones', 'instalaciones' => [(string) $e['laguna']], 'requiere_confirmacion' => '1']), $sup);
    $a = usuario('LA1');
    $b = usuario('LA2');
    $fuera = usuario('KM1');
    test_pdo()->prepare('UPDATE usuarios SET habitual_ubicacion_fisica_id = ? WHERE id IN (?, ?)')->execute([$e['carga'], $a['id'], $b['id']]);
    test_pdo()->prepare('UPDATE usuarios SET habitual_ubicacion_fisica_id = ? WHERE id = ?')->execute([$e['holkanaab'], $fuera['id']]);
    $ag = usuario('AG', 'agente_ti');
    asignar_agente($ag['id'], $e['laguna']);
    $svc->marcarLeido($id, $a);

    $r = $svc->lectura($id);
    assert_eq([$r['leidos'], $r['total']], [1, 4], 'leído por 1 de 4 (2 trabajadores, el agente y el supervisor de Laguna)');
    $faltan = array_column($r['faltan'], 'ficha');
    sort($faltan);
    assert_eq($faltan, ['AG', 'LA2', 'SUP'], 'faltan los que no han confirmado (no el de Km)');
}
