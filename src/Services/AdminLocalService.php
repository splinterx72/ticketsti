<?php

declare(strict_types=1);

namespace App\Services;

use App\Core\Roles;
use App\Core\ValidationException;
use DateTimeImmutable;
use PDO;

/**
 * Contraseña de admin local de cada agente/supervisor (spec 7.5).
 *
 * Solo se guarda la fecha en que se cambió; vence a los 3 meses calendario.
 * Avisos desde 10 días antes; a 5 días o menos (o vencida, o sin fecha) el
 * usuario de TI queda bloqueado por completo hasta registrar el cambio.
 */
final class AdminLocalService
{
    public const DIAS_AVISO = 10;
    public const DIAS_BLOQUEO = 5;
    public const MESES = 3;

    public function __construct(private readonly PDO $pdo)
    {
    }

    /** Fecha de vencimiento (Y-m-d): +3 meses calendario, ajustada al fin de mes. */
    public static function vence(?string $cambiadoEn): ?string
    {
        if ($cambiadoEn === null || $cambiadoEn === '') {
            return null;
        }
        $d = new DateTimeImmutable(substr($cambiadoEn, 0, 10));
        $primero = $d->modify('first day of this month')->modify('+' . self::MESES . ' months');
        $dia = min((int) $d->format('j'), (int) $primero->format('t'));
        return $primero->setDate((int) $primero->format('Y'), (int) $primero->format('n'), $dia)->format('Y-m-d');
    }

    public static function diasRestantes(?string $cambiadoEn, ?string $hoy = null): ?int
    {
        $vence = self::vence($cambiadoEn);
        if ($vence === null) {
            return null;
        }
        $h = new DateTimeImmutable($hoy ?? date('Y-m-d'));
        return (int) $h->diff(new DateTimeImmutable($vence))->format('%r%a');
    }

    /** @param array<string, mixed> $user requiere rol y admin_local_cambiado_en */
    public static function bloqueado(array $user, ?string $hoy = null): bool
    {
        if (!Roles::esTi($user['rol'] ?? null)) {
            return false;
        }
        $dias = self::diasRestantes($user['admin_local_cambiado_en'] ?? null, $hoy);
        return $dias === null || $dias <= self::DIAS_BLOQUEO;
    }

    /** @param array<string, mixed> $user */
    public static function enAviso(array $user, ?string $hoy = null): bool
    {
        if (!Roles::esTi($user['rol'] ?? null)) {
            return false;
        }
        $dias = self::diasRestantes($user['admin_local_cambiado_en'] ?? null, $hoy);
        return $dias !== null && $dias <= self::DIAS_AVISO;
    }

    public static function semaforo(?int $dias): string
    {
        return match (true) {
            $dias === null, $dias <= self::DIAS_BLOQUEO => 'rojo',
            $dias <= self::DIAS_AVISO                   => 'amarillo',
            default                                     => 'verde',
        };
    }

    /** Registra la fecha del último cambio de la contraseña de admin local. */
    public function registrar(int $usuarioId, string $fecha): void
    {
        $d = DateTimeImmutable::createFromFormat('!Y-m-d', $fecha);
        if ($d === false || $d->format('Y-m-d') !== $fecha) {
            throw new ValidationException(['Captura una fecha válida.']);
        }
        if ($fecha > date('Y-m-d')) {
            throw new ValidationException(['La fecha de cambio no puede ser futura.']);
        }
        if ((self::diasRestantes($fecha) ?? -1) < 0) {
            throw new ValidationException(['Con esa fecha la contraseña ya estaría vencida. Cámbiala y registra la fecha del cambio.']);
        }
        $this->pdo->prepare('UPDATE usuarios SET admin_local_cambiado_en = ? WHERE id = ?')->execute([$fecha, $usuarioId]);
    }
}
