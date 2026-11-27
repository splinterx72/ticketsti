<?php

declare(strict_types=1);

namespace App\Services;

use App\Core\Roles;
use App\Core\ValidationException;
use DateTimeImmutable;
use PDO;

/**
 * Avisos en la página de inicio (spec 7.4): con vigencia, publicados por
 * supervisores (y el admin), para todos o solo para ciertas instalaciones.
 */
final class AvisoService
{
    public const IMPORTANCIAS = [
        'urgente'     => ['Urgente', 'danger', 'bi-exclamation-octagon'],
        'importante'  => ['Importante', 'warning', 'bi-exclamation-triangle'],
        'informativo' => ['Informativo', 'info', 'bi-info-circle'],
    ];

    /** Imagen que dejó de usarse en el último guardar() (el controlador borra el archivo). */
    private ?string $imagenReemplazada = null;

    public function __construct(private readonly PDO $pdo)
    {
    }

    public function ultimaImagenReemplazada(): ?string
    {
        return $this->imagenReemplazada;
    }

    /**
     * Ruta de la imagen del aviso si este usuario puede verla ahora; null si no.
     * Sin sesión (pantalla de entrada): solo avisos para "todos" y vigentes.
     * Con sesión: los avisos vigentes que le tocan, y los que puede editar
     * (vista previa e historial).
     *
     * @param array<string, mixed>|null $user
     */
    public function imagenVisible(int $id, ?array $user): ?string
    {
        $a = $this->find($id);
        if ($a === null || ($a['imagen'] ?? null) === null) {
            return null;
        }
        if ($user !== null && $this->puedeEditar($user, $a)) {
            return (string) $a['imagen'];
        }
        foreach ($this->vigentesPara($user) as $v) {
            if ((int) $v['id'] === $id) {
                return (string) $a['imagen'];
            }
        }
        return null;
    }

