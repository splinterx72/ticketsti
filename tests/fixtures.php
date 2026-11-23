<?php

declare(strict_types=1);

// Fábricas de datos de prueba compartidas.

/** Crea un usuario de prueba y devuelve el arreglo que usa Auth::user(). @return array<string, mixed> */
function usuario(string $ficha, string $rol = 'usuario', ?string $email = null): array
{
    $id = insert('usuarios', [
        'ficha' => $ficha, 'nombre' => "Persona {$ficha}", 'email' => $email ?? strtolower($ficha) . '@pemex.com',
        'rol' => $rol, 'registrado' => 1, 'activo' => 1,
    ]);
    return ['id' => $id, 'ficha' => $ficha, 'nombre' => "Persona {$ficha}", 'rol' => $rol, 'email' => $email ?? strtolower($ficha) . '@pemex.com'];
}

function instalacion(string $nombre): int
{
    return insert('instalaciones', ['nombre' => $nombre]);
}

function asignar_agente(int $agenteId, int $instalacionId, string $tipo = 'base', ?string $desde = null, ?string $hasta = null): void
{
    insert('agente_instalaciones', ['agente_id' => $agenteId, 'instalacion_id' => $instalacionId, 'tipo' => $tipo, 'desde' => $desde, 'hasta' => $hasta]);
}

function asignar_supervisor(int $supId, int $instalacionId, string $tipo = 'titular', ?string $desde = null, ?string $hasta = null): void
{
    insert('supervisor_instalaciones', ['supervisor_id' => $supId, 'instalacion_id' => $instalacionId, 'tipo' => $tipo, 'desde' => $desde, 'hasta' => $hasta]);
}

/** @return array<string, mixed> */
function ticket_de(array $solicitante, ?int $instalacionId, ?int $agenteId = null): array
{
    $id = insert('tickets', [
        'folio' => 'TK-T-' . bin2hex(random_bytes(4)), 'titulo' => 'Prueba', 'descripcion' => 'Prueba de alcance',
        'usuario_solicitante_id' => $solicitante['id'], 'departamento_id' => 1, 'estado_id' => 1,
        'instalacion_id' => $instalacionId, 'agente_asignado_id' => $agenteId,
    ]);
    return ['id' => $id, 'instalacion_id' => $instalacionId, 'agente_asignado_id' => $agenteId, 'usuario_solicitante_id' => $solicitante['id']];
}

