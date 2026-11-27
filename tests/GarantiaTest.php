<?php

declare(strict_types=1);

use App\Services\GarantiaService;

function garantia_form(string $serie, array $extra = []): array
{
    return $extra + [
        'serie' => $serie, 'elemento_cubierto' => 'equipo', 'proveedor' => 'Dell México', 'numero_poliza_contrato' => 'P-1',
        'fecha_inicio' => date('Y-m-d', strtotime('-1 month')), 'fecha_vencimiento' => date('Y-m-d', strtotime('+1 year')),
        'estado' => 'vigente', 'notas' => '',
    ];
}

function test_agente_y_supervisor_registran_y_modifican_con_bitacora(): void
{
    $w = usuario('W1');
    equipo_geca($w, 'CPU-G1');
    $ag = usuario('AG', 'agente_ti');
    $sup = usuario('SUP', 'supervisor');
    $svc = new GarantiaService(test_pdo());

    $id = $svc->save(null, garantia_form('CPU-G1'), (int) $ag['id']);
    assert_eq(scalar('SELECT capturado_por FROM garantias WHERE id = ?', [$id]), $ag['id'], 'queda quién la registró');
    assert_eq(scalar('SELECT actualizado_por FROM garantias WHERE id = ?', [$id]), null, 'aún sin modificar');

    $svc->save($id, garantia_form('CPU-G1', ['estado' => 'en_reclamacion', 'notas' => 'Se reportó a Dell']), (int) $sup['id']);
    assert_eq(scalar('SELECT actualizado_por FROM garantias WHERE id = ?', [$id]), $sup['id'], 'queda quién la modificó');
    assert_eq(scalar('SELECT capturado_por FROM garantias WHERE id = ?', [$id]), $ag['id'], 'no cambia quién la registró');
    assert_eq($svc->find($id)['capturado_por_nombre'] ?? null, 'Persona AG', 'nombre de quien registró');
    assert_eq($svc->find($id)['actualizado_por_nombre'] ?? null, 'Persona SUP', 'nombre de quien modificó');
}

function test_el_trabajador_ve_el_historial_de_las_garantias_de_sus_equipos(): void
{
    $w = usuario('W1');
    $otro = usuario('W2');
    equipo_geca($w, 'CPU-G1');
    equipo_geca($otro, 'CPU-G2');
    $ag = usuario('AG', 'agente_ti');
    $svc = new GarantiaService(test_pdo());
    $svc->save(null, garantia_form('CPU-G1'), (int) $ag['id']);
    $svc->save(null, garantia_form('CPU-G1', ['proveedor' => 'HP', 'fecha_inicio' => '2020-01-01', 'fecha_vencimiento' => '2021-01-01']), (int) $ag['id']);
    $svc->save(null, garantia_form('CPU-G2'), (int) $ag['id']);

    $mias = $svc->deUsuario((int) $w['id']);
    assert_eq(count($mias), 2, 'solo las de sus equipos, incluida la vencida (historial)');
    assert_eq(array_column($mias, 'estado'), ['vigente', 'vencida'], 'primero las vigentes');
    assert_eq($mias[0]['serie'], 'CPU-G1', 'con la serie del equipo');
}
