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
        $email = trim($email);
        if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            return false;
        }
        $dominio = strtolower(substr((string) strrchr($email, '@'), 1));
        return in_array($dominio, self::dominios(), true);
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

    /** @return int|null id del usuario verificado, o null si el enlace no es válido */
    public function verificar(string $token): ?int
    {
        if (preg_match('/^[A-Za-z0-9_-]{43}$/', $token) !== 1) {
            return null;
        }
        $this->pdo->beginTransaction();
        try {
            $stmt = $this->pdo->prepare(
                'SELECT id, usuario_id, email FROM verificaciones_email
                  WHERE token_hash = ? AND usado_en IS NULL AND expira_en > NOW()
                  FOR UPDATE'
            );
            $stmt->execute([hash('sha256', $token)]);
            $v = $stmt->fetch();
            if (!$v) {
                $this->pdo->rollBack();
                return null;
            }
            $this->pdo->prepare('UPDATE verificaciones_email SET usado_en = NOW() WHERE id = ?')->execute([(int) $v['id']]);
            $this->pdo->prepare(
                'UPDATE usuarios SET email = ?, email_verificado_en = NOW(), registrado = 1 WHERE id = ? AND activo = 1'
            )->execute([$v['email'], (int) $v['usuario_id']]);
            $this->pdo->commit();
            return (int) $v['usuario_id'];
        } catch (Throwable $e) {
            if ($this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }
            // Correo tomado por otra cuenta entre la solicitud y la verificación.
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
