<?php

declare(strict_types=1);

use App\Core\ValidationException;
use App\Services\Mail\Notifier;
use App\Services\SlaService;
use App\Services\TicketService;
use App\Services\ZonaService;

/** Ticket abierto con fecha de creación ajustada. */
function ticket_hace(array $sol, ?int $inst, string $prioridad, string $hace): int
{
    $t = ticket_de($sol, $inst);
    test_pdo()->prepare("UPDATE tickets SET prioridad = ?, creado_en = NOW() - INTERVAL {$hace} WHERE id = ?")->execute([$prioridad, $t['id']]);
    return $t['id'];
}

function test_tiempos_por_prioridad_configurables_por_el_admin(): void
{
    $svc = new SlaService(test_pdo());
    assert_eq($svc->config()['media'], ['horas_respuesta' => 4, 'horas_resolucion' => 24], 'valores iniciales de prioridad media');
    $svc->guardar(['media' => ['horas_respuesta' => '2', 'horas_resolucion' => '16']], usuario('ADM', 'admin_ti'));
    assert_eq($svc->config()['media'], ['horas_respuesta' => 2, 'horas_resolucion' => 16], 'el admin los cambia');
    assert_throws(ValidationException::class, fn () => $svc->guardar(['alta' => ['horas_respuesta' => '9', 'horas_resolucion' => '4']], usuario('ADM2', 'admin_ti')),
        'la resolución no puede ser menor que la respuesta', 'resolución');
    assert_throws(ValidationException::class, fn () => $svc->guardar(['alta' => ['horas_respuesta' => '1', 'horas_resolucion' => '4']], usuario('SUP', 'supervisor')),
        'solo el Admin TI');
}

function test_semaforo_de_respuesta_y_resolucion(): void
{
    $sol = usuario('U1');
    $svc = new SlaService(test_pdo());
    $pdo = test_pdo();
    $a = ticket_hace($sol, null, 'media', '1 HOUR');
    $b = ticket_hace($sol, null, 'media', '200 MINUTE');
    $c = ticket_hace($sol, null, 'media', '5 HOUR');
    $d = ticket_hace($sol, null, 'media', '5 HOUR');
    $pdo->prepare('UPDATE tickets SET atendido_en = creado_en + INTERVAL 1 HOUR WHERE id = ?')->execute([$d]);
    $t = fn (int $id): array => $pdo->query("SELECT t.*, es.es_cierre FROM tickets t JOIN estados es ON es.id = t.estado_id WHERE t.id = {$id}")->fetch();
    assert_eq($svc->estado($t($a))['respuesta'], 'en_tiempo', '1 h de 4: en tiempo');
    assert_eq($svc->estado($t($b))['respuesta'], 'por_vencer', '3 h 20 min de 4 (más del 75 %): por vencer');
    assert_eq($svc->estado($t($c))['respuesta'], 'vencido', '5 h sin atender: vencido');
    $ed = $svc->estado($t($d));
    assert_eq([$ed['respuesta'], $ed['resolucion']], ['cumplido', 'en_tiempo'], 'atendido a la hora: respuesta cumplida; resolución aún en tiempo');
}

function test_primera_atencion_se_registra_con_la_accion_de_ti(): void
{
    $sol = usuario('U1');
    $ag = usuario('AG', 'agente_ti');
    $svc = new TicketService(test_pdo());
    $id = $svc->create($sol, ticket_form())['id'];
    $svc->addComment($svc->find($id), $sol, 'Sigue sin encender', false);
    assert_eq(scalar('SELECT atendido_en FROM tickets WHERE id = ?', [$id]), null, 'el comentario del trabajador no cuenta como atención');
    $svc->addComment($svc->find($id), $ag, 'Voy en camino', false);
    $primera = scalar('SELECT atendido_en FROM tickets WHERE id = ?', [$id]);
    assert_true($primera !== null, 'la respuesta de TI marca la primera atención');
    test_pdo()->prepare('UPDATE tickets SET atendido_en = atendido_en - INTERVAL 1 HOUR WHERE id = ?')->execute([$id]);
    $svc->addComment($svc->find($id), $ag, 'Ya revisé', false);
    assert_true(scalar('SELECT atendido_en FROM tickets WHERE id = ?', [$id]) < $primera, 'no se sobrescribe la primera');
}

function test_escalamiento_por_sla_vencido_una_sola_vez(): void
{
    $e = ejemplos_usuario();
    $zonas = new ZonaService(test_pdo());
    $norte = $zonas->guardarZona(null, 'Zona Norte');
    $zonas->asignarZona($e['laguna'], $norte);
    $admin = usuario('ADM', 'admin_ti', 'adm@pemex.com');
    $coord = usuario('COORD', 'coordinador', 'coord@pemex.com');
    (new \App\Services\AsignacionService(test_pdo()))->asignarCoordinador($admin, $coord['id'], $norte, 'titular', null, null);
    $sup = usuario('SUP', 'supervisor', 'sup@pemex.com');
    asignar_supervisor($sup['id'], $e['laguna']);
    $sol = usuario('U1');
    $sinRespuesta = ticket_hace($sol, $e['laguna'], 'media', '5 HOUR');
    $sinResolver = ticket_hace($sol, $e['laguna'], 'media', '30 HOUR');
    test_pdo()->prepare('UPDATE tickets SET atendido_en = creado_en + INTERVAL 1 HOUR WHERE id = ?')->execute([$sinResolver]);

    $GLOBALS['__app_config']['mail']['driver'] = 'smtp';
    Notifier::reiniciarCola();
    try {
        $n = (new SlaService(test_pdo()))->escalar();
        $dest = [];
        foreach (Notifier::cola() as $m) {
            $dest[] = $m['to'];
        }
        sort($dest);
        assert_eq($n, 2, 'dos escalamientos');
        assert_eq($dest, ['coord@pemex.com', 'sup@pemex.com'], 'respuesta vencida → supervisor; resolución vencida → coordinador');
        Notifier::reiniciarCola();
        assert_eq((new SlaService(test_pdo()))->escalar(), 0, 'la segunda revisión no repite');
        assert_eq(Notifier::cola(), [], 'sin correos repetidos');
        assert_true($sinRespuesta > 0, 'ticket sin respuesta existe');
    } finally {
        Notifier::reiniciarCola();
        $GLOBALS['__app_config']['mail']['driver'] = 'off';
    }
}
