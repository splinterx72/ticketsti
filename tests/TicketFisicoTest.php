<?php

declare(strict_types=1);

use App\Core\ValidationException;
use App\Services\Mail\Notifier;
use App\Services\TicketService;
use App\Services\UbicacionFisicaService;

/** Escenario: Km 4.5 (Holkanaab y Taller Central) y Laguna Azul (Área de Carga). */
function escenario_fisico(): array
{
    $e = ejemplos_usuario();
    $svc = new UbicacionFisicaService(test_pdo());
    $e['taller'] = $svc->guardarUbicacion($e['km'], null, ['nombre' => 'Taller Central', 'niveles' => [], 'alas' => []]);
    $e['unico'] = nivel_id('Único');
    $e['nivel1'] = nivel_id('1');
    return $e;
}

function reporte_en(int $ubicacionId, int $nivelId, string $ala, string $ref = ''): array
{
    return [
        'reportada_ubicacion_fisica_id' => (string) $ubicacionId, 'reportada_nivel_id' => (string) $nivelId,
        'reportada_ala' => $ala, 'reportada_referencia' => $ref,
    ];
}

function test_discrepancia_por_instalacion(): void
{
    $e = escenario_fisico();
    (new UbicacionFisicaService(test_pdo()))->mapearInmueble('Torre Ejecutiva', $e['km']);
    $jose = usuario('12345');
    $eq = equipo_geca($jose, 'SER001', 'Torre Ejecutiva');

    $r = (new TicketService(test_pdo()))->create($jose, ticket_form([
        'equipo_id' => (string) $eq, 'componente_afectado' => 'equipo',
    ] + reporte_en($e['carga'], $e['unico'], 'na', 'junto al andén 3')));

    assert_true($r['discrepancia'], 'instalación distinta a la del GECA → discrepancia');
    assert_true($r['vinculado'] !== null, 'se genera ticket vinculado');
    assert_eq(scalar('SELECT instalacion_id FROM tickets WHERE id = ?', [$r['id']]), $e['laguna'], 'ticket en la instalación reportada');
    assert_eq(scalar('SELECT referencia FROM tickets WHERE id = ?', [$r['id']]), 'junto al andén 3', 'referencia guardada');
    assert_eq(scalar("SELECT instalacion_id FROM tickets WHERE tipo_ticket = 'actualizacion_ubicacion'"), $e['laguna'], 'vinculado hereda instalación');
    assert_eq(scalar('SELECT CONCAT(real_ubicacion_fisica_id, "|", real_nivel_id, "|", real_ala) FROM equipos WHERE id = ?', [$eq]),
        "{$e['carga']}|{$e['unico']}|na", 'ubicación física real del equipo actualizada');
}

function test_misma_instalacion_otro_edificio_no_discrepa_pero_actualiza_real(): void
{
    $e = escenario_fisico();
    (new UbicacionFisicaService(test_pdo()))->mapearInmueble('Torre Ejecutiva', $e['km']);
    $jose = usuario('12345');
    $eq = equipo_geca($jose, 'SER001');
    $svc = new TicketService(test_pdo());

    $svc->create($jose, ticket_form(['equipo_id' => (string) $eq, 'componente_afectado' => 'equipo'] + reporte_en($e['holkanaab'], $e['nivel1'], 'norte')));
    $r = $svc->create($jose, ticket_form(['equipo_id' => (string) $eq, 'componente_afectado' => 'equipo'] + reporte_en($e['taller'], $e['unico'], 'na')));

    assert_true(!$r['discrepancia'], 'misma instalación → sin discrepancia');
    assert_eq(scalar("SELECT COUNT(*) FROM tickets WHERE tipo_ticket = 'actualizacion_ubicacion'"), 0, 'sin ticket vinculado');
    assert_eq(scalar('SELECT real_ubicacion_fisica_id FROM equipos WHERE id = ?', [$eq]), $e['taller'], 'la ubicación real sigue al último reporte');
}

function test_inmueble_sin_equivalencia_no_discrepa(): void
{
    $e = escenario_fisico();
    $jose = usuario('12345');
    $eq = equipo_geca($jose, 'SER001', 'Edificio Sin Mapear');
    $r = (new TicketService(test_pdo()))->create($jose, ticket_form(['equipo_id' => (string) $eq, 'componente_afectado' => 'equipo'] + reporte_en($e['carga'], $e['unico'], 'na')));
    assert_true(!$r['discrepancia'], 'inmueble GECA sin equivalencia no marca discrepancia');
}

