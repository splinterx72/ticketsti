<?php

declare(strict_types=1);

namespace App\Services;

use PDO;

/**
 * Ficha del trabajador para el personal de TI: datos del GECA (aunque no haya
 * activado su cuenta), dónde está, equipos a su cargo con su instalación,
 * equipos que tuvo antes y sus tickets dentro del alcance de quien consulta.
 */
final class TrabajadorService
{
    /**
     * Instalación de un equipo (alias e = equipos): primero su ubicación física
     * real; si no tiene, la equivalencia del inmueble GECA. NULL = sin instalación.
     */
    public const SQL_INSTALACION_EQUIPO =
        'COALESCE(
            (SELECT uf.instalacion_id FROM ubicaciones_fisicas uf WHERE uf.id = e.real_ubicacion_fisica_id),
            (SELECT gi.instalacion_id FROM ubicaciones ugx JOIN geca_inmueble_instalacion gi ON gi.inmueble = ugx.inmueble
              WHERE ugx.id = e.ubicacion_geca_id)
        )';

    public function __construct(private readonly PDO $pdo)
    {
    }

    /**
     * @param array<string, mixed> $viewer quien consulta (para el alcance de los tickets)
     * @return array{usuario: array<string, mixed>, ubicacion_fisica: ?string, ubicacion_geca: ?array<string, mixed>,
     *               equipos: list<array<string, mixed>>, anteriores: list<array<string, mixed>>, tickets: list<array<string, mixed>>}|null
     */
    public function ficha(int $usuarioId, array $viewer): ?array
    {
        $stmt = $this->pdo->prepare(
            'SELECT id, ficha, nombre, jefe_ficha, jefe_nombre, email, telefono_ext, celular, rol, tipo_cuenta, registrado, activo,
                    email_verificado_en, habitual_ubicacion_fisica_id, habitual_nivel_id, habitual_ala, habitual_referencia, creado_en
               FROM usuarios WHERE id = ?'
        );
        $stmt->execute([$usuarioId]);
        $u = $stmt->fetch();
        if (!$u) {
            return null;
        }

        $fisicas = new UbicacionFisicaService($this->pdo);
        $ubicacionFisica = $u['habitual_ubicacion_fisica_id'] !== null
            ? $fisicas->etiqueta((int) $u['habitual_ubicacion_fisica_id'], $u['habitual_nivel_id'] !== null ? (int) $u['habitual_nivel_id'] : null, $u['habitual_ala'])
            : null;

        $equipos = $this->pdo->prepare(
            'SELECT e.id, e.serie, e.tipo_componente, e.marca, e.modelo, e.es_principal, e.vigente_en_geca, e.estado_resguardo,
                    e.folio_resguardo, e.fecha_resguardo, e.serie_monitor1, e.serie_monitor2, e.ubicacion_referencia,
                    e.entidad_federativa, e.municipio, e.real_ubicacion_fisica_id, e.real_nivel_id, e.real_ala,
                    ug.eps, ug.subdireccion, ug.gerencia, ug.inmueble,
                    i.id AS instalacion_id, i.nombre AS instalacion,
                    EXISTS (SELECT 1 FROM ubicaciones_fisicas uf JOIN geca_inmueble_instalacion gi ON gi.inmueble = ug.inmueble
                             WHERE uf.id = e.real_ubicacion_fisica_id AND uf.instalacion_id <> gi.instalacion_id) AS discrepancia,
                    (SELECT COUNT(*) FROM garantias g WHERE g.equipo_id = e.id AND g.estado <> \'vencida\') AS garantias_activas
               FROM equipos e
               LEFT JOIN ubicaciones ug ON ug.id = e.ubicacion_geca_id
               LEFT JOIN instalaciones i ON i.id = ' . self::SQL_INSTALACION_EQUIPO . '
              WHERE e.usuario_id = ?
              ORDER BY e.vigente_en_geca DESC, e.es_principal DESC,
                       (e.tipo_componente IS NULL OR e.tipo_componente IN (\'' . implode("', '", GecaSyncService::COMPUTADORAS) . '\')) DESC,
                       e.serie'
        );
        $equipos->execute([$usuarioId]);
        $lista = $equipos->fetchAll();
        foreach ($lista as &$eq) {
            $eq['ubicacion_real'] = $eq['real_ubicacion_fisica_id'] !== null
                ? $fisicas->etiqueta((int) $eq['real_ubicacion_fisica_id'], $eq['real_nivel_id'] !== null ? (int) $eq['real_nivel_id'] : null, $eq['real_ala'])
                : null;
        }
        unset($eq);

        $geca = null;
        foreach ($lista as $eq) {
            if ($eq['inmueble'] !== null) {
                $geca = [
                    'inmueble' => $eq['inmueble'], 'gerencia' => $eq['gerencia'], 'subdireccion' => $eq['subdireccion'], 'eps' => $eq['eps'],
                    'referencia' => $eq['ubicacion_referencia'],
                    'entidad' => trim(implode(' / ', array_filter([$eq['entidad_federativa'], $eq['municipio']]))) ?: null,
                ];
                break;
            }
        }

        $anteriores = $this->pdo->prepare(
            'SELECT e.id, e.serie, e.tipo_componente, e.marca, e.modelo, e.pr_folio, e.pr_fecha, e.pr_estado_resguardo,
                    u.nombre AS actual_nombre, u.ficha AS actual_ficha
               FROM equipos e LEFT JOIN usuarios u ON u.id = e.usuario_id
              WHERE e.pr_ficha = ? AND (e.usuario_id IS NULL OR e.usuario_id <> ?)
              ORDER BY e.pr_fecha DESC LIMIT 50'
        );
        $anteriores->execute([(string) $u['ficha'], $usuarioId]);

        [$filtro, $params] = (new Alcance($this->pdo))->filtroTicketsSql($viewer, 't');
        $tickets = $this->pdo->prepare(
            "SELECT t.id, t.folio, t.titulo, t.tipo_ticket, t.prioridad, t.creado_en, es.nombre AS estado_nombre, es.color_hex AS estado_color,
                    i.nombre AS instalacion
               FROM tickets t
               JOIN estados es ON es.id = t.estado_id
               LEFT JOIN instalaciones i ON i.id = t.instalacion_id
              WHERE t.usuario_solicitante_id = ? AND {$filtro}
              ORDER BY t.id DESC LIMIT 30"
        );
        $tickets->execute(array_merge([$usuarioId], $params));

        return [
            'usuario'          => $u,
            'ubicacion_fisica' => $ubicacionFisica,
            'ubicacion_geca'   => $geca,
            'equipos'          => $lista,
            'anteriores'       => $anteriores->fetchAll(),
            'tickets'          => $tickets->fetchAll(),
        ];
    }
}
