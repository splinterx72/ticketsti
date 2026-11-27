<?php

declare(strict_types=1);

namespace App\Services;

use App\Core\Cifrado;
use App\Core\Roles;
use App\Core\ValidationException;
use PDO;

/**
 * Llaves de recuperación de BitLocker por equipo.
 *  - Identificador (obligatorio): el que muestra la pantalla de recuperación.
 *  - Llave (opcional): 48 dígitos, se guarda CIFRADA y solo se muestra
 *    volviendo a escribir la contraseña; cada consulta queda en bitácora.
 *  - Capturan y ven: el resguardante del equipo y el TI de la instalación del
 *    equipo (si el equipo aún no tiene instalación, cualquier TI). Admin: todo.
 *  - Nunca viaja en correos, exportaciones ni reportes.
 */
final class BitLockerService
{
    public const UNIDADES = ['C:', 'D:', 'E:', 'F:', 'G:'];

    public function __construct(private readonly PDO $pdo, private readonly Cifrado $cifrado)
    {
    }

    /** Identificador completo (GUID) o corto (primeros 8 caracteres). Mayúsculas, sin llaves. */
    public static function normalizarIdentificador(string $v): ?string
    {
        $v = strtoupper(trim(str_replace(['{', '}', ' '], '', $v)));
        if (preg_match('/^[0-9A-F]{8}(-[0-9A-F]{4}){3}-[0-9A-F]{12}$/', $v) === 1 || preg_match('/^[0-9A-F]{8}$/', $v) === 1) {
            return $v;
        }
        return null;
    }

    /** 8 bloques de 6 dígitos; cada bloque es múltiplo de 11 y menor que 720896 (regla de BitLocker). */
    public static function normalizarLlave(string $v): ?string
    {
        $digitos = preg_replace('/\D/', '', $v);
        if ($digitos === null || strlen($digitos) !== 48) {
            return null;
        }
        $bloques = str_split($digitos, 6);
        foreach ($bloques as $b) {
            $n = (int) $b;
            if ($n % 11 !== 0 || $n >= 720896) {
                return null;
            }
        }
        return implode('-', $bloques);
    }

    /**
     * @param array<string, mixed> $user
     * @param array{unidad?: string, identificador?: string, llave?: string, nota?: string} $in
     */
    public function guardar(int $equipoId, array $user, array $in): int
    {
        if (!$this->puede($equipoId, $user)) {
            throw new ValidationException(['No puedes registrar llaves de ese equipo.']);
        }
        $unidad = strtoupper(trim((string) ($in['unidad'] ?? 'C:')));
        $id = self::normalizarIdentificador((string) ($in['identificador'] ?? ''));
        $llaveTxt = trim((string) ($in['llave'] ?? ''));
        $llave = $llaveTxt === '' ? null : self::normalizarLlave($llaveTxt);
        $nota = mb_substr(trim((string) ($in['nota'] ?? '')), 0, 255);
        $errors = [];
        if (!in_array($unidad, self::UNIDADES, true)) {
            $errors[] = 'Elige la unidad (C:, D:…).';
        }
        if ($id === null) {
            $errors[] = 'El identificador debe tener el formato que muestra la pantalla de recuperación (ej. 1A2B3C4D o 1A2B3C4D-5E6F-7A8B-9C0D-1E2F3A4B5C6D).';
        }
        if ($llaveTxt !== '' && $llave === null) {
            $errors[] = 'La llave de recuperación debe tener 48 dígitos en 8 bloques de 6 (revisa que esté bien copiada).';
        }
        if ($errors !== []) {
            throw new ValidationException($errors);
        }
        $this->pdo->prepare(
            'INSERT INTO equipo_bitlocker (equipo_id, unidad, identificador, llave_cifrada, nota, capturado_por) VALUES (?, ?, ?, ?, ?, ?)'
        )->execute([$equipoId, $unidad, $id, $llave !== null ? $this->cifrado->cifrar($llave) : null, $nota !== '' ? $nota : null, (int) $user['id']]);
        return (int) $this->pdo->lastInsertId();
    }

    /**
     * Registros del equipo SIN la llave (null si el usuario no tiene acceso).
     *
     * @param array<string, mixed> $user
     * @return list<array<string, mixed>>|null
     */
    public function deEquipo(int $equipoId, array $user): ?array
    {
        if (!$this->puede($equipoId, $user)) {
            return null;
        }
        $stmt = $this->pdo->prepare(
            'SELECT b.id, b.unidad, b.identificador, (b.llave_cifrada IS NOT NULL) AS tiene_llave, b.nota, b.creado_en,
                    u.nombre AS capturado_por_nombre,
                    (SELECT COUNT(*) FROM bitlocker_consultas c WHERE c.bitlocker_id = b.id) AS consultas
               FROM equipo_bitlocker b LEFT JOIN usuarios u ON u.id = b.capturado_por
              WHERE b.equipo_id = ? ORDER BY b.unidad, b.id DESC'
        );
        $stmt->execute([$equipoId]);
        return $stmt->fetchAll();
    }

