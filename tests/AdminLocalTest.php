<?php

declare(strict_types=1);

use App\Core\ValidationException;
use App\Services\AdminLocalService;
use App\Services\Mail\Notifier;
use App\Services\Recordatorios;

/** Fecha de cambio que deja exactamente $dias días para vencer, respecto a $hoy. */
function cambio_para_dias(int $dias, string $hoy): string
{
    $vence = (new DateTimeImmutable($hoy))->modify("+{$dias} days");
    // Retrocede 3 meses calendario desde el vencimiento buscado.
    for ($d = 0; $d < 5; $d++) {
        $cambio = $vence->modify('-3 months')->modify("-{$d} days")->format('Y-m-d');
        if (AdminLocalService::vence($cambio) === $vence->format('Y-m-d')) {
            return $cambio;
        }
        $cambio = $vence->modify('-3 months')->modify("+{$d} days")->format('Y-m-d');
        if (AdminLocalService::vence($cambio) === $vence->format('Y-m-d')) {
            return $cambio;
        }
    }
    throw new RuntimeException('No se pudo construir la fecha de prueba');
}

function test_vencimiento_tres_meses_calendario(): void
{
    assert_eq(AdminLocalService::vence('2026-11-30'), '2027-02-28', 'fin de mes se ajusta al último día');
    assert_eq(AdminLocalService::vence('2026-08-15'), '2026-11-15', 'tres meses calendario');
    assert_eq(AdminLocalService::vence(null), null, 'sin fecha no hay vencimiento');
    assert_eq(AdminLocalService::diasRestantes('2026-08-15', '2026-11-05'), 10, 'días restantes');
    assert_eq(AdminLocalService::diasRestantes('2026-08-15', '2026-11-16'), -1, 'vencida = negativo');
}

function test_umbrales_bloqueo(): void
{
    $hoy = date('Y-m-d');
    $agente = ['rol' => 'agente_ti', 'admin_local_cambiado_en' => null];
    assert_true(AdminLocalService::bloqueado($agente), 'sin fecha → bloqueado');
    $agente['admin_local_cambiado_en'] = cambio_para_dias(5, $hoy);
    assert_true(AdminLocalService::bloqueado($agente), '5 días → bloqueado');
    $agente['admin_local_cambiado_en'] = cambio_para_dias(6, $hoy);
    assert_true(!AdminLocalService::bloqueado($agente), '6 días → no bloqueado');
    $agente['admin_local_cambiado_en'] = cambio_para_dias(-3, $hoy);
    assert_true(AdminLocalService::bloqueado($agente), 'vencida → bloqueado');
    $sup = ['rol' => 'supervisor', 'admin_local_cambiado_en' => cambio_para_dias(2, $hoy)];
    assert_true(AdminLocalService::bloqueado($sup), 'el supervisor también se bloquea');
}

function test_usuario_normal_nunca_bloqueado(): void
{
    assert_true(!AdminLocalService::bloqueado(['rol' => 'usuario', 'admin_local_cambiado_en' => null]), 'trabajador no tiene contraseña de admin local');
}

function test_semaforo(): void
{
    assert_eq(AdminLocalService::semaforo(11), 'verde', '11 días verde');
    assert_eq(AdminLocalService::semaforo(10), 'amarillo', '10 días amarillo');
    assert_eq(AdminLocalService::semaforo(6), 'amarillo', '6 días amarillo');
    assert_eq(AdminLocalService::semaforo(5), 'rojo', '5 días rojo');
    assert_eq(AdminLocalService::semaforo(-1), 'rojo', 'vencida rojo');
    assert_eq(AdminLocalService::semaforo(null), 'rojo', 'sin fecha rojo');
}

function test_registrar_valida_fecha(): void
{
    $u = usuario('AG1', 'agente_ti');
    $svc = new AdminLocalService(test_pdo());
    assert_throws(ValidationException::class, fn () => $svc->registrar($u['id'], date('Y-m-d', strtotime('+1 day'))), 'fecha futura');
    assert_throws(ValidationException::class, fn () => $svc->registrar($u['id'], date('Y-m-d', strtotime('-4 months'))), 'fecha que ya venció');
    $svc->registrar($u['id'], date('Y-m-d'));
    assert_eq(scalar('SELECT admin_local_cambiado_en FROM usuarios WHERE id = ?', [$u['id']]), date('Y-m-d'), 'fecha registrada');
}

