<?php

declare(strict_types=1);

use App\Core\ValidationException;
use App\Services\AsignacionService;
use App\Services\UbicacionFisicaService;

function test_ejemplos_del_usuario(): void
{
    $e = ejemplos_usuario();
    $svc = new UbicacionFisicaService(test_pdo());

    assert_eq($svc->etiqueta($e['holkanaab'], nivel_id('1'), 'norte'),
        'Zona Industrial Km 4.5 › Edificio Holkanaab › Nivel 1 › Ala Norte', 'etiqueta ejemplo 1');
    assert_eq($svc->etiqueta($e['carga'], nivel_id('Único'), 'na'),
        'Zona Industrial Laguna Azul › Area de Carga › Nivel Único › Ala N/A', 'etiqueta ejemplo 2');

    $cat = $svc->catalogo();
    $laguna = array_values(array_filter($cat, static fn (array $i): bool => $i['id'] === $e['laguna']))[0];
    $carga = $laguna['ubicaciones'][0];
    assert_eq($carga['niveles'], [['id' => nivel_id('Único'), 'nombre' => 'Único']], 'sin niveles → Único');
    assert_eq($carga['alas'], ['na'], 'sin alas → N/A');
    assert_eq($carga['indicaciones'], 'Es necesario contar con botas de trabajo y todo el equipo de seguridad', 'indicaciones del lugar');
    assert_true($svc->hayCatalogo(), 'hay catálogo');

    $v = $svc->validar([
        'reportada_ubicacion_fisica_id' => (string) $e['holkanaab'], 'reportada_nivel_id' => (string) nivel_id('1'),
        'reportada_ala' => 'norte', 'reportada_referencia' => '  junto a la sala 2 ',
    ], 'reportada');
    assert_eq($v, [
        'instalacion_id' => $e['km'], 'ubicacion_fisica_id' => $e['holkanaab'], 'nivel_id' => nivel_id('1'),
        'ala' => 'norte', 'referencia' => 'junto a la sala 2',
    ], 'validar devuelve la ubicación normalizada');

    $svc->mapearInmueble('Torre Ejecutiva', $e['km']);
    assert_eq($svc->instalacionDeInmuebleGeca('torre ejecutiva'), $e['km'], 'equivalencia GECA sin distinguir mayúsculas');
    assert_eq($svc->instalacionDeInmuebleGeca('Edificio B'), null, 'inmueble sin equivalencia');
}

function test_validar_rechaza_nivel_no_habilitado(): void
{
    $e = ejemplos_usuario();
    $svc = new UbicacionFisicaService(test_pdo());
    assert_throws(ValidationException::class, fn () => $svc->validar([
        'habitual_ubicacion_fisica_id' => (string) $e['holkanaab'], 'habitual_nivel_id' => (string) nivel_id('5'), 'habitual_ala' => 'norte',
    ], 'habitual'), 'nivel 5 no habilitado', 'nivel');
    assert_throws(ValidationException::class, fn () => $svc->validar([
        'habitual_ubicacion_fisica_id' => (string) $e['holkanaab'], 'habitual_nivel_id' => (string) nivel_id('1'), 'habitual_ala' => 'oriente',
    ], 'habitual'), 'ala oriente no habilitada', 'ala');
    assert_throws(ValidationException::class, fn () => $svc->validar([], 'habitual'), 'ubicación vacía');
}

function test_validar_rechaza_ubicacion_de_otra_instalacion(): void
{
    $e = ejemplos_usuario();
    $svc = new UbicacionFisicaService(test_pdo());
    assert_throws(ValidationException::class, fn () => $svc->validar([
        'reportada_instalacion_id' => (string) $e['km'],
        'reportada_ubicacion_fisica_id' => (string) $e['carga'], 'reportada_nivel_id' => (string) nivel_id('Único'), 'reportada_ala' => 'na',
    ], 'reportada'), 'ubicación que no pertenece a la instalación elegida', 'instalación');
}

function test_supervisor_no_puede_prestar_agente_ajeno(): void
{
    $a = instalacion('A');
    $b = instalacion('B');
    $sup = usuario('SUP1', 'supervisor');
    $otroSup = usuario('SUP2', 'supervisor');
    $admin = usuario('ADM1', 'admin_ti');
    $agente = usuario('AG1', 'agente_ti');
    $svc = new AsignacionService(test_pdo());

    $svc->asignarSupervisor($admin, $sup['id'], $a, 'titular', null, null);
    $svc->asignarSupervisor($admin, $otroSup['id'], $b, 'titular', null, null);
    assert_throws(ValidationException::class, fn () => $svc->asignarSupervisor($sup, $sup['id'], $b, 'titular', null, null),
        'solo el admin asigna supervisores');

    $svc->asignarAgente($sup, $agente['id'], $a);
    assert_throws(ValidationException::class, fn () => $svc->asignarAgente($sup, $agente['id'], $b),
        'supervisor no asigna en instalación ajena');

    $hasta = date('Y-m-d', strtotime('+7 days'));
    assert_throws(ValidationException::class, fn () => $svc->prestarAgente($otroSup, $agente['id'], $a, null, $hasta),
        'supervisor ajeno no presta al agente');
    $svc->prestarAgente($sup, $agente['id'], $b, null, $hasta);
    assert_eq(scalar("SELECT COUNT(*) FROM agente_instalaciones WHERE agente_id = ? AND tipo = 'prestamo' AND instalacion_id = ?", [$agente['id'], $b]), 1, 'préstamo registrado');
}

function test_prestamo_requiere_hasta(): void
{
    $a = instalacion('A');
    $b = instalacion('B');
    $sup = usuario('SUP1', 'supervisor');
    $admin = usuario('ADM1', 'admin_ti');
    $agente = usuario('AG1', 'agente_ti');
    $svc = new AsignacionService(test_pdo());
    $svc->asignarSupervisor($admin, $sup['id'], $a, 'titular', null, null);
    $svc->asignarAgente($sup, $agente['id'], $a);

    assert_throws(ValidationException::class, fn () => $svc->prestarAgente($sup, $agente['id'], $b, null, null),
        'préstamo sin fecha de fin', 'hasta');
    assert_throws(ValidationException::class, fn () => $svc->prestarAgente($sup, $agente['id'], $b, null, date('Y-m-d', strtotime('-1 day'))),
        'préstamo que ya terminó');
    assert_throws(ValidationException::class, fn () => $svc->prestarAgente($sup, $agente['id'], $a, null, date('Y-m-d', strtotime('+3 days'))),
        'préstamo a su propia instalación');
}
