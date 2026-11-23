<?php

declare(strict_types=1);

namespace App\Services;

use App\Core\ValidationException;
use PDO;

/**
 * Ubicaciones físicas de la app (spec 7.1): Instalación → Ubicación → Nivel → Ala.
 *
 * Independientes del GECA. La plantilla general (tipos y niveles) la mantiene
 * el admin TI; cada supervisor da de alta las ubicaciones de sus instalaciones
 * y habilita qué niveles y alas aplican. Sin niveles habilitados el nivel es
 * "Único"; sin alas, "N/A".
 */
final class UbicacionFisicaService
{
    public const ALAS = ['na' => 'N/A', 'norte' => 'Norte', 'sur' => 'Sur', 'oriente' => 'Oriente', 'poniente' => 'Poniente'];
    public const NIVEL_UNICO = 'Único';

    public function __construct(private readonly PDO $pdo)
    {
    }

    // ------------------------------------------------------------------
    //  Lectura
    // ------------------------------------------------------------------

    /**
     * Árbol para los selectores en cascada.
     *
     * @param list<int>|null $instalacionIds null = todas las activas
     * @return list<array{id: int, nombre: string, ubicaciones: list<array{id: int, nombre: string, tipo: ?string, indicaciones: ?string, niveles: list<array{id: int, nombre: string}>, alas: list<string>}>}>
     */
    public function catalogo(?array $instalacionIds = null): array
    {
        $where = 'i.activo = 1';
        $params = [];
        if ($instalacionIds !== null) {
            if ($instalacionIds === []) {
                return [];
            }
            $where .= ' AND i.id IN (' . implode(',', array_fill(0, count($instalacionIds), '?')) . ')';
            $params = array_values(array_map('intval', $instalacionIds));
        }
        $stmt = $this->pdo->prepare(
            "SELECT i.id AS inst_id, i.nombre AS inst_nombre, u.id, u.nombre, u.indicaciones, t.nombre AS tipo
               FROM instalaciones i
               LEFT JOIN ubicaciones_fisicas u ON u.instalacion_id = i.id AND u.activo = 1
               LEFT JOIN ubicacion_tipos t ON t.id = u.tipo_id
              WHERE {$where}
              ORDER BY i.nombre, u.nombre"
        );
        $stmt->execute($params);

        $niveles = $this->nivelesHabilitados();
        $alas = $this->alasHabilitadas();
        $unico = ['id' => $this->nivelUnicoId(), 'nombre' => self::NIVEL_UNICO];

        $tree = [];
        foreach ($stmt->fetchAll() as $r) {
            $iid = (int) $r['inst_id'];
            $tree[$iid] ??= ['id' => $iid, 'nombre' => (string) $r['inst_nombre'], 'ubicaciones' => []];
            if ($r['id'] === null) {
                continue;
            }
            $uid = (int) $r['id'];
            $tree[$iid]['ubicaciones'][] = [
                'id'           => $uid,
                'nombre'       => (string) $r['nombre'],
                'tipo'         => $r['tipo'],
                'indicaciones' => $r['indicaciones'] !== null && trim((string) $r['indicaciones']) !== '' ? (string) $r['indicaciones'] : null,
                'niveles'      => $niveles[$uid] ?? [$unico],
                'alas'         => $alas[$uid] ?? ['na'],
            ];
        }
        return array_values($tree);
    }

    public function hayCatalogo(): bool
    {
        return (bool) $this->pdo->query(
            'SELECT 1 FROM ubicaciones_fisicas u JOIN instalaciones i ON i.id = u.instalacion_id
              WHERE u.activo = 1 AND i.activo = 1 LIMIT 1'
        )->fetchColumn();
    }

    /** @return array<string, mixed>|null */
    public function ubicacion(int $id): ?array
    {
        $stmt = $this->pdo->prepare(
            'SELECT u.*, i.nombre AS instalacion_nombre FROM ubicaciones_fisicas u
               JOIN instalaciones i ON i.id = u.instalacion_id WHERE u.id = ?'
        );
        $stmt->execute([$id]);
        $u = $stmt->fetch();
        if (!$u) {
            return null;
        }
        $u['niveles'] = array_column($this->nivelesHabilitados()[$id] ?? [], 'id');
        $u['alas'] = array_values(array_filter($this->alasHabilitadas()[$id] ?? [], static fn (string $a): bool => $a !== 'na'));
        return $u;
    }

