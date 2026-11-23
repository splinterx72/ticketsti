<?php

declare(strict_types=1);

use App\Core\ValidationException;
use App\Services\AvisoService;

/** @return array<string, string|list<string>> */
function aviso_form(array $extra = []): array
{
    return $extra + [
        'titulo' => 'Mantenimiento de red', 'mensaje' => 'El sábado no habrá red de 8 a 12.', 'importancia' => 'importante',
        'inicia_en' => date('Y-m-d\TH:i', strtotime('-1 hour')), 'termina_en' => date('Y-m-d\TH:i', strtotime('+1 day')),
        'alcance' => 'todos', 'instalaciones' => [],
    ];
}

function titulos(array $avisos): array
{
    return array_column($avisos, 'titulo');
}

function test_fuera_de_vigencia_no_se_muestra(): void
{
    $admin = usuario('ADM', 'admin_ti');
    $svc = new AvisoService(test_pdo());
    $svc->guardar(null, aviso_form(['titulo' => 'Vigente']), $admin);
    $svc->guardar(null, aviso_form(['titulo' => 'Futuro', 'inicia_en' => date('Y-m-d\TH:i', strtotime('+2 days')), 'termina_en' => date('Y-m-d\TH:i', strtotime('+3 days'))]), $admin);
    $svc->guardar(null, aviso_form(['titulo' => 'Pasado', 'inicia_en' => date('Y-m-d\TH:i', strtotime('-3 days')), 'termina_en' => date('Y-m-d\TH:i', strtotime('-1 day'))]), $admin);
    assert_eq(titulos($svc->vigentesPara(usuario('U1'))), ['Vigente'], 'solo el aviso dentro de su periodo');
    assert_throws(ValidationException::class, fn () => $svc->guardar(null, aviso_form([
        'inicia_en' => date('Y-m-d\TH:i'), 'termina_en' => date('Y-m-d\TH:i', strtotime('-1 hour')),
    ]), $admin), 'fin antes del inicio', 'término');
}

function test_alcance_instalacion_por_ubicacion_habitual(): void
{
    $e = ejemplos_usuario();
    $sup = usuario('SUP', 'supervisor');
    asignar_supervisor($sup['id'], $e['laguna']);
    $svc = new AvisoService(test_pdo());
    $svc->guardar(null, aviso_form(['titulo' => 'Solo Laguna', 'alcance' => 'instalaciones', 'instalaciones' => [(string) $e['laguna']], 'importancia' => 'urgente']), $sup);
    $svc->guardar(null, aviso_form(['titulo' => 'Para todos', 'importancia' => 'informativo']), $sup);

    $enLaguna = usuario('U1');
    test_pdo()->prepare('UPDATE usuarios SET habitual_ubicacion_fisica_id = ? WHERE id = ?')->execute([$e['carga'], $enLaguna['id']]);
    $enKm = usuario('U2');
    test_pdo()->prepare('UPDATE usuarios SET habitual_ubicacion_fisica_id = ? WHERE id = ?')->execute([$e['holkanaab'], $enKm['id']]);

    assert_eq(titulos($svc->vigentesPara($enLaguna)), ['Solo Laguna', 'Para todos'], 'urgente primero; ve el de su instalación');
    assert_eq(titulos($svc->vigentesPara($enKm)), ['Para todos'], 'en otra instalación no lo ve');

    // Sin ubicación habitual: se deduce del equipo principal (equivalencia GECA).
    (new \App\Services\UbicacionFisicaService(test_pdo()))->mapearInmueble('Edificio B', $e['laguna']);
    $conEquipo = usuario('U3');
    equipo_geca($conEquipo, 'SER9', 'Edificio B');
    assert_eq(titulos($svc->vigentesPara($conEquipo)), ['Solo Laguna', 'Para todos'], 'instalación deducida del inmueble GECA de su equipo');

    $agente = usuario('AG', 'agente_ti');
    asignar_agente($agente['id'], $e['laguna']);
    assert_eq(titulos($svc->vigentesPara($agente)), ['Solo Laguna', 'Para todos'], 'agente asignado a la instalación');
}

function test_trabajador_sin_instalacion_solo_ve_todos(): void
{
    $e = ejemplos_usuario();
    $admin = usuario('ADM', 'admin_ti');
    $svc = new AvisoService(test_pdo());
    $svc->guardar(null, aviso_form(['titulo' => 'Aviso Km', 'alcance' => 'instalaciones', 'instalaciones' => [(string) $e['km']]]), $admin);
    $svc->guardar(null, aviso_form(['titulo' => 'Todos']), $admin);
    assert_eq(titulos($svc->vigentesPara(usuario('U1'))), ['Todos'], 'sin instalación conocida solo ve los de todos');
}

function test_login_solo_todos(): void
{
    $e = ejemplos_usuario();
    $admin = usuario('ADM', 'admin_ti');
    $svc = new AvisoService(test_pdo());
    $svc->guardar(null, aviso_form(['titulo' => 'Aviso Km', 'alcance' => 'instalaciones', 'instalaciones' => [(string) $e['km']]]), $admin);
    $svc->guardar(null, aviso_form(['titulo' => 'Todos']), $admin);
    assert_eq(titulos($svc->vigentesPara(null)), ['Todos'], 'la pantalla de entrada solo muestra avisos para todos');
}

function test_supervisor_no_publica_en_instalacion_ajena(): void
{
    $e = ejemplos_usuario();
    $sup = usuario('SUP', 'supervisor');
    asignar_supervisor($sup['id'], $e['laguna']);
    $svc = new AvisoService(test_pdo());
    assert_throws(ValidationException::class, fn () => $svc->guardar(null, aviso_form([
        'alcance' => 'instalaciones', 'instalaciones' => [(string) $e['km']],
    ]), $sup), 'instalación ajena', 'instalaciones');
    assert_throws(ValidationException::class, fn () => $svc->guardar(null, aviso_form([
        'alcance' => 'instalaciones', 'instalaciones' => [],
    ]), $sup), 'alcance por instalación sin elegir ninguna');
    $agente = usuario('AG', 'agente_ti');
    assert_throws(ValidationException::class, fn () => $svc->guardar(null, aviso_form(), $agente), 'un agente no publica avisos');
}
