<?php

declare(strict_types=1);

use App\Core\ValidationException;
use App\Services\InventarioService;
use App\Services\UbicacionFisicaService;

/** Km (Torre Ejecutiva mapeada), Laguna; equipos: solo-GECA en Km, físico en Laguna con GECA de Km, y uno sin equivalencia. */
function inventario_ejemplo(): array
{
    $e = ejemplos_usuario();
    (new UbicacionFisicaService(test_pdo()))->mapearInmueble('Torre Ejecutiva', $e['km']);
    $w = usuario('W1');
    $soloGeca = equipo_geca($w, 'GECA-KM');
    $fisico = equipo_geca($w, 'FIS-LAGUNA');
    test_pdo()->prepare('UPDATE equipos SET real_ubicacion_fisica_id = ? WHERE id = ?')->execute([$e['carga'], $fisico]);
    $sin = equipo_geca($w, 'SIN-INST', 'Inmueble Sin Equivalencia');
    $baja = equipo_geca(['id' => null], 'BAJA-KM');
    test_pdo()->prepare("UPDATE equipos SET estado_resguardo = 'BAJA' WHERE id = ?")->execute([$baja]);
    return $e + compact('soloGeca', 'fisico', 'sin', 'baja');
}

function series_con(string $sql, array $params): array
{
    $s = test_pdo()->prepare("SELECT e.serie FROM equipos e WHERE {$sql} ORDER BY e.serie");
    $s->execute($params);
    return $s->fetchAll(PDO::FETCH_COLUMN);
}

function test_instalacion_del_equipo_fisica_primero_y_si_no_geca(): void
{
    $d = inventario_ejemplo();
    $svc = new InventarioService(test_pdo());
    assert_eq(series_con(...$svc->condicionInstalaciones([$d['km']])), ['BAJA-KM', 'GECA-KM'], 'Km: los que solo tienen GECA de Km (el físico en Laguna no)');
    assert_eq(series_con(...$svc->condicionInstalaciones([$d['laguna']])), ['FIS-LAGUNA'], 'Laguna: manda la ubicación física real');
    assert_eq(series_con(...$svc->condicionInstalaciones([$d['km'], $d['laguna']])), ['BAJA-KM', 'FIS-LAGUNA', 'GECA-KM'], 'varias instalaciones');
    assert_eq(series_con($svc->condicionSinInstalacion(), []), ['SIN-INST'], 'sin instalación: inmueble sin equivalencia y sin ubicación física');
    assert_eq(series_con(...$svc->condicionInstalaciones([])), [], 'lista vacía: nada');
}

function test_resumen_por_instalacion_para_el_panel(): void
{
    $d = inventario_ejemplo();
    insert('garantias', ['equipo_id' => $d['soloGeca'], 'elemento_cubierto' => 'equipo', 'proveedor' => 'HP', 'fecha_inicio' => date('Y-m-d', strtotime('-1 year')), 'fecha_vencimiento' => date('Y-m-d', strtotime('+1 year')), 'estado' => 'vigente']);
    $r = (new InventarioService(test_pdo()))->resumenPorInstalacion([$d['km'], $d['laguna']]);
    assert_eq($r[$d['km']], ['equipos' => 2, 'con_garantia' => 1, 'discrepancias' => 0, 'sin_trabajador' => 1], 'Km');
    assert_eq($r[$d['laguna']], ['equipos' => 1, 'con_garantia' => 0, 'discrepancias' => 1, 'sin_trabajador' => 0], 'Laguna: el equipo está físicamente ahí pero su GECA dice Km');
}