    /** "Zona Industrial Km 4.5 › Edificio Holkanaab › Nivel 1 › Ala Norte" */
    public function etiqueta(int $ubicacionFisicaId, ?int $nivelId, ?string $ala): string
    {
        $stmt = $this->pdo->prepare(
            'SELECT i.nombre AS instalacion, u.nombre AS ubicacion, n.nombre AS nivel
               FROM ubicaciones_fisicas u
               JOIN instalaciones i ON i.id = u.instalacion_id
               LEFT JOIN niveles n ON n.id = ?
              WHERE u.id = ?'
        );
        $stmt->execute([$nivelId, $ubicacionFisicaId]);
        $r = $stmt->fetch();
        if (!$r) {
            return '';
        }
        $nivel = $r['nivel'] ?? self::NIVEL_UNICO;
        return "{$r['instalacion']} › {$r['ubicacion']} › Nivel {$nivel} › Ala " . (self::ALAS[$ala ?? 'na'] ?? 'N/A');
    }

    public function instalacionDeUbicacion(int $ubicacionFisicaId): ?int
    {
        $stmt = $this->pdo->prepare('SELECT instalacion_id FROM ubicaciones_fisicas WHERE id = ?');
        $stmt->execute([$ubicacionFisicaId]);
        $v = $stmt->fetchColumn();
        return $v === false ? null : (int) $v;
    }

    public function instalacionDeInmuebleGeca(?string $inmueble): ?int
    {
        $inmueble = trim((string) $inmueble);
        if ($inmueble === '') {
            return null;
        }
        $stmt = $this->pdo->prepare('SELECT instalacion_id FROM geca_inmueble_instalacion WHERE inmueble = ?');
        $stmt->execute([$inmueble]);
        $v = $stmt->fetchColumn();
        return $v === false ? null : (int) $v;
    }

    /** @return list<array{id: int, nombre: string, activo: int, ubicaciones: int}> */
    public function instalaciones(bool $soloActivas = false): array
    {
        $rows = $this->pdo->query(
            'SELECT i.id, i.nombre, i.activo, COUNT(u.id) AS ubicaciones
               FROM instalaciones i LEFT JOIN ubicaciones_fisicas u ON u.instalacion_id = i.id AND u.activo = 1'
            . ($soloActivas ? ' WHERE i.activo = 1' : '') .
            ' GROUP BY i.id, i.nombre, i.activo ORDER BY i.nombre'
        )->fetchAll();
        return array_map(static fn (array $r): array => [
            'id' => (int) $r['id'], 'nombre' => (string) $r['nombre'], 'activo' => (int) $r['activo'], 'ubicaciones' => (int) $r['ubicaciones'],
        ], $rows);
    }

    /** @return list<array<string, mixed>> */
    public function ubicacionesDe(int $instalacionId): array
    {
        $stmt = $this->pdo->prepare(
            'SELECT u.id, u.nombre, u.indicaciones, u.activo, t.nombre AS tipo
               FROM ubicaciones_fisicas u LEFT JOIN ubicacion_tipos t ON t.id = u.tipo_id
              WHERE u.instalacion_id = ? ORDER BY u.activo DESC, u.nombre'
        );
        $stmt->execute([$instalacionId]);
        $niveles = $this->nivelesHabilitados();
        $alas = $this->alasHabilitadas();
        return array_map(static function (array $r) use ($niveles, $alas): array {
            $id = (int) $r['id'];
            $r['niveles'] = array_column($niveles[$id] ?? [], 'nombre');
            $r['alas'] = $alas[$id] ?? [];
            return $r;
        }, $stmt->fetchAll());
    }

    /** @return list<array{id: int, nombre: string}> */
    public function tipos(): array
    {
        return array_map(static fn (array $r): array => ['id' => (int) $r['id'], 'nombre' => (string) $r['nombre']],
            $this->pdo->query('SELECT id, nombre FROM ubicacion_tipos WHERE activo = 1 ORDER BY nombre')->fetchAll());
    }

    /** @return list<array{id: int, nombre: string}> */
    public function niveles(): array
    {
        return array_map(static fn (array $r): array => ['id' => (int) $r['id'], 'nombre' => (string) $r['nombre']],
            $this->pdo->query('SELECT id, nombre FROM niveles WHERE activo = 1 ORDER BY orden, nombre')->fetchAll());
    }

