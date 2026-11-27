<?php

declare(strict_types=1);

use App\Services\ArchivoService;
use App\Services\TicketService;

function archivo_ticket(string $nombre = 'error.png'): array
{
    $dir = sys_get_temp_dir() . '/mesa_tickets_' . getmypid();
    $img = imagecreatetruecolor(20, 20);
    $tmp = tempnam(sys_get_temp_dir(), 'img');
    imagepng($img, $tmp);
    return (new ArchivoService($dir, false))->guardar(['name' => $nombre, 'tmp_name' => $tmp, 'size' => (int) filesize($tmp), 'error' => UPLOAD_ERR_OK], 'adjuntos');
}

function test_ticket_y_comentarios_guardan_sus_adjuntos(): void
{
    $sol = usuario('U1');
    $ag = usuario('AG', 'agente_ti');
    $svc = new TicketService(test_pdo());
    $id = $svc->create($sol, ticket_form(), [archivo_ticket('pantalla azul.png')])['id'];
    $svc->addComment($svc->find($id), $sol, 'Otra foto', false, [archivo_ticket('foto2.png')]);
    $svc->addComment($svc->find($id), $ag, 'Nota para TI con evidencia', true, [archivo_ticket('interno.png')]);

    $todos = $svc->archivosDe($id, true);
    assert_eq(array_column($todos, 'nombre_original'), ['pantalla azul.png', 'foto2.png', 'interno.png'], 'tres adjuntos en orden');
    assert_eq(array_map(static fn ($a) => $a['comentario_id'] === null, $todos), [true, false, false], 'el primero es del ticket; los demás de comentarios');
    assert_eq(array_column($svc->archivosDe($id, false), 'nombre_original'), ['pantalla azul.png', 'foto2.png'], 'el trabajador no ve los adjuntos de notas internas');
    assert_true(!str_contains((string) $todos[0]['ruta'], 'pantalla'), 'el nombre guardado no usa el original');
    assert_eq((int) $svc->archivo((int) $todos[2]['id'])['interno'], 1, 'adjunto marcado como interno');
}