function test_ubicacion_obligatoria_con_catalogo(): void
{
    escenario_fisico();
    $jose = usuario('12345');
    assert_throws(ValidationException::class, fn () => (new TicketService(test_pdo()))->create($jose, ticket_form()),
        'con catálogo, el ticket exige ubicación física', 'ubicación');
}

function test_sin_catalogo_se_acepta_sin_ubicacion(): void
{
    $jose = usuario('12345');
    $r = (new TicketService(test_pdo()))->create($jose, ticket_form());
    assert_eq(scalar('SELECT instalacion_id FROM tickets WHERE id = ?', [$r['id']]), null, 'ticket sin instalación mientras no haya catálogo');
}

function test_destinatarios_ticket_nuevo(): void
{
    $GLOBALS['__app_config']['mail']['driver'] = 'smtp';
    $GLOBALS['__app_config']['mail']['mesa_institucional'] = 'mesadeayuda@pemex.com';
    $GLOBALS['__app_config']['mail']['notify_new_tickets_to'] = [];
    Notifier::reiniciarCola();
    try {
        $e = escenario_fisico();
        $sup = usuario('SUP1', 'supervisor', 'sup1@pemex.com');
        $supOtra = usuario('SUP2', 'supervisor', 'sup2@pemex.com');
        $agente = usuario('AG1', 'agente_ti', 'ag1@pemex.com');
        asignar_supervisor($sup['id'], $e['laguna']);
        asignar_supervisor($supOtra['id'], $e['km']);
        asignar_agente($agente['id'], $e['laguna']);
        $jose = usuario('12345', 'usuario', 'jose@pemex.com');

        $r = (new TicketService(test_pdo()))->create($jose, ticket_form(reporte_en($e['carga'], $e['unico'], 'na')));
        Notifier::ticketCreado($r['id']);

        $destinos = array_column(Notifier::cola(), 'to');
        sort($destinos);
        assert_eq($destinos, ['jose@pemex.com', 'mesadeayuda@pemex.com', 'sup1@pemex.com'],
            'confirmación al solicitante + supervisor de la instalación + mesa institucional (sin agentes ni otros supervisores)');
    } finally {
        Notifier::reiniciarCola();
        $GLOBALS['__app_config']['mail']['driver'] = 'off';
    }
}

function test_correo_de_ticket_nuevo_lleva_la_ficha_completa_para_ti_y_remedy(): void
{
    $GLOBALS['__app_config']['mail']['driver'] = 'smtp';
    $GLOBALS['__app_config']['mail']['mesa_institucional'] = 'mesadeayuda@pemex.com';
    $GLOBALS['__app_config']['mail']['notify_new_tickets_to'] = [];
    Notifier::reiniciarCola();
    try {
        $e = escenario_fisico();
        $sup = usuario('SUP1', 'supervisor', 'sup1@pemex.com');
        asignar_supervisor($sup['id'], $e['laguna']);
        $jose = usuario('12345', 'usuario', 'jose@pemex.com');
        test_pdo()->prepare("UPDATE usuarios SET telefono_ext = '81234', celular = '9381234567', jefe_nombre = 'MARIA IBAÑEZ', jefe_ficha = '330981' WHERE id = ?")->execute([$jose['id']]);
        $r = (new TicketService(test_pdo()))->create($jose, ticket_form(reporte_en($e['carga'], $e['unico'], 'na', 'Junto al andén 3')));
        Notifier::ticketCreado($r['id']);

        $porDestino = array_column(Notifier::cola(), 'text', 'to');
        $ti = (string) $porDestino['mesadeayuda@pemex.com'];
        foreach (['Ficha: 12345', 'Extensión: 81234', 'Celular: 9381234567', 'Jefe: MARIA IBAÑEZ (ficha 330981)', 'Zona Industrial Laguna Azul', 'Junto al andén 3', 'botas', 'Límite de primera atención'] as $dato) {
            assert_true(str_contains($ti, $dato), "el correo para TI/Remedy incluye: {$dato}");
        }
        assert_true(!str_contains((string) $porDestino['jose@pemex.com'], 'Celular'), 'la confirmación al trabajador no repite sus datos personales');
    } finally {
        Notifier::reiniciarCola();
        $GLOBALS['__app_config']['mail']['driver'] = 'off';
    }
}