    /**
     * Inmuebles del GECA con su instalación equivalente (o null).
     *
     * @return list<array{inmueble: string, instalacion_id: ?int, equipos: int}>
     */
    public function inmueblesGeca(): array
    {
        $rows = $this->pdo->query(
            'SELECT ug.inmueble, gi.instalacion_id, COUNT(e.id) AS equipos
               FROM ubicaciones ug
               LEFT JOIN geca_inmueble_instalacion gi ON gi.inmueble = ug.inmueble
               LEFT JOIN equipos e ON e.ubicacion_geca_id = ug.id AND e.vigente_en_geca = 1
              WHERE ug.inmueble <> \'\'
              GROUP BY ug.inmueble, gi.instalacion_id
              ORDER BY (gi.instalacion_id IS NULL) DESC, ug.inmueble'
        )->fetchAll();
        return array_map(static fn (array $r): array => [
            'inmueble' => (string) $r['inmueble'],
            'instalacion_id' => $r['instalacion_id'] !== null ? (int) $r['instalacion_id'] : null,
            'equipos' => (int) $r['equipos'],
        ], $rows);
    }

    // ------------------------------------------------------------------
    //  Validación de lo capturado en formularios
    // ------------------------------------------------------------------

    /**
     * Lee "{prefijo}_ubicacion_fisica_id", "{prefijo}_nivel_id", "{prefijo}_ala",
     * "{prefijo}_referencia" y, opcional, "{prefijo}_instalacion_id".
     *
     * @param array<string, mixed> $in
     * @return array{instalacion_id: int, ubicacion_fisica_id: int, nivel_id: int, ala: string, referencia: ?string}
     */
    public function validar(array $in, string $prefijo): array
    {
        $ubId = (int) ($in["{$prefijo}_ubicacion_fisica_id"] ?? 0);
        $u = $ubId > 0 ? $this->ubicacionActiva($ubId) : null;
        if ($u === null) {
            throw new ValidationException(['Selecciona la instalación y la ubicación (edificio, nave, área…).']);
        }

        $errors = [];
        $instElegida = (int) ($in["{$prefijo}_instalacion_id"] ?? 0);
        if ($instElegida > 0 && $instElegida !== (int) $u['instalacion_id']) {
            $errors[] = 'La ubicación no pertenece a la instalación seleccionada.';
        }

        $habilitados = array_column($this->nivelesHabilitados()[$ubId] ?? [], 'id');
        $nivelId = (int) ($in["{$prefijo}_nivel_id"] ?? 0);
        if ($habilitados === []) {
            $nivelId = $this->nivelUnicoId();
        } elseif (!in_array($nivelId, $habilitados, true)) {
            $errors[] = 'Selecciona un nivel válido para esa ubicación.';
        }

        $alasOk = $this->alasHabilitadas()[$ubId] ?? ['na'];
        $ala = (string) ($in["{$prefijo}_ala"] ?? '');
        if ($alasOk === ['na']) {
            $ala = 'na';
        } elseif (!in_array($ala, $alasOk, true)) {
            $errors[] = 'Selecciona un ala válida para esa ubicación.';
        }

        $ref = trim((string) preg_replace('/\s+/u', ' ', (string) ($in["{$prefijo}_referencia"] ?? '')));
        if (mb_strlen($ref) > 255) {
            $errors[] = 'La referencia no puede exceder 255 caracteres.';
        }

        if ($errors !== []) {
            throw new ValidationException($errors);
        }
        return [
            'instalacion_id'      => (int) $u['instalacion_id'],
            'ubicacion_fisica_id' => $ubId,
            'nivel_id'            => $nivelId,
            'ala'                 => $ala,
            'referencia'          => $ref !== '' ? $ref : null,
        ];
    }

    // ------------------------------------------------------------------
    //  Altas y ediciones
    // ------------------------------------------------------------------

