<?php

declare(strict_types=1);

use App\Core\Cifrado;
use App\Core\ValidationException;
use App\Services\BitLockerService;

/** Llave de recuperación válida: 8 bloques de 6 dígitos, cada uno múltiplo de 11. */
const LLAVE_OK = '123453-234564-345675-456786-567897-678909-111111-222222';
const ID_OK = '1a2b3c4d-5e6f-7a8b-9c0d-1e2f3a4b5c6d';

function equipo_con_dueno(): array
{
    $e = ejemplos_usuario();
    (new \App\Services\UbicacionFisicaService(test_pdo()))->mapearInmueble('Torre Ejecutiva', $e['km']);
    $w = usuario('W1');
    test_pdo()->prepare('UPDATE usuarios SET password_hash = ? WHERE id = ?')->execute([password_hash('Clave#Segura2026', PASSWORD_DEFAULT), $w['id']]);
    $eq = equipo_geca($w, 'CPU-BL1');
    return $e + ['w' => $w, 'eq' => $eq];
}

function test_formatos_de_identificador_y_llave(): void
{
    assert_eq(BitLockerService::normalizarIdentificador('{' . ID_OK . '}'), strtoupper(ID_OK), 'identificador con llaves se normaliza');
    assert_eq(BitLockerService::normalizarIdentificador('1A2B3C4D'), '1A2B3C4D', 'se acepta el identificador corto de 8 caracteres que muestra la pantalla');
    assert_eq(BitLockerService::normalizarIdentificador('no-es-id'), null, 'identificador inválido');
    assert_eq(BitLockerService::normalizarLlave(str_replace('-', ' ', LLAVE_OK)), LLAVE_OK, 'llave con espacios se normaliza');
    assert_eq(BitLockerService::normalizarLlave('123456-234564-345675-456786-567897-678909-111111-222222'), null, 'bloque que no es múltiplo de 11 se rechaza');
    assert_eq(BitLockerService::normalizarLlave('123453-234564'), null, 'llave incompleta');
}

function test_dueno_y_ti_de_la_instalacion_capturan_y_ven_otros_no(): void
{
    $d = equipo_con_dueno();
    $svc = new BitLockerService(test_pdo(), new Cifrado(str_repeat('k', 32)));
    $id = $svc->guardar($d['eq'], $d['w'], ['unidad' => 'C:', 'identificador' => ID_OK, 'llave' => LLAVE_OK, 'nota' => '']);
    $crudo = (string) scalar('SELECT llave_cifrada FROM equipo_bitlocker WHERE id = ?', [$id]);
    assert_true($crudo !== '' && !str_contains($crudo, '123453'), 'la llave queda cifrada en la base');

    $ag = usuario('AG', 'agente_ti');
    asignar_agente($ag['id'], $d['km']);
    $svc->guardar($d['eq'], $ag, ['unidad' => 'D:', 'identificador' => '9F8E7D6C', 'llave' => '', 'nota' => 'Disco de datos']);
    assert_eq(count($svc->deEquipo($d['eq'], $ag)), 2, 'el agente de la instalación ve las dos (sin la llave)');
    assert_true(!array_key_exists('llave', $svc->deEquipo($d['eq'], $ag)[0]), 'la lista nunca trae la llave');

    $otro = usuario('W2');
    assert_throws(ValidationException::class, fn () => $svc->guardar($d['eq'], $otro, ['unidad' => 'C:', 'identificador' => ID_OK, 'llave' => '', 'nota' => '']), 'otro trabajador no captura');
    $agFuera = usuario('AGX', 'agente_ti');
    asignar_agente($agFuera['id'], $d['laguna']);
    assert_eq($svc->deEquipo($d['eq'], $agFuera), null, 'agente de otra instalación no ve');
}

