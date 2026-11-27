<?php

declare(strict_types=1);

namespace App\Services\Mail;

use App\Core\ValidationException;
use PDO;

/**
 * Correo de la mesa institucional (Remedy) y copias fijas de cada ticket nuevo.
 *  - El Admin TI los captura en Gestión › Correo y Remedy (tabla ajustes).
 *  - Mientras no los capture se usan los de config/config.php
 *    (mail.mesa_institucional y mail.notify_new_tickets_to).
 *  - Cada supervisor/coordinador decide si su instalación envía a Remedy
 *    (instalaciones.remedy_activo); las copias fijas no dependen de eso.
 */
final class CorreoAjustes
{
    private const REMEDY = 'correo.remedy';
    private const COPIAS = 'correo.copias';
    private const EXIGIR = 'correo.exigir_folio_remedy';

    public function __construct(private readonly PDO $pdo)
    {
    }

    public function remedy(): string
    {
        $v = $this->leer(self::REMEDY);
        return $v !== null ? $v : Mailer::cleanAddress((string) config('mail.mesa_institucional', ''));
    }

    /** @return list<string> */
    public function copias(): array
    {
        $v = $this->leer(self::COPIAS);
        $lista = $v !== null ? self::separar($v) : array_map('strval', (array) config('mail.notify_new_tickets_to', []));
        return array_values(array_unique(array_filter(array_map(static fn (string $e): string => mb_strtolower(Mailer::cleanAddress($e)), $lista))));
    }

    /** Vacío = sin Remedy / sin copias (no regresa al config). */
    public function guardar(string $remedy, string $copias, int $actorId): void
    {
        $remedy = mb_strtolower(trim($remedy));
        $errors = [];
        if ($remedy !== '' && Mailer::cleanAddress($remedy) === '') {
            $errors[] = 'El correo de Remedy no es válido.';
        }
        $lista = [];
        foreach (self::separar($copias) as $e) {
            $limpio = mb_strtolower(Mailer::cleanAddress($e));
            if ($limpio === '') {
                $errors[] = "La copia \"{$e}\" no es un correo válido.";
            } elseif (!in_array($limpio, $lista, true)) {
                $lista[] = $limpio;
            }
        }
        if ($errors !== []) {
            throw new ValidationException($errors);
        }
        $this->escribir(self::REMEDY, $remedy, $actorId);
        $this->escribir(self::COPIAS, implode(', ', $lista), $actorId);
    }

    /**
     * ¿Se exige el folio de Remedy para cerrar? Solo si hay correo de Remedy,
     * la instalación le envía sus tickets y el Admin no lo desactivó.
     */
    public function exigeFolioRemedy(?int $instalacionId): bool
    {
        return $this->leer(self::EXIGIR) !== '0' && $this->remedy() !== '' && $this->remedyActivo($instalacionId);
    }

    public function exigirFolio(): bool
    {
        return $this->leer(self::EXIGIR) !== '0';
    }

    public function guardarExigirFolio(bool $exigir, int $actorId): void
    {
        $this->escribir(self::EXIGIR, $exigir ? '1' : '0', $actorId);
    }

    /** Ticket sin instalación: sí va a Remedy. */
    public function remedyActivo(?int $instalacionId): bool
    {
        if ($instalacionId === null) {
            return true;
        }
        try {
            $stmt = $this->pdo->prepare('SELECT remedy_activo FROM instalaciones WHERE id = ?');
            $stmt->execute([$instalacionId]);
            $v = $stmt->fetchColumn();
            return $v === false || (int) $v === 1;
        } catch (\PDOException) {
            return true; // migración pendiente
        }
    }

    public function cambiarRemedyInstalacion(int $instalacionId, bool $activo): void
    {
        $this->pdo->prepare('UPDATE instalaciones SET remedy_activo = ? WHERE id = ?')->execute([$activo ? 1 : 0, $instalacionId]);
    }

    private function leer(string $clave): ?string
    {
        try {
            $stmt = $this->pdo->prepare('SELECT valor FROM ajustes WHERE clave = ?');
            $stmt->execute([$clave]);
            $v = $stmt->fetchColumn();
            return $v === false ? null : (string) $v;
        } catch (\PDOException) {
            return null; // migración pendiente: se usa config.php
        }
    }

    private function escribir(string $clave, string $valor, int $actorId): void
    {
        $this->pdo->prepare(
            'INSERT INTO ajustes (clave, valor, actualizado_por) VALUES (?, ?, ?)
             ON DUPLICATE KEY UPDATE valor = VALUES(valor), actualizado_por = VALUES(actualizado_por)'
        )->execute([$clave, $valor, $actorId]);
    }

    /** @return list<string> */
    private static function separar(string $texto): array
    {
        return array_values(array_filter(array_map('trim', preg_split('/[\s,;]+/', $texto) ?: [])));
    }
}
