<?php

declare(strict_types=1);

use App\Core\Auth;
use App\Core\Session;
use App\Services\Mail\Notifier;
use App\Services\VerificacionEmailService;

const CLAVE_T = 'Clave#Segura2026';

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
    assert_eq($svc->verificar($token, CLAVE_T), $id, 'primer uso verifica');
    assert_eq(scalar('SELECT CONCAT(registrado, "|", email_verificado_en IS NOT NULL) FROM usuarios WHERE id = ?', [$id]), '1|1', 'cuenta activada y verificada');
    assert_eq($svc->verificar($token, CLAVE_T), null, 'segundo uso rechazado');
}

function test_token_vencido(): void
{
    $id = pendiente('U1', 'u1@pemex.com');
    $svc = new VerificacionEmailService(test_pdo());
    $token = $svc->emitir($id, 'u1@pemex.com');
    test_pdo()->exec('UPDATE verificaciones_email SET expira_en = NOW() - INTERVAL 1 MINUTE');
    assert_eq($svc->verificar($token, CLAVE_T), null, 'token vencido rechazado');
    assert_eq(scalar('SELECT registrado FROM usuarios WHERE id = ?', [$id]), 0, 'la cuenta sigue sin activar');

    $nuevo = $svc->emitir($id, 'u1@pemex.com');
    $otro = $svc->emitir($id, 'u1@pemex.com');
    assert_eq($svc->verificar($nuevo, CLAVE_T), null, 'emitir de nuevo invalida el token anterior');
    assert_eq($svc->verificar($otro, CLAVE_T), $id, 'el último token sí funciona');
}

function test_token_ajeno_no_activa_otra_cuenta(): void
{
    $a = pendiente('UA', 'ua@pemex.com');
    $b = pendiente('UB', 'ub@pemex.com');
    $svc = new VerificacionEmailService(test_pdo());
    $tokenA = $svc->emitir($a, 'ua@pemex.com');
    $svc->emitir($b, 'ub@pemex.com');
    assert_eq($svc->verificar($tokenA, CLAVE_T), $a, 'el token de A solo activa a A');
    assert_eq(scalar('SELECT registrado FROM usuarios WHERE id = ?', [$b]), 0, 'B sigue pendiente');
    assert_eq($svc->verificar('x' . substr($tokenA, 1), CLAVE_T), null, 'token alterado rechazado');
}

