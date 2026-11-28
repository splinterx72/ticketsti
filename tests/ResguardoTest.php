<?php

declare(strict_types=1);

use App\Core\ValidationException;
use App\Services\ResguardoService;

function test_recibir_por_ficha_con_lo_que_deja(): void
{
    $pdo = test_pdo();
    $svc = new ResguardoService($pdo);
    $trab = usuario('11111');
    $lap = equipo_geca($trab, 'LAP-1');                  // con monitor casado MON-LAP-1
    $rep = equipo_geca($trab, 'REP-1');
    $ti = usuario('AG', 'agente_ti');
    $inst = instalacion('Km 4.5');

    $c = $svc->candidatos('11111');
    assert_eq($c['usuario']['ficha'], '11111', 'encuentra al trabajador por ficha');
    assert_eq(count($c['equipos']), 2, 'con sus equipos');
    assert_true(array_key_exists('monitor_1', ResguardoService::opcionesDe($c['equipos'][0])), 'el monitor casado se ofrece para marcar');
    assert_eq($svc->candidatos('rep-1')['usuario']['ficha'], '11111', 'por serie también trae a su resguardante');

    assert_throws(ValidationException::class, fn () => $svc->recibir($ti, ['motivo' => 'baja', 'equipos' => []]), 'sin equipos no');
    $id = $svc->recibir($ti, [
        'usuario_id' => $trab['id'], 'motivo' => 'baja', 'instalacion_id' => $inst, 'equipos' => [(string) $lap, (string) $rep],
        'accesorios' => [$lap => ['monitor_1', 'cargador', 'inventado']], 'condicion' => [$lap => 'regular'], 'obs' => [$lap => 'Rayada'],
    ]);
    $rc = $svc->recepcion($id);
    assert_true(preg_match('/^RE-\d{4}-\d{6}$/', (string) $rc['folio']) === 1, 'folio RE-AAAA-NNNNNN');
    assert_eq(count($rc['equipos']), 2, 'dos equipos recibidos');
    $l = array_values(array_filter($rc['equipos'], static fn (array $r): bool => $r['serie'] === 'LAP-1'))[0];
    assert_eq(ResguardoService::dejo($l), ['Monitor 1 MON-LAP-1', 'Cargador'], 'lo que dejó (sin claves inventadas)');
    assert_eq([$l['estado'], $l['condicion']], ['en_resguardo', 'regular'], 'entra en resguardo con su condición');
    assert_throws(ValidationException::class, fn () => $svc->recibir($ti, ['motivo' => 'baja', 'equipos' => [(string) $lap]]), 'no se recibe dos veces');
}

function test_disponible_asignar_y_confirmar_con_geca(): void
{
    $pdo = test_pdo();
    $svc = new ResguardoService($pdo);
    $ti = usuario('AG', 'agente_ti');
    $lap = equipo_geca(usuario('11111'), 'LAP-9');
    $id = $svc->recibir($ti, ['motivo' => 'cambio', 'equipos' => [(string) $lap]]);
    $item = (int) $svc->recepcion($id)['equipos'][0]['id'];

    assert_throws(ValidationException::class, fn () => $svc->asignar($item, '22222', '', $ti), 'en resguardo aún no se asigna');
    $svc->cambiarEstado($item, 'disponible', $ti);
    assert_eq(count($svc->lista(usuario('ADM', 'admin_ti'), ['estado' => 'disponible'])), 1, 'aparece en disponibles');
    assert_throws(ValidationException::class, fn () => $svc->asignar($item, 'NOEXISTE', '', $ti), 'ficha inexistente');

    $nuevo = usuario('22222');
    $svc->asignar($item, '22222', 'Reemplazo', $ti);
    assert_eq(scalar('SELECT estado FROM equipos_resguardo WHERE id = ?', [$item]), 'asignado', 'asignado');
    assert_eq($svc->confirmarConGeca(), 0, 'aún pendiente en GECA');
    assert_true($svc->activo($lap) !== null, 'pendiente en GECA sigue abierto');

    $pdo->prepare('UPDATE equipos SET usuario_id = ? WHERE id = ?')->execute([$nuevo['id'], $lap]);   // llegó la carga GECA
    assert_eq($svc->confirmarConGeca(), 1, 'la carga GECA lo confirma');
    assert_eq($svc->activo($lap), null, 'ya no está en manos de TI');
    assert_throws(ValidationException::class, fn () => $svc->cambiarEstado($item, 'disponible', $ti), 'asignado no regresa a disponible');
}

function test_alcance_por_instalacion(): void
{
    $svc = new ResguardoService(test_pdo());
    $km = instalacion('Km 4.5');
    $laguna = instalacion('Laguna');
    $ag = usuario('AG', 'agente_ti');
    asignar_agente($ag['id'], $km);
    $svc->recibir($ag, ['motivo' => 'baja', 'instalacion_id' => $laguna, 'equipos' => [(string) equipo_geca(usuario('11111'), 'X-1')]]);
    $svc->recibir($ag, ['motivo' => 'baja', 'instalacion_id' => $km, 'equipos' => [(string) equipo_geca(usuario('22222'), 'X-2')]]);
    $svc->recibir($ag, ['motivo' => 'baja', 'equipos' => [(string) equipo_geca(usuario('33333'), 'X-3')]]);
    assert_eq(count($svc->lista($ag)), 2, 'su instalación y las que no tienen instalación');
    assert_eq(count($svc->lista(usuario('ADM', 'admin_ti'))), 3, 'el admin ve todo');
    assert_true(!$svc->enAlcance($ag, $laguna), 'otra instalación no');
}
