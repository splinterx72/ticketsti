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


/** Equipo del GECA asignado a un usuario, con su inmueble GECA. */
function equipo_geca(array $usuario, string $serie, string $inmueble = 'Torre Ejecutiva'): int
{
    $ub = (int) scalar('SELECT id FROM ubicaciones WHERE inmueble = ?', [$inmueble]);
    if ($ub === 0) {
        $ub = insert('ubicaciones', ['eps' => 'PEP', 'subdireccion' => 'Sub', 'gerencia' => 'Ger', 'zona' => '', 'sector' => '', 'inmueble' => $inmueble]);
    }
    return insert('equipos', [
        'serie' => $serie, 'usuario_id' => $usuario['id'], 'ubicacion_geca_id' => $ub, 'ubicacion_real_id' => $ub,
        'es_principal' => 1, 'marca' => 'Dell', 'modelo' => 'Optiplex', 'serie_monitor1' => 'MON-' . $serie,
    ]);
}

/** Datos mínimos válidos de un ticket nuevo. @return array<string, string> */
function ticket_form(array $extra = []): array
{
    return $extra + [
        'tipo_ticket' => 'incidencia', 'prioridad' => 'media', 'titulo' => 'No enciende el equipo',
        'descripcion' => 'Desde esta mañana el equipo no enciende.', 'equipo_id' => '0', 'componente_afectado' => 'general',
        'hostname_reportado' => '',
    ];
}

function nivel_id(string $nombre): int
{
    return (int) scalar('SELECT id FROM niveles WHERE nombre = ?', [$nombre]);
}

/** Crea los dos ejemplos reales del usuario. @return array{km: int, laguna: int, holkanaab: int, carga: int} */
function ejemplos_usuario(): array
{
    $svc = new \App\Services\UbicacionFisicaService(test_pdo());
    $km = $svc->guardarInstalacion(null, 'Zona Industrial Km 4.5');
    $laguna = $svc->guardarInstalacion(null, 'Zona Industrial Laguna Azul');
    $holkanaab = $svc->guardarUbicacion($km, null, [
        'nombre' => 'Edificio Holkanaab', 'tipo_id' => (string) scalar("SELECT id FROM ubicacion_tipos WHERE nombre = 'Edificio'"),
        'indicaciones' => '', 'niveles' => [nivel_id('PB'), nivel_id('1'), nivel_id('2')], 'alas' => ['norte', 'sur'],
    ]);
    $carga = $svc->guardarUbicacion($laguna, null, [
        'nombre' => 'Area de Carga', 'tipo_id' => '', 'niveles' => [], 'alas' => [],
        'indicaciones' => 'Es necesario contar con botas de trabajo y todo el equipo de seguridad',
    ]);
    return ['km' => $km, 'laguna' => $laguna, 'holkanaab' => $holkanaab, 'carga' => $carga];
}