function test_cron_no_duplica_mismo_dia(): void
{
    $GLOBALS['__app_config']['mail']['driver'] = 'smtp';
    Notifier::reiniciarCola();
    try {
        $hoy = date('Y-m-d');
        $ag = usuario('AG1', 'agente_ti', 'ag1@pemex.com');
        test_pdo()->prepare('UPDATE usuarios SET admin_local_cambiado_en = ? WHERE id = ?')->execute([cambio_para_dias(10, $hoy), $ag['id']]);
        $lejos = usuario('AG2', 'agente_ti', 'ag2@pemex.com');
        test_pdo()->prepare('UPDATE usuarios SET admin_local_cambiado_en = ? WHERE id = ?')->execute([cambio_para_dias(11, $hoy), $lejos['id']]);

        $rec = new Recordatorios(test_pdo());
        $primera = $rec->ejecutar($hoy);
        $segunda = $rec->ejecutar($hoy);
        assert_eq($primera['enviados'], 1, 'a 10 días se avisa (a 11 todavía no)');
        assert_eq($segunda['enviados'], 0, 'la segunda corrida del mismo día no duplica');
        assert_eq(array_column(Notifier::cola(), 'to'), ['ag1@pemex.com'], 'un solo correo al agente');

        $manana = (new DateTimeImmutable($hoy))->modify('+1 day')->format('Y-m-d');
        assert_eq($rec->ejecutar($manana)['enviados'], 2, 'al día siguiente avisa de nuevo (9 días y 10 días)');
    } finally {
        Notifier::reiniciarCola();
        $GLOBALS['__app_config']['mail']['driver'] = 'off';
    }
}

function test_cron_avisa_supervisor_base_no_al_de_prestamo(): void
{
    $GLOBALS['__app_config']['mail']['driver'] = 'smtp';
    Notifier::reiniciarCola();
    try {
        $hoy = date('Y-m-d');
        $a = instalacion('A');
        $b = instalacion('B');
        $supA = usuario('SUPA', 'supervisor', 'supa@pemex.com');
        $supB = usuario('SUPB', 'supervisor', 'supb@pemex.com');
        $admin = usuario('ADM', 'admin_ti', 'admin@pemex.com');
        asignar_supervisor($supA['id'], $a);
        asignar_supervisor($supB['id'], $b);
        $ag = usuario('AG1', 'agente_ti', 'ag1@pemex.com');
        asignar_agente($ag['id'], $a);
        asignar_agente($ag['id'], $b, 'prestamo', null, date('Y-m-d', strtotime('+5 days')));
        foreach ([$supA, $supB, $admin] as $x) {
            test_pdo()->prepare('UPDATE usuarios SET admin_local_cambiado_en = ? WHERE id = ?')->execute([$hoy, $x['id']]);
        }
        test_pdo()->prepare('UPDATE usuarios SET admin_local_cambiado_en = ? WHERE id = ?')->execute([cambio_para_dias(4, $hoy), $ag['id']]);
        test_pdo()->prepare('UPDATE usuarios SET admin_local_cambiado_en = ? WHERE id = ?')->execute([cambio_para_dias(3, $hoy), $supB['id']]);

        (new Recordatorios(test_pdo()))->ejecutar($hoy);
        $destinos = array_column(Notifier::cola(), 'to');
        sort($destinos);
        assert_eq($destinos, ['admin@pemex.com', 'ag1@pemex.com', 'supa@pemex.com', 'supb@pemex.com'],
            'agente + su supervisor base; el supervisor bloqueado + el admin TI; el supervisor del préstamo no recibe lo del agente');
        $aSupB = array_values(array_filter(Notifier::cola(), static fn (array $m): bool => $m['to'] === 'supb@pemex.com'));
        assert_eq(count($aSupB), 1, 'el supervisor del préstamo solo recibe su propio aviso');
    } finally {
        Notifier::reiniciarCola();
        $GLOBALS['__app_config']['mail']['driver'] = 'off';
    }
}