    /**
     * Avisos vigentes para un usuario (null = pantalla de entrada: solo "todos").
     *
     * @param array<string, mixed>|null $user
     * @return list<array<string, mixed>>
     */
    public function vigentesPara(?array $user, ?string $ahora = null): array
    {
        $ahora ??= date('Y-m-d H:i:s');
        $instalaciones = $user === null ? [] : $this->instalacionesDe($user);

        $sql = "SELECT DISTINCT a.id, a.titulo, a.mensaje, a.importancia, a.inicia_en, a.termina_en, a.alcance, a.imagen,
                       a.requiere_confirmacion, l.leido_en, u.nombre AS autor
                  FROM avisos a
                  LEFT JOIN usuarios u ON u.id = a.autor_id
                  LEFT JOIN aviso_instalaciones ai ON ai.aviso_id = a.id
                  LEFT JOIN aviso_lecturas l ON l.aviso_id = a.id AND l.usuario_id = ?
                 WHERE a.inicia_en <= ? AND a.termina_en > ?
                   AND (a.alcance = 'todos'";
        $params = [$user === null ? 0 : (int) $user['id'], $ahora, $ahora];
        if ($instalaciones !== []) {
            $sql .= ' OR ai.instalacion_id IN (' . implode(',', array_fill(0, count($instalaciones), '?')) . ')';
            $params = array_merge($params, $instalaciones);
        }
        $sql .= ") ORDER BY (a.requiere_confirmacion = 1 AND l.leido_en IS NULL) DESC,
                  FIELD(a.importancia, 'urgente', 'importante', 'informativo'), a.inicia_en DESC, a.id DESC";

        $stmt = $this->pdo->prepare($sql);
        $stmt->execute($params);
        return $stmt->fetchAll();
    }

    /** "Enterado": registra que el usuario leyó un aviso que le toca. @param array<string, mixed> $user */
    public function marcarLeido(int $avisoId, array $user): void
    {
        if (!in_array($avisoId, array_map('intval', array_column($this->vigentesPara($user), 'id')), true)) {
            throw new ValidationException(['Ese aviso no está vigente para ti.']);
        }
        $this->pdo->prepare('INSERT IGNORE INTO aviso_lecturas (aviso_id, usuario_id, leido_en) VALUES (?, ?, NOW())')
            ->execute([$avisoId, (int) $user['id']]);
    }

    /** Avisos vigentes que piden confirmación y el usuario no ha leído. @param array<string, mixed> $user */
    public function pendientesDe(array $user): int
    {
        return count(array_filter(
            $this->vigentesPara($user),
            static fn (array $a): bool => (int) $a['requiere_confirmacion'] === 1 && $a['leido_en'] === null
        ));
    }

    /**
     * Reporte de lectura: a quién va dirigido y quién lo ha confirmado.
     * Destinatarios: "todos" = cuentas activas; por instalación = trabajadores
     * cuya ubicación habitual está ahí + TI asignado (agente, supervisor o
     * coordinador de la zona) vigente.
     *
     * @return array{total: int, leidos: int, faltan: list<array{id: int, nombre: string, ficha: string}>}
     */
    public function lectura(int $avisoId): array
    {
        $a = $this->find($avisoId);
        if ($a === null) {
            return ['total' => 0, 'leidos' => 0, 'faltan' => []];
        }
        $hoy = date('Y-m-d');
        if ($a['alcance'] === 'todos' || $a['instalaciones'] === []) {
            $dest = $this->pdo->query('SELECT id FROM usuarios WHERE registrado = 1 AND activo = 1')->fetchAll(PDO::FETCH_COLUMN);
        } else {
            $ids = array_map('intval', $a['instalaciones']);
            $in = implode(',', $ids);
            $vig = "(x.desde IS NULL OR x.desde <= '{$hoy}') AND (x.hasta IS NULL OR x.hasta >= '{$hoy}')";
            $dest = $this->pdo->query(
                "SELECT u.id FROM usuarios u JOIN ubicaciones_fisicas uf ON uf.id = u.habitual_ubicacion_fisica_id
                  WHERE uf.instalacion_id IN ({$in}) AND u.registrado = 1 AND u.activo = 1
                 UNION SELECT x.agente_id FROM agente_instalaciones x WHERE x.instalacion_id IN ({$in}) AND {$vig}
                 UNION SELECT x.supervisor_id FROM supervisor_instalaciones x WHERE x.instalacion_id IN ({$in}) AND {$vig}
                 UNION SELECT x.coordinador_id FROM coordinador_zonas x JOIN instalaciones i ON i.zona_id = x.zona_id
                  WHERE i.id IN ({$in}) AND {$vig}"
            )->fetchAll(PDO::FETCH_COLUMN);
        }
        $dest = array_values(array_unique(array_map('intval', $dest)));
        if ($dest === []) {
            return ['total' => 0, 'leidos' => 0, 'faltan' => []];
        }
        $lista = implode(',', $dest);
        $leidos = (int) $this->pdo->query("SELECT COUNT(*) FROM aviso_lecturas WHERE aviso_id = {$avisoId} AND usuario_id IN ({$lista})")->fetchColumn();
        $faltan = $this->pdo->query(
            "SELECT u.id, u.nombre, u.ficha FROM usuarios u
              WHERE u.id IN ({$lista}) AND u.activo = 1
                AND NOT EXISTS (SELECT 1 FROM aviso_lecturas l WHERE l.aviso_id = {$avisoId} AND l.usuario_id = u.id)
              ORDER BY u.nombre LIMIT 200"
        )->fetchAll();
        return ['total' => count($dest), 'leidos' => $leidos, 'faltan' => $faltan];
    }

    /**
     * Instalaciones que le tocan a un usuario para efectos de avisos.
     * TI: las de su alcance. Trabajador: su ubicación habitual física; si no
     * tiene, la de su equipo principal (física real o equivalencia GECA).
     *
     * @param array<string, mixed> $user
     * @return list<int>
     */
    public function instalacionesDe(array $user): array
    {
        $id = (int) $user['id'];
        if (Roles::esTi($user['rol'] ?? null)) {
            $ids = (new Alcance($this->pdo))->instalacionesDe($id);
            $propia = $this->instalacionTrabajador($id);
            return array_values(array_unique($propia !== null ? [...$ids, $propia] : $ids));
        }
        $propia = $this->instalacionTrabajador($id);
        return $propia !== null ? [$propia] : [];
    }

    public function instalacionTrabajador(int $usuarioId): ?int
    {
        $stmt = $this->pdo->prepare(
            'SELECT uf.instalacion_id FROM usuarios u JOIN ubicaciones_fisicas uf ON uf.id = u.habitual_ubicacion_fisica_id WHERE u.id = ?'
        );
        $stmt->execute([$usuarioId]);
        $inst = $stmt->fetchColumn();
        if ($inst !== false) {
            return (int) $inst;
        }
        $stmt = $this->pdo->prepare(
            'SELECT uf.instalacion_id AS fisica, ug.inmueble
               FROM equipos e
               LEFT JOIN ubicaciones_fisicas uf ON uf.id = e.real_ubicacion_fisica_id
               LEFT JOIN ubicaciones ug ON ug.id = e.ubicacion_geca_id
              WHERE e.usuario_id = ?
              ORDER BY e.es_principal DESC, e.vigente_en_geca DESC, e.id
              LIMIT 1'
        );
        $stmt->execute([$usuarioId]);
        $eq = $stmt->fetch();
        if (!$eq) {
            return null;
        }
        if ($eq['fisica'] !== null) {
            return (int) $eq['fisica'];
        }
        return (new UbicacionFisicaService($this->pdo))->instalacionDeInmuebleGeca($eq['inmueble']);
    }

    /**
     * Avisos que el usuario puede administrar (el admin, todos; el supervisor, los suyos).
     *
     * @param array<string, mixed> $user
     * @return list<array<string, mixed>>
     */
    public function administrables(array $user): array
    {
        $sql = "SELECT a.*, u.nombre AS autor,
                       (SELECT GROUP_CONCAT(i.nombre ORDER BY i.nombre SEPARATOR ', ')
                          FROM aviso_instalaciones ai JOIN instalaciones i ON i.id = ai.instalacion_id
                         WHERE ai.aviso_id = a.id) AS instalaciones
                  FROM avisos a LEFT JOIN usuarios u ON u.id = a.autor_id";
        $params = [];
        if (($user['rol'] ?? '') !== 'admin_ti') {
            $sql .= ' WHERE a.autor_id = ?';
            $params[] = (int) $user['id'];
        }
        $sql .= ' ORDER BY (a.termina_en > NOW()) DESC, a.inicia_en DESC LIMIT 100';
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute($params);
        return $stmt->fetchAll();
    }

    /** @return array<string, mixed>|null */
    public function find(int $id): ?array
    {
        $stmt = $this->pdo->prepare('SELECT * FROM avisos WHERE id = ?');
        $stmt->execute([$id]);
        $a = $stmt->fetch();
        if (!$a) {
            return null;
        }
        $ins = $this->pdo->prepare('SELECT instalacion_id FROM aviso_instalaciones WHERE aviso_id = ?');
        $ins->execute([$id]);
        $a['instalaciones'] = array_map('intval', $ins->fetchAll(PDO::FETCH_COLUMN));
        return $a;
    }

    /** @param array<string, mixed> $user */
    public function puedeEditar(array $user, array $aviso): bool
    {
        return ($user['rol'] ?? '') === 'admin_ti' || (int) $aviso['autor_id'] === (int) $user['id'];
    }

    /**
     * @param array<string, mixed> $in titulo, mensaje, importancia, inicia_en, termina_en, alcance, instalaciones[],
     *                                 imagen (ruta nueva ya guardada, opcional), quitar_imagen
     * @param array<string, mixed> $autor
     */
    public function guardar(?int $id, array $in, array $autor): int
    {
        if (!in_array($autor['rol'] ?? '', Roles::SUPERVISION, true)) {
            throw new ValidationException(['Solo los supervisores publican avisos.']);
        }
        $titulo = trim((string) preg_replace('/\s+/u', ' ', (string) ($in['titulo'] ?? '')));
        $mensaje = trim(str_replace(["\r\n", "\r"], "\n", (string) ($in['mensaje'] ?? '')));
        $importancia = (string) ($in['importancia'] ?? 'informativo');
        $alcance = (string) ($in['alcance'] ?? 'todos');
        $instalaciones = array_values(array_unique(array_filter(array_map('intval', (array) ($in['instalaciones'] ?? [])))));
        $inicia = self::fecha((string) ($in['inicia_en'] ?? ''));
        $termina = self::fecha((string) ($in['termina_en'] ?? ''));

        $errors = [];
        if (mb_strlen($titulo) < 3 || mb_strlen($titulo) > 150) {
            $errors[] = 'El título debe tener entre 3 y 150 caracteres.';
        }
        if (mb_strlen($mensaje) < 3 || mb_strlen($mensaje) > 3000) {
            $errors[] = 'El mensaje debe tener entre 3 y 3000 caracteres.';
        }
        if (!array_key_exists($importancia, self::IMPORTANCIAS)) {
            $errors[] = 'Importancia inválida.';
        }
        if ($inicia === null || $termina === null) {
            $errors[] = 'Captura la fecha y hora de inicio y de fin.';
        } elseif ($termina <= $inicia) {
            $errors[] = 'La fecha de término debe ser posterior a la de inicio.';
        }
        if (!in_array($alcance, ['todos', 'instalaciones'], true)) {
            $errors[] = 'Alcance inválido.';
        } elseif ($alcance === 'instalaciones') {
            $permitidas = (new Alcance($this->pdo))->instalacionesSupervisadas((int) $autor['id']);
            if ($instalaciones === []) {
                $errors[] = 'Elige al menos una de tus instalaciones.';
            } elseif (array_diff($instalaciones, $permitidas) !== []) {
                $errors[] = 'Solo puedes dirigir avisos a tus propias instalaciones.';
            }
        }
        if ($errors !== []) {
            throw new ValidationException($errors);
        }

        $requiere = !empty($in['requiere_confirmacion']) ? 1 : 0;
        $nueva = isset($in['imagen']) && (string) $in['imagen'] !== '' ? (string) $in['imagen'] : null;
        $anterior = $id === null ? null : ($this->find($id)['imagen'] ?? null);
        $quitar = !empty($in['quitar_imagen']);
        $imagen = $nueva ?? ($quitar ? null : $anterior);
        $this->imagenReemplazada = $anterior !== null && $anterior !== $imagen ? $anterior : null;

        $this->pdo->beginTransaction();
        try {
            if ($id === null) {
                $this->pdo->prepare(
                    'INSERT INTO avisos (titulo, mensaje, importancia, inicia_en, termina_en, alcance, imagen, requiere_confirmacion, autor_id)
                     VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)'
                )->execute([$titulo, $mensaje, $importancia, $inicia, $termina, $alcance, $imagen, $requiere, (int) $autor['id']]);
                $id = (int) $this->pdo->lastInsertId();
            } else {
                $this->pdo->prepare(
                    'UPDATE avisos SET titulo = ?, mensaje = ?, importancia = ?, inicia_en = ?, termina_en = ?, alcance = ?, imagen = ?,
                            requiere_confirmacion = ? WHERE id = ?'
                )->execute([$titulo, $mensaje, $importancia, $inicia, $termina, $alcance, $imagen, $requiere, $id]);
                $this->pdo->prepare('DELETE FROM aviso_instalaciones WHERE aviso_id = ?')->execute([$id]);
            }
            if ($alcance === 'instalaciones') {
                $ins = $this->pdo->prepare('INSERT INTO aviso_instalaciones (aviso_id, instalacion_id) VALUES (?, ?)');
                foreach ($instalaciones as $i) {
                    $ins->execute([$id, $i]);
                }
            }
            $this->pdo->commit();
        } catch (\Throwable $e) {
            $this->pdo->rollBack();
            $this->imagenReemplazada = null;
            throw $e;
        }
        return $id;
    }

    /** Termina un aviso ya (no lo borra: queda en el historial). */
    public function finalizar(int $id): void
    {
        $this->pdo->prepare('UPDATE avisos SET termina_en = LEAST(termina_en, NOW()) WHERE id = ?')->execute([$id]);
    }

    private static function fecha(string $v): ?string
    {
        $v = trim($v);
        foreach (['Y-m-d\TH:i', 'Y-m-d H:i', 'Y-m-d H:i:s'] as $f) {
            $d = DateTimeImmutable::createFromFormat('!' . $f, $v);
            if ($d !== false && $d->format($f) === $v) {
                return $d->format('Y-m-d H:i:s');
            }
        }
        return null;
    }
}
