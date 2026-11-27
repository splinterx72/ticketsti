<?php

declare(strict_types=1);

use App\Core\ValidationException;
use App\Services\Mail\CorreoAjustes;
use App\Services\Mail\Notifier;
use App\Services\TicketService;

/** Crea un ticket en Laguna Azul (Área de Carga) y devuelve los destinatarios del aviso. @return list<string> */
function destinos_ticket_en_laguna(array $e): array
{
    Notifier::reiniciarCola();
    $jose = usuario('J' . bin2hex(random_bytes(2)), 'usuario', 'j' . bin2hex(random_bytes(3)) . '@pemex.com');
    $r = (new TicketService(test_pdo()))->create($jose, ticket_form([
        'reportada_ubicacion_fisica_id' => (string) $e['carga'], 'reportada_nivel_id' => (string) nivel_id('Único'),
        'reportada_ala' => 'na', 'reportada_referencia' => '',
    ]));
    Notifier::ticketCreado($r['id']);
    $d = array_values(array_filter(array_column(Notifier::cola(), 'to'), static fn (string $t): bool => $t !== $jose['email']));
    sort($d);
    return $d;
}

function con_correo(callable $fn): void
{
    $GLOBALS['__app_config']['mail']['driver'] = 'smtp';
    $GLOBALS['__app_config']['mail']['mesa_institucional'] = 'config@pemex.com';
    $GLOBALS['__app_config']['mail']['notify_new_tickets_to'] = [];
    try {
        $fn();
    } finally {
        Notifier::reiniciarCola();
        $GLOBALS['__app_config']['mail']['driver'] = 'off';
    }
}

function test_sin_ajuste_se_usa_el_correo_del_config(): void
{
    con_correo(function (): void {
        $c = new CorreoAjustes(test_pdo());
        assert_eq($c->remedy(), 'config@pemex.com', 'respaldo: config.php');
        $e = ejemplos_usuario();
        assert_true(in_array('config@pemex.com', destinos_ticket_en_laguna($e), true), 'el ticket nuevo va al Remedy del config');
    });
}

function test_admin_captura_remedy_y_copias(): void
{
    con_correo(function (): void {
        $c = new CorreoAjustes(test_pdo());
        $admin = usuario('ADM', 'admin_ti', 'adm@pemex.com');
        $c->guardar(' Remedy@Pemex.com ', "copia1@pemex.com\ncopia2@pemex.com, copia1@pemex.com", (int) $admin['id']);
        assert_eq($c->remedy(), 'remedy@pemex.com', 'normalizado');
        assert_eq($c->copias(), ['copia1@pemex.com', 'copia2@pemex.com'], 'copias sin repetir');
        assert_throws(ValidationException::class, fn () => $c->guardar('no-es-correo', '', (int) $admin['id']), 'correo inválido', 'Remedy');
        assert_throws(ValidationException::class, fn () => $c->guardar('', 'bien@pemex.com, mal', (int) $admin['id']), 'copia inválida', 'mal');

        $e = ejemplos_usuario();
        $sup = usuario('SUP1', 'supervisor', 'sup1@pemex.com');
        asignar_supervisor($sup['id'], $e['laguna']);
        assert_eq(destinos_ticket_en_laguna($e), ['copia1@pemex.com', 'copia2@pemex.com', 'remedy@pemex.com', 'sup1@pemex.com'], 'ticket nuevo: supervisor + Remedy + copias');

        $c->guardar('', '', (int) $admin['id']);
        assert_eq($c->remedy(), '', 'vacío = sin Remedy (no regresa al config)');
        assert_eq(destinos_ticket_en_laguna($e), ['sup1@pemex.com'], 'sin Remedy ni copias');
    });
}

function test_supervisor_apaga_remedy_en_su_instalacion(): void
{
    con_correo(function (): void {
        $c = new CorreoAjustes(test_pdo());
        $e = ejemplos_usuario();
        $sup = usuario('SUP1', 'supervisor', 'sup1@pemex.com');
        asignar_supervisor($sup['id'], $e['laguna']);
        assert_true($c->remedyActivo($e['laguna']), 'encendido por omisión');

        $c->cambiarRemedyInstalacion($e['laguna'], false);
        assert_true(!$c->remedyActivo($e['laguna']), 'apagado');
        assert_true($c->remedyActivo($e['km']), 'las demás siguen igual');
        assert_true($c->remedyActivo(null), 'ticket sin instalación: sí va a Remedy');
        assert_eq(destinos_ticket_en_laguna($e), ['sup1@pemex.com'], 'su instalación ya no manda a Remedy');
    });
}
