<?php

declare(strict_types=1);

namespace App\Services;

use App\Core\ValidationException;
use PDO;

/**
 * Categorías de la acción de un ticket (Hardware, Impresión, Redes…), cada una
 * con su descripción de lo que abarca. Las elige quien crea el ticket, TI
 * puede corregirlas y el Admin TI las edita en Gestión › Categorías.
 */
final class CategoriaService
{
    private static ?bool $tabla = null;

    public function __construct(private readonly PDO $pdo)
    {
    }

    /** ¿Ya se importó la migración? Sin ella los tickets se crean sin categoría. */
    public function disponible(): bool
    {
        if (self::$tabla === null) {
            self::$tabla = (int) $this->pdo->query(
                "SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'categorias'"
            )->fetchColumn() === 1;
        }
        return self::$tabla;
    }

    /** @return list<array{id: int, nombre: string, descripcion: ?string, orden: int, activo: int}> */
    public function todas(bool $soloActivas = false): array
    {
        if (!$this->disponible()) {
            return [];
        }
        $rows = $this->pdo->query(
            'SELECT id, nombre, descripcion, orden, activo FROM categorias'
            . ($soloActivas ? ' WHERE activo = 1' : '') . ' ORDER BY orden, nombre'
        )->fetchAll();
        return array_map(static fn (array $r): array => [
            'id' => (int) $r['id'], 'nombre' => (string) $r['nombre'], 'descripcion' => $r['descripcion'],
            'orden' => (int) $r['orden'], 'activo' => (int) $r['activo'],
        ], $rows);
    }

    /** @return array{id: int, nombre: string, descripcion: ?string, orden: int, activo: int}|null */
    public function find(int $id): ?array
    {
        foreach ($this->todas() as $c) {
            if ($c['id'] === $id) {
                return $c;
            }
        }
        return null;
    }

    /** Categoría activa elegida en un formulario (o excepción). */
    public function validar(int $id): int
    {
        $c = $this->find($id);
        if ($c === null || $c['activo'] !== 1) {
            throw new ValidationException(['Elige la categoría de la acción.']);
        }
        return $c['id'];
    }

    public function guardar(?int $id, string $nombre, string $descripcion, int $orden, bool $activo): int
    {
        $nombre = trim(preg_replace('/\s+/u', ' ', $nombre) ?? '');
        $descripcion = trim(preg_replace('/\s+/u', ' ', $descripcion) ?? '');
        $errors = [];
        if (mb_strlen($nombre) < 3 || mb_strlen($nombre) > 120) {
            $errors[] = 'El nombre de la categoría debe tener entre 3 y 120 caracteres.';
        }
        if (mb_strlen($descripcion) > 500) {
            $errors[] = 'La descripción admite hasta 500 caracteres.';
        }
        $dup = $this->pdo->prepare('SELECT id FROM categorias WHERE nombre = ? AND id <> ?');
        $dup->execute([$nombre, $id ?? 0]);
        if ($dup->fetchColumn()) {
            $errors[] = "Ya existe la categoría \"{$nombre}\".";
        }
        if ($errors !== []) {
            throw new ValidationException($errors);
        }
        $desc = $descripcion !== '' ? $descripcion : null;
        if ($id === null) {
            $this->pdo->prepare('INSERT INTO categorias (nombre, descripcion, orden, activo) VALUES (?, ?, ?, ?)')
                ->execute([$nombre, $desc, $orden, $activo ? 1 : 0]);
            return (int) $this->pdo->lastInsertId();
        }
        $this->pdo->prepare('UPDATE categorias SET nombre = ?, descripcion = ?, orden = ?, activo = ? WHERE id = ?')
            ->execute([$nombre, $desc, $orden, $activo ? 1 : 0, $id]);
        return $id;
    }
}
