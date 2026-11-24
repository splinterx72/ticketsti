<?php

declare(strict_types=1);

use App\Core\ValidationException;
use App\Services\AdminLocalService;
use App\Services\CuentaService;
use App\Services\Recordatorios;

/** @return array<string, string> */
function cuenta_form(array $extra = []): array
{
    return $extra + [
        'ficha' => 'agente-ti', 'nombre' => 'Agente de Pruebas', 'email' => 'jonathan+agente@pemex.com',
        'rol' => 'agente_ti', 'tipo_cuenta' => 'prueba', 'password' => 'Lince#Verde2026x', 'password_confirm' => 'Lince#Verde2026x',
    ];
}

function test_admin_crea_cuenta_de_prueba_lista_para_entrar(): void
{
    $admin = usuario('ADM', 'admin_ti');
    $id = (new CuentaService(test_pdo()))->crear(cuenta_form(), $admin);
    $u = test_pdo()->query("SELECT * FROM usuarios WHERE id = {$id}")->fetch();
    assert_eq([$u['ficha'], $u['rol'], $u['tipo_cuenta'], (int) $u['registrado'], (int) $u['activo']], ['AGENTE-TI', 'agente_ti', 'prueba', 1, 1], 'cuenta activa, ficha en mayúsculas');
    assert_true($u['email_verificado_en'] !== null, 'el correo queda verificado (el admin responde por él)');
    assert_true(password_verify('Lince#Verde2026x', (string) $u['password_hash']), 'contraseña cifrada');
    assert_true(AdminLocalService::bloqueado($u), 'una cuenta de prueba de TI sí pasa por el bloqueo de admin local');
}

function test_cuenta_de_administracion_no_tiene_bloqueo_de_admin_local(): void
{
    $admin = usuario('ADM', 'admin_ti');
    $id = (new CuentaService(test_pdo()))->crear(cuenta_form([
        'ficha' => 'ADMIN-TI', 'nombre' => 'Administrador Mesa TI', 'email' => 'admin.mesa@pctunning.com.mx',
        'rol' => 'admin_ti', 'tipo_cuenta' => 'administracion', 'password' => 'Halcon#Azul2026xy', 'password_confirm' => 'Halcon#Azul2026xy',
    ]), $admin);
    $u = test_pdo()->query("SELECT * FROM usuarios WHERE id = {$id}")->fetch();
    assert_true(!AdminLocalService::bloqueado($u), 'sin fecha de admin local y aun así no se bloquea');
    assert_true(!AdminLocalService::enAviso($u), 'tampoco recibe avisos');

    $GLOBALS['__app_config']['mail']['driver'] = 'smtp';
    \App\Services\Mail\Notifier::reiniciarCola();
    try {
        test_pdo()->prepare('UPDATE usuarios SET admin_local_cambiado_en = ? WHERE id = ?')->execute([date('Y-m-d', strtotime('-3 months +8 days')), $id]);
        (new Recordatorios(test_pdo()))->ejecutar(date('Y-m-d'));
        assert_eq(array_column(\App\Services\Mail\Notifier::cola(), 'to'), [], 'el cron no le manda recordatorios');
    } finally {
        \App\Services\Mail\Notifier::reiniciarCola();
        $GLOBALS['__app_config']['mail']['driver'] = 'off';
    }
}

function test_crear_cuenta_valida_datos(): void
{
    $admin = usuario('ADM', 'admin_ti');
    $svc = new CuentaService(test_pdo());
    $svc->crear(cuenta_form(), $admin);
    assert_throws(ValidationException::class, fn () => $svc->crear(cuenta_form(['email' => 'otro@pemex.com']), $admin), 'ficha repetida', 'ficha');
    assert_throws(ValidationException::class, fn () => $svc->crear(cuenta_form(['ficha' => 'OTRA-1']), $admin), 'correo repetido', 'correo');
    assert_throws(ValidationException::class, fn () => $svc->crear(cuenta_form(['ficha' => 'mal ficha!', 'email' => 'x@pemex.com']), $admin), 'ficha con espacios o signos', 'ficha');
    assert_throws(ValidationException::class, fn () => $svc->crear(cuenta_form(['ficha' => 'OTRA-2', 'email' => 'y@pemex.com', 'password' => 'corta', 'password_confirm' => 'corta']), $admin), 'contraseña débil');
    assert_throws(ValidationException::class, fn () => $svc->crear(cuenta_form(['ficha' => 'OTRA-3', 'email' => 'z@pemex.com', 'tipo_cuenta' => 'geca']), $admin), 'solo administración o prueba', 'tipo');
    assert_throws(ValidationException::class, fn () => $svc->crear(cuenta_form(['ficha' => 'OTRA-4', 'email' => 'w@pemex.com']), usuario('SUP', 'supervisor')), 'solo el Admin TI crea cuentas');
}
