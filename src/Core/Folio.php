<?php

declare(strict_types=1);

namespace App\Core;

use PDO;
use PDOException;

/**
 * Folios no consecutivos: PREFIJO-AAAA-NNNNNN con 6 dígitos aleatorios
 * (100000–999999). Si el número ya existe (índice único), se sortea otro.
 * Así no se puede adivinar el siguiente folio ni saber cuántos hay.
 */
final class Folio
{
    private const INTENTOS = 25;

    /**
     * Asigna el folio definitivo a la fila $id de $tabla (tickets o tareas).
     *
     * @param callable(): int|null $aleatorio solo para pruebas
     */
    public static function asignar(PDO $pdo, string $tabla, string $prefijo, int $id, ?callable $aleatorio = null): string
    {
        if (!in_array($tabla, ['tickets', 'tareas', 'formatos'], true) || preg_match('/^[A-Z]{2}$/', $prefijo) !== 1) {
            throw new \InvalidArgumentException('Tabla o prefijo de folio inválido.');
        }
        $aleatorio ??= static fn (): int => random_int(100000, 999999);
        $stmt = $pdo->prepare("UPDATE {$tabla} SET folio = ? WHERE id = ?");
        for ($i = 0; $i < self::INTENTOS; $i++) {
            $folio = sprintf('%s-%s-%06d', $prefijo, date('Y'), $aleatorio());
            try {
                $stmt->execute([$folio, $id]);
                return $folio;
            } catch (PDOException $e) {
                if ((int) ($e->errorInfo[1] ?? 0) !== 1062) {
                    throw $e;
                }
            }
        }
        throw new \RuntimeException('No se pudo generar un folio único; inténtalo de nuevo.');
    }
}