    public function guardarInstalacion(?int $id, string $nombre, bool $activo = true): int
    {
        $nombre = trim((string) preg_replace('/\s+/u', ' ', $nombre));
        if (mb_strlen($nombre) < 2 || mb_strlen($nombre) > 150) {
            throw new ValidationException(['El nombre de la instalación debe tener entre 2 y 150 caracteres.']);
        }
        $dup = $this->pdo->prepare('SELECT id FROM instalaciones WHERE nombre = ? AND id <> ?');
        $dup->execute([$nombre, (int) $id]);
        if ($dup->fetchColumn()) {
            throw new ValidationException(["Ya existe la instalación \"{$nombre}\"."]);
        }
        if ($id === null) {
            $this->pdo->prepare('INSERT INTO instalaciones (nombre, activo) VALUES (?, ?)')->execute([$nombre, $activo ? 1 : 0]);
            return (int) $this->pdo->lastInsertId();
        }
        $this->pdo->prepare('UPDATE instalaciones SET nombre = ?, activo = ? WHERE id = ?')->execute([$nombre, $activo ? 1 : 0, $id]);
        return $id;
    }

    /**
     * @param array<string, mixed> $in nombre, tipo_id, indicaciones, niveles (list<int>), alas (list<string>), activo
     */
    public function guardarUbicacion(int $instalacionId, ?int $id, array $in): int
    {
        $nombre = trim((string) preg_replace('/\s+/u', ' ', (string) ($in['nombre'] ?? '')));
        $tipoId = (int) ($in['tipo_id'] ?? 0);
        $indicaciones = trim(str_replace(["\r\n", "\r"], "\n", (string) ($in['indicaciones'] ?? '')));
        $niveles = array_values(array_unique(array_map('intval', (array) ($in['niveles'] ?? []))));
        $alas = array_values(array_unique(array_map('strval', (array) ($in['alas'] ?? []))));
        $activo = !isset($in['activo']) || (bool) $in['activo'];

        $errors = [];
        if (mb_strlen($nombre) < 2 || mb_strlen($nombre) > 150) {
            $errors[] = 'El nombre de la ubicación debe tener entre 2 y 150 caracteres.';
        }
        if (mb_strlen($indicaciones) > 2000) {
            $errors[] = 'Las indicaciones no pueden exceder 2000 caracteres.';
        }
        $validos = array_column($this->niveles(), 'id');
        foreach ($niveles as $n) {
            if (!in_array($n, $validos, true)) {
                $errors[] = 'Nivel inválido.';
                break;
            }
        }
        $unico = $this->nivelUnicoId();
        $niveles = array_values(array_filter($niveles, static fn (int $n): bool => $n !== $unico));
        foreach ($alas as $a) {
            if (!array_key_exists($a, self::ALAS) || $a === 'na') {
                $errors[] = 'Ala inválida.';
                break;
            }
        }
        if ($tipoId > 0 && !in_array($tipoId, array_column($this->tipos(), 'id'), true)) {
            $errors[] = 'Tipo de ubicación inválido.';
        }
        $dup = $this->pdo->prepare('SELECT id FROM ubicaciones_fisicas WHERE instalacion_id = ? AND nombre = ? AND id <> ?');
        $dup->execute([$instalacionId, $nombre, (int) $id]);
        if ($dup->fetchColumn()) {
            $errors[] = "Ya existe \"{$nombre}\" en esta instalación.";
        }
        if ($errors !== []) {
            throw new ValidationException($errors);
        }

        $this->pdo->beginTransaction();
        try {
            $params = [$tipoId > 0 ? $tipoId : null, $nombre, $indicaciones !== '' ? $indicaciones : null, $activo ? 1 : 0];
            if ($id === null) {
                $this->pdo->prepare(
                    'INSERT INTO ubicaciones_fisicas (instalacion_id, tipo_id, nombre, indicaciones, activo) VALUES (?, ?, ?, ?, ?)'
                )->execute([$instalacionId, ...$params]);
                $id = (int) $this->pdo->lastInsertId();
            } else {
                $this->pdo->prepare(
                    'UPDATE ubicaciones_fisicas SET tipo_id = ?, nombre = ?, indicaciones = ?, activo = ? WHERE id = ? AND instalacion_id = ?'
                )->execute([...$params, $id, $instalacionId]);
            }
            $this->pdo->prepare('DELETE FROM ubicacion_fisica_niveles WHERE ubicacion_fisica_id = ?')->execute([$id]);
            $this->pdo->prepare('DELETE FROM ubicacion_fisica_alas WHERE ubicacion_fisica_id = ?')->execute([$id]);
            $insN = $this->pdo->prepare('INSERT INTO ubicacion_fisica_niveles (ubicacion_fisica_id, nivel_id) VALUES (?, ?)');
            foreach ($niveles as $n) {
                $insN->execute([$id, $n]);
            }
            $insA = $this->pdo->prepare('INSERT INTO ubicacion_fisica_alas (ubicacion_fisica_id, ala) VALUES (?, ?)');
            foreach ($alas as $a) {
                $insA->execute([$id, $a]);
            }
            $this->pdo->commit();
        } catch (\Throwable $e) {
            $this->pdo->rollBack();
            throw $e;
        }
        return $id;
    }