function test_supervisor_relaciona_inmuebles_solo_con_sus_instalaciones(): void
{
    $d = inventario_ejemplo();
    $sup = usuario('SUP', 'supervisor');
    asignar_supervisor($sup['id'], $d['laguna']);
    $svc = new UbicacionFisicaService(test_pdo());

    $svc->mapearInmueblePor($sup, 'Inmueble Sin Equivalencia', $d['laguna']);
    assert_eq((int) scalar("SELECT instalacion_id FROM geca_inmueble_instalacion WHERE inmueble = 'Inmueble Sin Equivalencia'"), $d['laguna'], 'relaciona un inmueble libre con su instalación');
    assert_throws(ValidationException::class, fn () => $svc->mapearInmueblePor($sup, 'Torre Ejecutiva', $d['laguna']), 'no puede quitarle un inmueble a otra instalación', 'otra instalación');
    assert_throws(ValidationException::class, fn () => $svc->mapearInmueblePor($sup, 'Inmueble Sin Equivalencia', $d['km']), 'no puede usar una instalación ajena', 'tus instalaciones');
    assert_throws(ValidationException::class, fn () => $svc->mapearInmueblePor($sup, 'No existe en GECA', $d['laguna']), 'el inmueble debe venir del GECA', 'GECA');
    assert_throws(ValidationException::class, fn () => $svc->mapearInmueblePor($sup, 'Torre Ejecutiva', null), 'no puede quitar la equivalencia de otra instalación', 'otra instalación');
    $svc->mapearInmueblePor($sup, 'Inmueble Sin Equivalencia', null);
    assert_eq(scalar("SELECT COUNT(*) FROM geca_inmueble_instalacion WHERE inmueble = 'Inmueble Sin Equivalencia'"), 0, 'puede quitar la de su instalación');

    $svc->mapearInmueblePor(usuario('ADM', 'admin_ti'), 'Torre Ejecutiva', $d['laguna']);
    assert_eq((int) scalar("SELECT instalacion_id FROM geca_inmueble_instalacion WHERE inmueble = 'Torre Ejecutiva'"), $d['laguna'], 'el admin puede reasignar cualquiera');
}

function test_equipos_segun_geca_por_inmueble_referencia_y_tipo(): void
{
    $pdo = test_pdo();
    $inv = new \App\Services\InventarioService($pdo);
    $km = instalacion('Km 4.5');
    $sinEq = instalacion('Laguna');
    $u = usuario('11111');
    $a = equipo_geca($u, 'S-1', 'Holkanaab');          // con monitor casado MON-S-1
    $b = equipo_geca($u, 'S-2', 'Holkanaab');
    $c = equipo_geca($u, 'S-3', '2040');               // inmueble con nombre numérico
    equipo_geca($u, 'S-4', 'Ajeno');
    $pdo->prepare("UPDATE equipos SET tipo_componente = 'LAPTOP', ubicacion_referencia = ' piso 2  ala norte.' WHERE id = ?")->execute([$a]);
    $pdo->prepare("UPDATE equipos SET tipo_componente = 'CPU', ubicacion_referencia = 'PISO 2 ALA NORTE', serie_monitor1 = NULL WHERE id = ?")->execute([$b]);
    $pdo->prepare("UPDATE equipos SET tipo_componente = 'REPLICADOR', serie_monitor1 = NULL WHERE id = ?")->execute([$c]);
    $svc = new \App\Services\UbicacionFisicaService($pdo);
    $svc->mapearInmueble('Holkanaab', $km);
    $svc->mapearInmueble('2040', $km);

    $d = $inv->gecaPorUbicacion($km);
    assert_eq($d['total'], 4, '3 equipos + 1 monitor casado; el inmueble ajeno no cuenta');
    assert_eq($d['inmuebles']['Holkanaab'], 3, 'por inmueble');
    assert_eq(count($d['filas']), 2, 'la referencia se normaliza: "piso 2  ala norte." = "PISO 2 ALA NORTE"');
    $holk = array_values(array_filter($d['filas'], static fn (array $f): bool => $f['inmueble'] === 'Holkanaab'))[0];
    assert_eq([$holk['referencia'], $holk['tipos']], ['PISO 2 ALA NORTE', ['LAPTOP' => 1, 'MONITOR' => 1, 'CPU' => 1]], 'desglose por tipo con el monitor casado');
    assert_eq(array_values(array_filter($d['filas'], static fn (array $f): bool => $f['inmueble'] === '2040'))[0]['referencia'], 'Sin referencia', 'vacía = Sin referencia');
    assert_true($d['con_equivalencia'], 'tiene inmuebles relacionados');
    assert_true(!$inv->gecaPorUbicacion($sinEq)['con_equivalencia'], 'sin inmuebles relacionados');
}
