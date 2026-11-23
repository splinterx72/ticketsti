<?php

declare(strict_types=1);

use App\Core\Auth;
use App\Core\Session;
use App\Services\Mail\Notifier;
use App\Services\VerificacionEmailService;

function pendiente(string $ficha, string $email): int
{
    return insert('usuarios', [
        'ficha' => $ficha, 'nombre' => "Persona {$ficha}", 'email' => $email,
        'password_hash' => password_hash('Clave#Segura2026', PASSWORD_DEFAULT), 'rol' => 'usuario',
        'registrado' => 0, 'activo' => 1,
    ]);
}

function test_dominios(): void
{
    $svc = new VerificacionEmailService(test_pdo());
    assert_true($svc->dominioPermitido('Ana@PEMEX.COM'), 'mayúsculas aceptadas');
    assert_true($svc->dominioPermitido('jose.pena@pemex.com'), 'correo normal');
    assert_true(!$svc->dominioPermitido('a@pemex.com.mx'), 'pemex.com.mx rechazado');
    assert_true(!$svc->dominioPermitido('a@x.pemex.com'), 'subdominio rechazado');
    assert_true(!$svc->dominioPermitido('a@pemex.co'), 'pemex.co rechazado');
    assert_true(!$svc->dominioPermitido('a@gmail.com'), 'gmail rechazado');
    assert_true(!$svc->dominioPermitido('no-es-correo'), 'texto sin @ rechazado');
}

function test_token_un_solo_uso(): void
{
    $id = pendiente('U1', 'u1@pemex.com');
    $svc = new VerificacionEmailService(test_pdo());
    $token = $svc->emitir($id, 'u1@pemex.com');
    assert_eq(strlen($token), 43, 'token base64url de 32 bytes');
    assert_eq(scalar('SELECT COUNT(*) FROM verificaciones_email WHERE token_hash = ?', [$token]), 0, 'no se guarda el token en claro');
    assert_eq($svc->verificar($token), $id, 'primer uso verifica');
    assert_eq(scalar('SELECT CONCAT(registrado, "|", email_verificado_en IS NOT NULL) FROM usuarios WHERE id = ?', [$id]), '1|1', 'cuenta activada y verificada');
    assert_eq($svc->verificar($token), null, 'segundo uso rechazado');
}

function test_token_vencido(): void
{
    $id = pendiente('U1', 'u1@pemex.com');
    $svc = new VerificacionEmailService(test_pdo());
    $token = $svc->emitir($id, 'u1@pemex.com');
    test_pdo()->exec('UPDATE verificaciones_email SET expira_en = NOW() - INTERVAL 1 MINUTE');
    assert_eq($svc->verificar($token), null, 'token vencido rechazado');
    assert_eq(scalar('SELECT registrado FROM usuarios WHERE id = ?', [$id]), 0, 'la cuenta sigue sin activar');

    $nuevo = $svc->emitir($id, 'u1@pemex.com');
    $otro = $svc->emitir($id, 'u1@pemex.com');
    assert_eq($svc->verificar($nuevo), null, 'emitir de nuevo invalida el token anterior');
    assert_eq($svc->verificar($otro), $id, 'el último token sí funciona');
}

function test_token_ajeno_no_activa_otra_cuenta(): void
{
    $a = pendiente('UA', 'ua@pemex.com');
    $b = pendiente('UB', 'ub@pemex.com');
    $svc = new VerificacionEmailService(test_pdo());
    $tokenA = $svc->emitir($a, 'ua@pemex.com');
    $svc->emitir($b, 'ub@pemex.com');
    assert_eq($svc->verificar($tokenA), $a, 'el token de A solo activa a A');
    assert_eq(scalar('SELECT registrado FROM usuarios WHERE id = ?', [$b]), 0, 'B sigue pendiente');
    assert_eq($svc->verificar('x' . substr($tokenA, 1)), null, 'token alterado rechazado');
}

function test_cambio_de_correo_se_aplica_al_verificar(): void
{
    $u = usuario('U1', 'usuario', 'viejo@pemex.com');
    $svc = new VerificacionEmailService(test_pdo());
    $token = $svc->emitir($u['id'], 'nuevo@pemex.com');
    assert_eq(scalar('SELECT email FROM usuarios WHERE id = ?', [$u['id']]), 'viejo@pemex.com', 'el correo no cambia antes de verificar');
    $svc->verificar($token);
    assert_eq(scalar('SELECT email FROM usuarios WHERE id = ?', [$u['id']]), 'nuevo@pemex.com', 'el correo cambia al verificar');
}

function test_reenvio_limitado(): void
{
    $id = pendiente('U1', 'u1@pemex.com');
    $svc = new VerificacionEmailService(test_pdo());
    for ($i = 0; $i < 5; $i++) {
        assert_true($svc->puedeReenviar($id), "reenvío {$i} permitido");
        $svc->emitir($id, 'u1@pemex.com');
    }
    assert_true(!$svc->puedeReenviar($id), 'sexto reenvío en la misma hora bloqueado');
}

function test_verificar_activa_y_notifica_supervisor(): void
{
    $GLOBALS['__app_config']['mail']['driver'] = 'smtp';
    Notifier::reiniciarCola();
    try {
        $e = ejemplos_usuario();
        $sup = usuario('SUP1', 'supervisor', 'sup1@pemex.com');
        asignar_supervisor($sup['id'], $e['laguna']);
        $id = pendiente('U1', 'u1@pemex.com');
        test_pdo()->prepare('UPDATE usuarios SET habitual_ubicacion_fisica_id = ? WHERE id = ?')->execute([$e['carga'], $id]);

        $svc = new VerificacionEmailService(test_pdo());
        $nuevo = $svc->esRegistroNuevo($id);
        $svc->verificar($svc->emitir($id, 'u1@pemex.com'));
        assert_true($nuevo, 'era un registro nuevo');
        Notifier::usuarioRegistrado($id);
        assert_eq(array_column(Notifier::cola(), 'to'), ['sup1@pemex.com'], 'aviso al supervisor de la instalación elegida');
    } finally {
        Notifier::reiniciarCola();
        $GLOBALS['__app_config']['mail']['driver'] = 'off';
    }
}

function test_cuenta_existente_sin_verificar_queda_bloqueada(): void
{
    $u = usuario('U1', 'usuario', 'u1@pemex.com');
    $_SESSION = [];
    Auth::login($u['id']);
    assert_true(Auth::requiereVerificacion(), 'cuenta existente sin verificar debe verificar su correo');
    test_pdo()->prepare('UPDATE usuarios SET email_verificado_en = NOW() WHERE id = ?')->execute([$u['id']]);
    Auth::refrescar();
    assert_true(!Auth::requiereVerificacion(), 'tras verificar ya no se pide');
    $_SESSION = [];
}