function test_mostrar_la_llave_pide_contrasena_y_queda_en_bitacora(): void
{
    $d = equipo_con_dueno();
    $svc = new BitLockerService(test_pdo(), new Cifrado(str_repeat('k', 32)));
    $id = $svc->guardar($d['eq'], $d['w'], ['unidad' => 'C:', 'identificador' => ID_OK, 'llave' => LLAVE_OK, 'nota' => '']);
    assert_throws(ValidationException::class, fn () => $svc->mostrar($id, $d['w'], 'mala', 'Olvidé mi llave'), 'contraseña equivocada', 'contraseña');
    assert_eq($svc->mostrar($id, $d['w'], 'Clave#Segura2026', 'Pantalla de recuperación'), LLAVE_OK, 'el dueño ve su llave');
    assert_eq(scalar('SELECT COUNT(*) FROM bitlocker_consultas WHERE bitlocker_id = ? AND usuario_id = ?', [$id, $d['w']['id']]), 1, 'la consulta quedó registrada (la fallida no)');
    $otra = new BitLockerService(test_pdo(), new Cifrado(str_repeat('z', 32)));
    assert_throws(\RuntimeException::class, fn () => $otra->mostrar($id, $d['w'], 'Clave#Segura2026', 'x'), 'con otra clave de cifrado no se puede leer');
}

function test_buscador_por_identificador_serie_o_ficha_respeta_el_alcance(): void
{
    $d = equipo_con_dueno();
    $svc = new BitLockerService(test_pdo(), new Cifrado(str_repeat('k', 32)));
    $svc->guardar($d['eq'], $d['w'], ['unidad' => 'C:', 'identificador' => ID_OK, 'llave' => LLAVE_OK, 'nota' => '']);
    $ag = usuario('AG', 'agente_ti');
    asignar_agente($ag['id'], $d['km']);

    $porId = $svc->buscar($ag, '1a2b3c4d');
    assert_eq(array_column($porId, 'serie'), ['CPU-BL1'], 'los 8 caracteres que dicta el usuario bastan');
    assert_eq($porId[0]['identificadores'], ['C: 1A2B3C4D-5E6F-7A8B-9C0D-1E2F3A4B5C6D'], 'muestra unidad e identificador');
    assert_true(!array_key_exists('llave_cifrada', $porId[0]), 'nunca trae la llave');
    assert_eq(array_column($svc->buscar($ag, '{1A2B 3C4D'), 'serie'), ['CPU-BL1'], 'tolera llaves y espacios');
    assert_eq(array_column($svc->buscar($ag, 'cpu-bl1'), 'serie'), ['CPU-BL1'], 'por serie');
    assert_eq(array_column($svc->buscar($ag, 'W1'), 'serie'), ['CPU-BL1'], 'por ficha del resguardante');
    assert_eq($svc->buscar($ag, 'ab'), [], 'texto muy corto: nada');

    $fuera = usuario('AGX', 'agente_ti');
    asignar_agente($fuera['id'], $d['laguna']);
    assert_eq($svc->buscar($fuera, '1A2B3C4D'), [], 'otra instalación: no lo encuentra');
    assert_eq($svc->buscar($d['w'], '1A2B3C4D'), [], 'un trabajador no usa el buscador');
}

function test_mis_equipos_con_sus_llaves(): void
{
    $d = equipo_con_dueno();
    equipo_geca($d['w'], 'CPU-BL2');
    $svc = new BitLockerService(test_pdo(), new Cifrado(str_repeat('k', 32)));
    $svc->guardar($d['eq'], $d['w'], ['unidad' => 'C:', 'identificador' => ID_OK, 'llave' => '', 'nota' => '']);
    $mios = $svc->misEquipos((int) $d['w']['id']);
    assert_eq(array_column($mios, 'serie'), ['CPU-BL1', 'CPU-BL2'], 'todos sus equipos');
    assert_eq(array_map(static fn (array $e): int => (int) $e['registros'], $mios), [1, 0], 'cuántas llaves tiene cada uno');
}