function test_cambio_de_correo_se_aplica_al_verificar(): void
{
    $u = usuario('U1', 'usuario', 'viejo@pemex.com');
    test_pdo()->prepare('UPDATE usuarios SET password_hash = ? WHERE id = ?')->execute([password_hash(CLAVE_T, PASSWORD_DEFAULT), $u['id']]);
    $svc = new VerificacionEmailService(test_pdo());
    $token = $svc->emitir($u['id'], 'nuevo@pemex.com');
    assert_eq(scalar('SELECT email FROM usuarios WHERE id = ?', [$u['id']]), 'viejo@pemex.com', 'el correo no cambia antes de verificar');
    $svc->verificar($token, CLAVE_T);
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
        $svc->verificar($svc->emitir($id, 'u1@pemex.com'), CLAVE_T);
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

/** Ficha del GECA aún sin registrar (sin correo ni contraseña). */
function ficha_geca(string $ficha): int
{
    return insert('usuarios', ['ficha' => $ficha, 'nombre' => "Persona {$ficha}", 'rol' => 'usuario', 'registrado' => 0, 'activo' => 1]);
}

function test_registro_pendiente_no_ocupa_el_correo(): void
{
    $intruso = ficha_geca('X1');
    $victima = ficha_geca('V1');
    $svc = new VerificacionEmailService(test_pdo());

    $token = $svc->prepararRegistro($intruso, 'victima@pemex.com', '1234', 'Otra#Clave2026xx', null);
    assert_true($token !== null, 'el registro pendiente emite enlace');
    assert_eq(scalar('SELECT email FROM usuarios WHERE id = ?', [$intruso]), null, 'el correo sin verificar no se guarda en la cuenta');
    assert_true(!$svc->correoOcupado('victima@pemex.com', $victima), 'un registro pendiente no aparta el correo de otra persona');

    $tokenV = $svc->prepararRegistro($victima, 'victima@pemex.com', '5678', CLAVE_T, null);
    assert_eq($svc->verificar((string) $tokenV, CLAVE_T), $victima, 'la dueña del correo sí puede verificarlo');
    assert_eq(scalar('SELECT email FROM usuarios WHERE id = ?', [$victima]), 'victima@pemex.com', 'el correo se aplica al verificar');
    assert_true($svc->correoOcupado('victima@pemex.com', $intruso), 'ya verificado, el correo queda ocupado');
    assert_eq($svc->verificar((string) $token, 'Otra#Clave2026xx'), null, 'el enlace del intruso ya no puede tomar el correo');
}

function test_verificar_exige_la_contrasena_de_la_cuenta(): void
{
    $intruso = ficha_geca('X1');
    $svc = new VerificacionEmailService(test_pdo());
    $token = (string) $svc->prepararRegistro($intruso, 'victima@pemex.com', '1234', 'Otra#Clave2026xx', null);

    assert_eq($svc->verificar($token, CLAVE_T), null, 'quien abre el enlace sin conocer la contraseña elegida no activa la cuenta');
    assert_eq(scalar('SELECT registrado FROM usuarios WHERE id = ?', [$intruso]), 0, 'la cuenta sigue pendiente');
    assert_eq(scalar('SELECT COUNT(*) FROM verificaciones_email WHERE usuario_id = ? AND usado_en IS NULL', [$intruso]), 1, 'una contraseña equivocada no gasta el enlace');
    assert_eq($svc->verificar($token, 'Otra#Clave2026xx'), $intruso, 'con su contraseña sí se activa');
}

function test_consultar_enlace_no_lo_gasta_y_distingue_ya_verificado(): void
{
    $id = ficha_geca('U1');
    $svc = new VerificacionEmailService(test_pdo());
    $token = (string) $svc->prepararRegistro($id, 'u1@pemex.com', '1234', CLAVE_T, null);

    $c = $svc->consultar($token);
    assert_eq([$c['estado'] ?? null, $c['ficha'] ?? null, $c['email'] ?? null], ['valido', 'U1', 'u1@pemex.com'], 'el enlace se puede consultar (GET de un escáner)');
    assert_eq($svc->consultar($token)['estado'] ?? null, 'valido', 'consultar no lo gasta');
    $svc->verificar($token, CLAVE_T);
    assert_eq($svc->consultar($token)['estado'] ?? null, 'verificado', 'enlace usado de cuenta ya verificada');
    assert_eq($svc->consultar(str_repeat('a', 43)), null, 'enlace desconocido');
}

function test_sin_supervisor_vigente_avisa_al_admin_ti(): void
{
    $GLOBALS['__app_config']['mail']['driver'] = 'smtp';
    Notifier::reiniciarCola();
    try {
        $e = ejemplos_usuario();
        $sup = usuario('SUP1', 'supervisor', 'sup1@pemex.com');
        // El titular terminó ayer y la suplencia empieza mañana: hoy nadie está a cargo.
        asignar_supervisor($sup['id'], $e['laguna'], 'titular', null, date('Y-m-d', strtotime('-1 day')));
        usuario('ADM1', 'admin_ti', 'adm1@pemex.com');
        $id = pendiente('U1', 'u1@pemex.com');
        test_pdo()->prepare('UPDATE usuarios SET habitual_ubicacion_fisica_id = ? WHERE id = ?')->execute([$e['carga'], $id]);

        Notifier::usuarioRegistrado($id);
        assert_eq(array_column(Notifier::cola(), 'to'), ['adm1@pemex.com'], 'sin supervisor vigente el aviso va al Admin TI');
    } finally {
        Notifier::reiniciarCola();
        $GLOBALS['__app_config']['mail']['driver'] = 'off';
    }
}

function test_correo_aceptable_solo_exige_dominio_si_cambia(): void
{
    assert_true(VerificacionEmailService::correoAceptable('externo@gmail.com', 'Externo@gmail.com'), 'correo heredado sin cambio se conserva');
    assert_true(!VerificacionEmailService::correoAceptable('otro@gmail.com', 'externo@gmail.com'), 'cambiar a otro externo se rechaza');
    assert_true(VerificacionEmailService::correoAceptable('nuevo@pemex.com', 'externo@gmail.com'), 'cambiar al de la empresa se acepta');
    assert_true(!VerificacionEmailService::correoAceptable('', 'externo@gmail.com'), 'vacío se rechaza');
}

function test_celular_opcional_se_normaliza_y_se_guarda_al_registrarse(): void
{
    assert_eq(VerificacionEmailService::celular(''), '', 'vacío = sin celular');
    assert_eq(VerificacionEmailService::celular(' 938 123 4567 '), '938 123 4567', 'se acepta con espacios');
    assert_eq(VerificacionEmailService::celular('+52 (938) 123-4567'), '+52 (938) 123-4567', 'se acepta con lada y signos');
    assert_eq(VerificacionEmailService::celular('12345'), null, 'muy corto: menos de 10 dígitos');
    assert_eq(VerificacionEmailService::celular('938-ABC-4567'), null, 'letras no');
    assert_eq(VerificacionEmailService::celular(str_repeat('9', 21)), null, 'más de 20 caracteres no');

    $id = ficha_geca('C1');
    (new VerificacionEmailService(test_pdo()))->prepararRegistro($id, 'c1@pemex.com', '1234', CLAVE_T, null, '9381234567');
    assert_eq(scalar('SELECT celular FROM usuarios WHERE id = ?', [$id]), '9381234567', 'el celular queda en la cuenta');
}
