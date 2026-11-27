<?php

declare(strict_types=1);

use App\Services\TableroService;

/** Ticket abierto reportado en un lugar físico. */
function ticket_en(array $sol, int $inst, int $ubic, ?int $nivel, ?int $agente = null): int
{
    $t = ticket_de($sol, $inst, $agente);
    test_pdo()->prepare('UPDATE tickets SET reportada_ubicacion_fisica_id = ?, reportada_nivel_id = ?, reportada_ala = ? WHERE id = ?')
        ->execute([$ubic, $nivel, $nivel !== null ? 'norte' : 'na', $t['id']]);
    return $t['id'];
}

function test_ruta_del_agente_por_instalacion_edificio_y_nivel(): void
{
    $e = ejemplos_usuario();
    $ag = usuario('AG', 'agente_ti');
    asignar_agente($ag['id'], $e['km']);
    asignar_agente($ag['id'], $e['laguna']);
    $sol = usuario('U1');
    ticket_en($sol, $e['km'], $e['holkanaab'], nivel_id('1'), $ag['id']);
    ticket_en($sol, $e['km'], $e['holkanaab'], nivel_id('1'));
    ticket_en($sol, $e['km'], $e['holkanaab'], nivel_id('2'));
    ticket_en($sol, $e['laguna'], $e['carga'], null);
    $otraInst = instalacion('Ajena');
    ticket_de($sol, $otraInst);

    $ruta = (new TableroService(test_pdo()))->rutaAgente($ag);
    $plano = [];
    foreach ($ruta as $inst) {
        foreach ($inst['lugares'] as $l) {
            $plano[] = [$inst['nombre'], $l['ubicacion'], $l['nivel'], $l['total'], $l['mios'], $l['sin_asignar']];
        }
    }
    assert_eq($plano, [
        ['Zona Industrial Km 4.5', 'Edificio Holkanaab', '1', 2, 1, 1],
        ['Zona Industrial Km 4.5', 'Edificio Holkanaab', '2', 1, 0, 1],
        ['Zona Industrial Laguna Azul', 'Area de Carga', null, 1, 0, 1],
    ], 'agrupado por instalación › edificio › nivel, solo de sus instalaciones');
    $lugarCarga = $ruta[1]['lugares'][0];
    assert_true(str_contains((string) $lugarCarga['indicaciones'], 'botas'), 'incluye las indicaciones del lugar');
    assert_eq([$lugarCarga['ubicacion_id'], $lugarCarga['nivel_id']], [$e['carga'], null], 'ids para el enlace a la bandeja filtrada');
}
