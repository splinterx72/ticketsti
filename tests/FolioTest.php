<?php

declare(strict_types=1);

use App\Core\Folio;
use App\Services\TareaService;
use App\Services\TicketService;

function test_folio_de_ticket_es_aleatorio_de_seis_digitos(): void
{
    $u = usuario('U1');
    $svc = new TicketService(test_pdo());
    $folios = [];
    for ($i = 0; $i < 5; $i++) {
        $folios[] = $svc->create($u, ticket_form(['titulo' => "Prueba {$i}"]))['folio'];
    }
    foreach ($folios as $f) {
        assert_true(preg_match('/^TK-' . date('Y') . '-[1-9]\d{5}$/', $f) === 1, "formato TK-AAAA-NNNNNN ({$f})");
    }
    assert_true($folios[0] !== sprintf('TK-%s-%06d', date('Y'), 1), 'ya no es consecutivo');
    assert_eq(count(array_unique($folios)), 5, 'sin repetidos');
    assert_eq(scalar('SELECT COUNT(*) FROM tickets WHERE folio LIKE ?', ['TMP-%']), 0, 'no quedan folios temporales');
}

function test_folio_repetido_se_vuelve_a_sortear(): void
{
    $u = usuario('U1');
    $id = insert('tickets', ['folio' => 'TMP-x', 'titulo' => 'a', 'descripcion' => 'b', 'usuario_solicitante_id' => $u['id'], 'departamento_id' => 1, 'estado_id' => 1]);
    insert('tickets', ['folio' => 'TK-' . date('Y') . '-111111', 'titulo' => 'a', 'descripcion' => 'b', 'usuario_solicitante_id' => $u['id'], 'departamento_id' => 1, 'estado_id' => 1]);
    $numeros = [111111, 222222];
    $folio = Folio::asignar(test_pdo(), 'tickets', 'TK', $id, static function () use (&$numeros): int {
        return (int) array_shift($numeros);
    });
    assert_eq($folio, 'TK-' . date('Y') . '-222222', 'si el número ya existe, se sortea otro');
    assert_eq(scalar('SELECT folio FROM tickets WHERE id = ?', [$id]), $folio, 'el folio quedó guardado');
}

function test_folio_de_tarea_es_aleatorio(): void
{
    $t = equipo_tareas();
    $id = (new TareaService(test_pdo()))->crear($t['sup'], tarea_form([$t['a1']]), []);
    assert_true(preg_match('/^TA-' . date('Y') . '-[1-9]\d{5}$/', (string) scalar('SELECT folio FROM tareas WHERE id = ?', [$id])) === 1, 'TA-AAAA-NNNNNN aleatorio');
}
