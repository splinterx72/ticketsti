<?php

declare(strict_types=1);

namespace App\Services;

use PDO;
use Throwable;

/**
 * Verificación del correo de la empresa (spec 7.6 bis).
 *
 * - Solo dominios permitidos (config registro.dominios_permitidos, exacto).
 * - Enlace de un solo uso válido 24 h; se guarda solo el hash SHA-256.
 * - Emitir un enlace nuevo invalida los anteriores del mismo usuario.
 * - Al verificar: email_verificado_en = NOW(), registrado = 1 y, si el
 *   enlace era para un correo nuevo, se aplica ese correo.
 */
final class VerificacionEmailService
{
    public const HORAS_VIGENCIA = 24;
    public const MAX_POR_HORA = 5;

    public function __construct(private readonly PDO $pdo)
    {
    }

    /** @return list<string> */
    public static function dominios(): array
    {
        $dominios = array_values(array_filter(array_map(
            static fn (mixed $d): string => strtolower(trim((string) $d)),
            (array) config('registro.dominios_permitidos', ['pemex.com'])
        )));
        return $dominios !== [] ? $dominios : ['pemex.com'];
    }

    public function dominioPermitido(string $email): bool
    {
        return self::esDeLaEmpresa($email);
    }

    private static function esDeLaEmpresa(string $email): bool
    {
        $email = trim($email);
        if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            return false;
        }
        $dominio = strtolower(substr((string) strrchr($email, '@'), 1));
        return in_array($dominio, self::dominios(), true);
    }

    /** Un correo heredado que no cambia se conserva; uno nuevo debe ser de la empresa. */
    public static function correoAceptable(string $nuevo, string $actual): bool
    {
        $nuevo = trim($nuevo);
        if ($nuevo === '' || mb_strlen($nuevo) > 150) {
            return false;
        }
        if ($actual !== '' && strcasecmp($nuevo, trim($actual)) === 0) {
            return true;
        }
        return self::esDeLaEmpresa($nuevo);
    }

    public function emitir(int $usuarioId, string $email): string
    {
        $token = rtrim(strtr(base64_encode(random_bytes(32)), '+/', '-_'), '=');
        $this->pdo->prepare('UPDATE verificaciones_email SET usado_en = NOW() WHERE usuario_id = ? AND usado_en IS NULL')
            ->execute([$usuarioId]);
        $this->pdo->prepare(
            'INSERT INTO verificaciones_email (usuario_id, email, token_hash, expira_en)
             VALUES (?, ?, ?, NOW() + INTERVAL ' . self::HORAS_VIGENCIA . ' HOUR)'
        )->execute([$usuarioId, mb_strtolower(trim($email)), hash('sha256', $token)]);
        return $token;
    }

    /**
     * Deja lista una ficha del GECA para activarse: guarda extensión, contraseña
     * y ubicación, pero NO el correo (va solo en el enlace, hasta verificarse),
     * para que nadie aparte el correo de otra persona.
     *
     * @param array{ubicacion_fisica_id?: int|null, nivel_id?: int|null, ala?: string|null, referencia?: string|null}|null $fisica
     * @return string|null token del enlace, o null si la ficha ya no está pendiente
     */
    public function prepararRegistro(int $usuarioId, string $email, string $ext, string $password, ?array $fisica): ?string
    {
        $stmt = $this->pdo->prepare(
            'UPDATE usuarios
                SET telefono_ext = ?, password_hash = ?,
                    habitual_ubicacion_fisica_id = ?, habitual_nivel_id = ?, habitual_ala = ?, habitual_referencia = ?
              WHERE id = ? AND registrado = 0 AND activo = 1'
        );
        $stmt->execute([
            $ext, password_hash($password, PASSWORD_DEFAULT),
            $fisica['ubicacion_fisica_id'] ?? null, $fisica['nivel_id'] ?? null, $fisica['ala'] ?? null, $fisica['referencia'] ?? null,
            $usuarioId,
        ]);
        if ($stmt->rowCount() !== 1) {
            return null;
        }
        return $this->emitir($usuarioId, $email);
    }

    /** true si otra cuenta ya activada o verificada usa ese correo (los registros pendientes no lo apartan). */
    public function correoOcupado(string $email, int $exceptoId): bool
    {
        $stmt = $this->pdo->prepare(
            'SELECT 1 FROM usuarios
              WHERE email = ? AND id <> ? AND (registrado = 1 OR email_verificado_en IS NOT NULL)'
        );
        $stmt->execute([mb_strtolower(trim($email)), $exceptoId]);
        return (bool) $stmt->fetchColumn();
    }

    /**
     * Datos del enlace sin gastarlo (la página GET de confirmación; los escáneres
     * de correo pueden abrirla sin consecuencias).
     *
     * @return array{estado: string, usuario_id: int, ficha: string, nombre: string, email: string}|null
     *         estado 'valido', o 'verificado' si el enlace ya no sirve pero la cuenta ya está verificada con ese correo
     */
    public function consultar(string $token): ?array
    {
        if (preg_match('/^[A-Za-z0-9_-]{43}$/', $token) !== 1) {
            return null;
        }
        $stmt = $this->pdo->prepare(
            'SELECT v.usuario_id, v.email, (v.usado_en IS NULL AND v.expira_en > NOW()) AS vigente,
                    u.ficha, u.nombre, u.email AS email_actual, u.email_verificado_en
               FROM verificaciones_email v JOIN usuarios u ON u.id = v.usuario_id
              WHERE v.token_hash = ? AND u.activo = 1'
        );
        $stmt->execute([hash('sha256', $token)]);
        $v = $stmt->fetch();
        if (!$v) {
            return null;
        }
        $datos = ['usuario_id' => (int) $v['usuario_id'], 'ficha' => (string) $v['ficha'], 'nombre' => (string) $v['nombre'], 'email' => (string) $v['email']];
        if ((int) $v['vigente'] === 1) {
            return ['estado' => 'valido'] + $datos;
        }
        if ($v['email_verificado_en'] !== null && $v['email_actual'] === $v['email']) {
            return ['estado' => 'verificado'] + $datos;
        }
        return null;
    }

    /**
     * Gasta el enlace y verifica el correo. Exige la contraseña de la cuenta:
     * quien recibe un enlace que no pidió no puede activar una cuenta ajena.
     * Una contraseña equivocada no gasta el enlace.
     *
     * @return int|null id del usuario verificado, o null si el enlace o la contraseña no son válidos
     */
    public function verificar(string $token, string $password): ?int
    {
        if (preg_match('/^[A-Za-z0-9_-]{43}$/', $token) !== 1) {
            return null;
        }
        $this->pdo->beginTransaction();
        try {
            $stmt = $this->pdo->prepare(
                'SELECT v.id, v.usuario_id, v.email, u.password_hash
                   FROM verificaciones_email v JOIN usuarios u ON u.id = v.usuario_id
                  WHERE v.token_hash = ? AND v.usado_en IS NULL AND v.expira_en > NOW() AND u.activo = 1
                  FOR UPDATE'
            );
            $stmt->execute([hash('sha256', $token)]);
            $v = $stmt->fetch();
            if (!$v || $v['password_hash'] === null || !password_verify($password, (string) $v['password_hash'])
                || $this->correoOcupado((string) $v['email'], (int) $v['usuario_id'])) {
                $this->pdo->rollBack();
                return null;
            }
            $this->pdo->prepare('UPDATE verificaciones_email SET usado_en = NOW() WHERE id = ?')->execute([(int) $v['id']]);
            // Registros pendientes antiguos que aún tuvieran ese correo lo liberan.
            $this->pdo->prepare(
                'UPDATE usuarios SET email = NULL WHERE email = ? AND id <> ? AND registrado = 0 AND email_verificado_en IS NULL'
            )->execute([$v['email'], (int) $v['usuario_id']]);
            $this->pdo->prepare(
                'UPDATE usuarios SET email = ?, email_verificado_en = NOW(), registrado = 1 WHERE id = ?'
            )->execute([$v['email'], (int) $v['usuario_id']]);
            $this->pdo->commit();
            return (int) $v['usuario_id'];
        } catch (Throwable $e) {
            if ($this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }
            if ($e instanceof \PDOException && (int) ($e->errorInfo[1] ?? 0) === 1062) {
                return null;
            }
            throw $e;
        }
    }

    public function puedeReenviar(int $usuarioId): bool
    {
        $stmt = $this->pdo->prepare(
            'SELECT COUNT(*) FROM verificaciones_email WHERE usuario_id = ? AND creado_en > NOW() - INTERVAL 1 HOUR'
        );
        $stmt->execute([$usuarioId]);
        return (int) $stmt->fetchColumn() < self::MAX_POR_HORA;
    }

    /** true si la cuenta aún no estaba activada (registro nuevo, no cambio de correo). */
    public function esRegistroNuevo(int $usuarioId): bool
    {
        $stmt = $this->pdo->prepare('SELECT registrado FROM usuarios WHERE id = ?');
        $stmt->execute([$usuarioId]);
        return (int) $stmt->fetchColumn() === 0;
    }

    /** Correo pendiente de verificar (el del último enlace emitido). */
    public function correoPendiente(int $usuarioId): ?string
    {
        $stmt = $this->pdo->prepare(
            'SELECT email FROM verificaciones_email WHERE usuario_id = ? ORDER BY id DESC LIMIT 1'
        );
        $stmt->execute([$usuarioId]);
        $e = $stmt->fetchColumn();
        return $e === false ? null : (string) $e;
    }
}
