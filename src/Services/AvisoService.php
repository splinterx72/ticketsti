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

    public function __construct(private readonly PDO $pdo)
    {
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

        $sql = "SELECT DISTINCT a.id, a.titulo, a.mensaje, a.importancia, a.inicia_en, a.termina_en, a.alcance,
                       u.nombre AS autor
                  FROM avisos a
                  LEFT JOIN usuarios u ON u.id = a.autor_id
                  LEFT JOIN aviso_instalaciones ai ON ai.aviso_id = a.id
                 WHERE a.inicia_en <= ? AND a.termina_en > ?
                   AND (a.alcance = 'todos'";
        $params = [$ahora, $ahora];
        if ($instalaciones !== []) {
            $sql .= ' OR ai.instalacion_id IN (' . implode(',', array_fill(0, count($instalaciones), '?')) . ')';
            $params = array_merge($params, $instalaciones);
        }
        $sql .= ") ORDER BY FIELD(a.importancia, 'urgente', 'importante', 'informativo'), a.inicia_en DESC, a.id DESC";

        $stmt = $this->pdo->prepare($sql);
        $stmt->execute($params);
        return $stmt->fetchAll();
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
     * @param array<string, mixed> $in titulo, mensaje, importancia, inicia_en, termina_en, alcance, instalaciones[]
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

        $this->pdo->beginTransaction();
        try {
            if ($id === null) {
                $this->pdo->prepare(
                    'INSERT INTO avisos (titulo, mensaje, importancia, inicia_en, termina_en, alcance, autor_id) VALUES (?, ?, ?, ?, ?, ?, ?)'
                )->execute([$titulo, $mensaje, $importancia, $inicia, $termina, $alcance, (int) $autor['id']]);
                $id = (int) $this->pdo->lastInsertId();
            } else {
                $this->pdo->prepare(
                    'UPDATE avisos SET titulo = ?, mensaje = ?, importancia = ?, inicia_en = ?, termina_en = ?, alcance = ? WHERE id = ?'
                )->execute([$titulo, $mensaje, $importancia, $inicia, $termina, $alcance, $id]);
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