    public function mapearInmueble(string $inmueble, ?int $instalacionId): void
    {
        $inmueble = trim($inmueble);
        if ($inmueble === '') {
            return;
        }
        if ($instalacionId === null || $instalacionId <= 0) {
            $this->pdo->prepare('DELETE FROM geca_inmueble_instalacion WHERE inmueble = ?')->execute([$inmueble]);
            return;
        }
        $this->pdo->prepare(
            'INSERT INTO geca_inmueble_instalacion (inmueble, instalacion_id) VALUES (?, ?)
             ON DUPLICATE KEY UPDATE instalacion_id = VALUES(instalacion_id)'
        )->execute([mb_substr($inmueble, 0, 150), $instalacionId]);
    }

    public function guardarTipo(string $nombre): void
    {
        $nombre = trim($nombre);
        if (mb_strlen($nombre) < 2 || mb_strlen($nombre) > 60) {
            throw new ValidationException(['El tipo debe tener entre 2 y 60 caracteres.']);
        }
        $this->pdo->prepare('INSERT INTO ubicacion_tipos (nombre) VALUES (?) ON DUPLICATE KEY UPDATE activo = 1')->execute([$nombre]);
    }

    public function guardarNivel(string $nombre): void
    {
        $nombre = trim($nombre);
        if (mb_strlen($nombre) < 1 || mb_strlen($nombre) > 40) {
            throw new ValidationException(['El nivel debe tener entre 1 y 40 caracteres.']);
        }
        $orden = (int) $this->pdo->query('SELECT COALESCE(MAX(orden), 0) + 1 FROM niveles')->fetchColumn();
        $this->pdo->prepare('INSERT INTO niveles (nombre, orden) VALUES (?, ?) ON DUPLICATE KEY UPDATE activo = 1')->execute([$nombre, $orden]);
    }

    // ------------------------------------------------------------------

    public function nivelUnicoId(): int
    {
        $stmt = $this->pdo->prepare('SELECT id FROM niveles WHERE nombre = ?');
        $stmt->execute([self::NIVEL_UNICO]);
        $id = $stmt->fetchColumn();
        if ($id === false) {
            $this->pdo->prepare('INSERT INTO niveles (nombre, orden) VALUES (?, 0)')->execute([self::NIVEL_UNICO]);
            return (int) $this->pdo->lastInsertId();
        }
        return (int) $id;
    }

    /** @return array<string, mixed>|null */
    private function ubicacionActiva(int $id): ?array
    {
        $stmt = $this->pdo->prepare(
            'SELECT u.id, u.instalacion_id FROM ubicaciones_fisicas u JOIN instalaciones i ON i.id = u.instalacion_id
              WHERE u.id = ? AND u.activo = 1 AND i.activo = 1'
        );
        $stmt->execute([$id]);
        return $stmt->fetch() ?: null;
    }

    /** @return array<int, list<array{id: int, nombre: string}>> */
    private function nivelesHabilitados(): array
    {
        $out = [];
        $rows = $this->pdo->query(
            'SELECT un.ubicacion_fisica_id, n.id, n.nombre FROM ubicacion_fisica_niveles un
               JOIN niveles n ON n.id = un.nivel_id ORDER BY n.orden, n.nombre'
        )->fetchAll();
        foreach ($rows as $r) {
            $out[(int) $r['ubicacion_fisica_id']][] = ['id' => (int) $r['id'], 'nombre' => (string) $r['nombre']];
        }
        return $out;
    }

    /** @return array<int, list<string>> */
    private function alasHabilitadas(): array
    {
        $out = [];
        $orden = array_keys(self::ALAS);
        foreach ($this->pdo->query('SELECT ubicacion_fisica_id, ala FROM ubicacion_fisica_alas')->fetchAll() as $r) {
            $out[(int) $r['ubicacion_fisica_id']][] = (string) $r['ala'];
        }
        foreach ($out as &$list) {
            usort($list, static fn (string $a, string $b): int => array_search($a, $orden, true) <=> array_search($b, $orden, true));
        }
        return $out;
    }
}
