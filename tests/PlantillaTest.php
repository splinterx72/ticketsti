<?php

declare(strict_types=1);

use App\Core\ValidationException;
use App\Services\Mail\Notifier;
use App\Services\PlantillaService;

function test_plantillas_semilla_y_alta_con_validacion(): void
{
    $svc = new PlantillaService(test_pdo());
    assert_eq(count($svc->todas()), 3, 'tres plantillas de ejemplo');
    $sup = usuario('SUP', 'supervisor');
    $id = $svc->guardar(null, ['nombre' => 'Cambio de equipo', 'asunto' => 'Tu equipo nuevo', 'cuerpo' => 'Hola {nombre}, tu equipo está listo.', 'activa' => '1'], $sup);
    assert_eq($svc->find($id)['asunto'] ?? null, 'Tu equipo nuevo', 'se guardó');
    assert_throws(ValidationException::class, fn () => $svc->guardar(null, ['nombre' => 'Cambio de equipo', 'asunto' => 'x', 'cuerpo' => 'y y y', 'activa' => '1'], $sup), 'nombre repetido', 'existe');
    assert_throws(ValidationException::class, fn () => $svc->guardar(null, ['nombre' => '', 'asunto' => '', 'cuerpo' => '', 'activa' => '1'], $sup), 'vacía');
    $svc->guardar($id, ['nombre' => 'Cambio de equipo', 'asunto' => 'Tu equipo nuevo', 'cuerpo' => 'Hola {nombre}', 'activa' => '0'], $sup);
    assert_eq(count($svc->todas(true)), 3, 'la desactivada no se ofrece para enviar');
}

function test_quien_gestiona_plantillas(): void
{
    assert_true(PlantillaService::puedeGestionar(['rol' => 'supervisor']), 'supervisor');
    assert_true(PlantillaService::puedeGestionar(['rol' => 'coordinador']), 'coordinador');
    assert_true(PlantillaService::puedeGestionar(['rol' => 'admin_ti']), 'admin');
    assert_true(!PlantillaService::puedeGestionar(['rol' => 'agente_ti']), 'el agente solo envía');
}

function test_rellena_variables_y_escapa_nada_raro(): void
{
    $txt = PlantillaService::rellenar('Hola {nombre} ({ficha}) — {folio} {desconocida}', ['{nombre}' => 'José', '{ficha}' => '123', '{folio}' => 'TK-2026-000001']);
    assert_eq($txt, 'Hola José (123) — TK-2026-000001 {desconocida}', 'reemplaza solo las conocidas');
}

function test_enviar_desde_ticket_queda_en_bitacora_y_lo_ve_el_trabajador(): void
{
    $GLOBALS['__app_config']['mail']['driver'] = 'smtp';
    Notifier::reiniciarCola();
    try {
        $inst = instalacion('Km 4.5');
        $ag = usuario('AG', 'agente_ti', 'ag@pemex.com');
        $jose = usuario('12345', 'usuario', 'jose@pemex.com');
        $t = ticket_de($jose, $inst, $ag['id']);
        $svc = new PlantillaService(test_pdo());
        $pl = $svc->todas()[0];
        $vars = $svc->variables((int) $jose['id'], (int) $t['id'], $ag);
        assert_eq($vars['{nombre}'], 'Persona 12345', 'variable nombre');
        assert_eq($vars['{agente}'], 'Persona AG', 'variable agente = quien envía');

        $svc->enviar($ag, (int) $jose['id'], (int) $t['id'], 'Aviso {folio}', "Hola {nombre}\nLínea 2", (int) $pl['id']);
        $cola = Notifier::cola();
        assert_eq(array_column($cola, 'to'), ['jose@pemex.com'], 'sale al trabajador');
        assert_true(str_contains($cola[0]['subject'], 'TK-') && str_contains($cola[0]['text'], 'Hola Persona 12345'), 'con variables reemplazadas');
        assert_eq(scalar('SELECT COUNT(*) FROM correos_enviados WHERE destinatario_id = ? AND ticket_id = ? AND enviado_por = ?', [$jose['id'], $t['id'], $ag['id']]), 1, 'bitácora de envíos');
        assert_eq(scalar("SELECT COUNT(*) FROM comentarios WHERE ticket_id = ? AND es_nota_interna = 0 AND mensaje LIKE '[Correo enviado]%'", [$t['id']]), 1, 'queda en el seguimiento del ticket');

        $sinCorreo = usuario('SC', 'usuario');
        test_pdo()->prepare('UPDATE usuarios SET email = NULL WHERE id = ?')->execute([$sinCorreo['id']]);
        assert_throws(ValidationException::class, fn () => $svc->enviar($ag, (int) $sinCorreo['id'], null, 'Hola', 'Cuerpo de prueba', null), 'sin correo no se puede', 'correo');
        assert_throws(ValidationException::class, fn () => $svc->enviar($ag, (int) $jose['id'], null, '', 'x', null), 'asunto vacío');
    } finally {
        Notifier::reiniciarCola();
        $GLOBALS['__app_config']['mail']['driver'] = 'off';
    }
}
