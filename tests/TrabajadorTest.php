<?php

declare(strict_types=1);

use App\Services\TrabajadorService;
use App\Services\UbicacionFisicaService;

/** Trabajador del GECA sin activar, con dos equipos: uno con ubicación física real y otro solo con la del GECA. */
function trabajador_con_equipos(): array
{
    $e = ejemplos_usuario();
    (new UbicacionFisicaService(test_pdo()))->mapearInmueble('Torre Ejecutiva', $e['km']);
    $w = ['id' => insert('usuarios', ['ficha' => '323364', 'nombre' => 'INES OCAMPO', 'jefe_ficha' => '330981', 'jefe_nombre' => 'JEFE X', 'rol' => 'usuario', 'registrado' => 0, 'activo' => 1])];
    $cpu = equipo_geca($w, 'CPU-1', 'Torre Ejecutiva');
    $lap = equipo_geca($w, 'LAP-1', 'Torre Ejecutiva');
    test_pdo()->prepare('UPDATE equipos SET real_ubicacion_fisica_id = ?, tipo_componente = ? WHERE id = ?')->execute([$e['carga'], 'LAPTOP', $lap]);
    test_pdo()->prepare("UPDATE equipos SET tipo_componente = 'CPU', ubicacion_referencia = 'T.E. PISO 01' WHERE id = ?")->execute([$cpu]);
    return $e + ['w' => $w, 'cpu' => $cpu, 'lap' => $lap];
}

function test_ficha_de_trabajador_sin_activar_con_equipos_e_instalacion(): void
{
    $d = trabajador_con_equipos();
    $ficha = (new TrabajadorService(test_pdo()))->ficha($d['w']['id'], usuario('ADM', 'admin_ti'));
    assert_eq([$ficha['usuario']['ficha'], $ficha['usuario']['jefe_ficha'], (int) $ficha['usuario']['registrado']], ['323364', '330981', 0], 'datos del GECA aunque no haya activado su cuenta');
    $porSerie = array_column($ficha['equipos'], null, 'serie');
    assert_eq((int) $porSerie['CPU-1']['instalacion_id'], $d['km'], 'sin ubicación física: instalación por equivalencia GECA');
    assert_eq((int) $porSerie['LAP-1']['instalacion_id'], $d['laguna'], 'con ubicación física: manda la física real');
    assert_eq($porSerie['LAP-1']['instalacion'], 'Zona Industrial Laguna Azul', 'nombre de la instalación');
    assert_eq($ficha['ubicacion_geca']['referencia'] ?? null, 'T.E. PISO 01', 'dónde está según el GECA (equipo principal o primero)');
}

function test_ficha_muestra_equipos_que_tuvo_antes(): void
{
    $d = trabajador_con_equipos();
    $otro = usuario('OTRO');
    $anterior = equipo_geca($otro, 'VIEJO-1');
    test_pdo()->prepare("UPDATE equipos SET pr_ficha = '323364', pr_folio = '30557', pr_fecha = '2024-08-21 10:00:00' WHERE id = ?")->execute([$anterior]);
    $ficha = (new TrabajadorService(test_pdo()))->ficha($d['w']['id'], usuario('ADM', 'admin_ti'));
    assert_eq(array_column($ficha['anteriores'], 'serie'), ['VIEJO-1'], 'equipo cuyo resguardo anterior era de esta ficha');
}

function test_ficha_solo_muestra_tickets_del_alcance(): void
{
    $d = trabajador_con_equipos();
    $w = $d['w'] + ['rol' => 'usuario'];
    $enKm = ticket_de($w, $d['km']);
    $enLaguna = ticket_de($w, $d['laguna']);
    $ag = usuario('AG1', 'agente_ti');
    asignar_agente($ag['id'], $d['km']);
    $svc = new TrabajadorService(test_pdo());
    assert_eq(array_map('intval', array_column($svc->ficha($d['w']['id'], $ag)['tickets'], 'id')), [$enKm['id']], 'el agente ve solo los de su instalación');
    assert_eq(count($svc->ficha($d['w']['id'], usuario('ADM', 'admin_ti'))['tickets']), 2, 'el admin ve todos');
    assert_true($enLaguna['id'] > 0, 'existe el ticket ajeno');
    assert_eq($svc->ficha(99999, $ag), null, 'ficha inexistente');
}