    /**
     * Descifra la llave tras confirmar la contraseña; registra la consulta.
     *
     * @param array<string, mixed> $user
     */
    public function mostrar(int $bitlockerId, array $user, string $password, string $motivo): string
    {
        $stmt = $this->pdo->prepare('SELECT b.*, u.password_hash FROM equipo_bitlocker b JOIN usuarios u ON u.id = ? WHERE b.id = ?');
        $stmt->execute([(int) $user['id'], $bitlockerId]);
        $b = $stmt->fetch();
        if (!$b || !$this->puede((int) $b['equipo_id'], $user) || $b['llave_cifrada'] === null) {
            throw new ValidationException(['Esa llave no existe o no puedes verla.']);
        }
        if ($b['password_hash'] === null || !password_verify($password, (string) $b['password_hash'])) {
            throw new ValidationException(['La contraseña no es correcta.']);
        }
        $motivo = trim($motivo);
        if (mb_strlen($motivo) < 3) {
            throw new ValidationException(['Escribe para qué la necesitas (ej. folio del ticket).']);
        }
        $llave = $this->cifrado->descifrar((string) $b['llave_cifrada']);
        $this->pdo->prepare('INSERT INTO bitlocker_consultas (bitlocker_id, usuario_id, motivo, ip) VALUES (?, ?, ?, ?)')
            ->execute([$bitlockerId, (int) $user['id'], mb_substr($motivo, 0, 255), mb_substr((string) ($_SERVER['REMOTE_ADDR'] ?? ''), 0, 45) ?: null]);
        return $llave;
    }

    /** @return list<array<string, mixed>> bitácora de consultas de las llaves del equipo */
    public function bitacora(int $equipoId): array
    {
        $stmt = $this->pdo->prepare(
            'SELECT c.consultado_en, c.motivo, b.unidad, u.nombre, u.ficha
               FROM bitlocker_consultas c JOIN equipo_bitlocker b ON b.id = c.bitlocker_id LEFT JOIN usuarios u ON u.id = c.usuario_id
              WHERE b.equipo_id = ? ORDER BY c.id DESC LIMIT 50'
        );
        $stmt->execute([$equipoId]);
        return $stmt->fetchAll();
    }

    /**
     * Buscador de TI: por identificador (basta el inicio que dicta el usuario
     * desde la pantalla de recuperación), por serie o por ficha del
     * resguardante. Solo equipos dentro del alcance; nunca trae la llave.
     *
     * @param array<string, mixed> $user
     * @return list<array<string, mixed>>
     */
    public function buscar(array $user, string $q): array
    {
        if (!Roles::esTi($user['rol'] ?? null)) {
            return [];
        }
        $q = strtoupper(trim(str_replace(['{', '}', ' '], '', $q)));
        $patron = (string) preg_replace('/[^A-Z0-9-]/', '', $q);
        if ($q === '') {
            return [];
        }
        $like = strlen($patron) >= 4 ? $patron . '%' : null;
        $stmt = $this->pdo->prepare(
            'SELECT DISTINCT e.id, e.serie, e.marca, e.modelo, e.tipo_componente, u.nombre AS resguardante, u.ficha
               FROM equipos e
               LEFT JOIN usuarios u ON u.id = e.usuario_id
               LEFT JOIN equipo_bitlocker b ON b.equipo_id = e.id
              WHERE u.ficha = ?' . ($like !== null ? ' OR b.identificador LIKE ? OR e.serie LIKE ?' : '') . '
              ORDER BY e.serie LIMIT 100'
        );
        $stmt->execute($like !== null ? [$q, $like, $like] : [$q]);
        $out = [];
        $ids = $this->pdo->prepare('SELECT CONCAT(unidad, \' \', identificador) FROM equipo_bitlocker WHERE equipo_id = ? ORDER BY unidad, id DESC');
        foreach ($stmt->fetchAll() as $r) {
            if (!$this->puede((int) $r['id'], $user)) {
                continue;
            }
            $ids->execute([(int) $r['id']]);
            $r['identificadores'] = array_map('strval', $ids->fetchAll(PDO::FETCH_COLUMN));
            $out[] = $r;
        }
        return $out;
    }

    /** @return list<array<string, mixed>> equipos del trabajador y cuántas llaves tiene cada uno */
    public function misEquipos(int $usuarioId): array
    {
        $stmt = $this->pdo->prepare(
            'SELECT e.id, e.serie, e.marca, e.modelo, e.tipo_componente,
                    (SELECT COUNT(*) FROM equipo_bitlocker b WHERE b.equipo_id = e.id) AS registros
               FROM equipos e WHERE e.usuario_id = ? ORDER BY e.es_principal DESC, e.serie'
        );
        $stmt->execute([$usuarioId]);
        return $stmt->fetchAll();
    }

    /** @param array<string, mixed> $user */
    public function puede(int $equipoId, array $user): bool
    {
        $stmt = $this->pdo->prepare('SELECT e.usuario_id, ' . TrabajadorService::SQL_INSTALACION_EQUIPO . ' AS instalacion_id FROM equipos e WHERE e.id = ?');
        $stmt->execute([$equipoId]);
        $eq = $stmt->fetch();
        if (!$eq) {
            return false;
        }
        if ((int) $eq['usuario_id'] === (int) $user['id']) {
            return true;
        }
        if (!Roles::esTi($user['rol'] ?? null)) {
            return false;
        }
        $alcance = new Alcance($this->pdo);
        if ($alcance->veTodo($user) || $eq['instalacion_id'] === null) {
            return true;
        }
        return in_array((int) $eq['instalacion_id'], $alcance->instalacionesDe((int) $user['id']), true);
    }
}
