<?php

declare(strict_types=1);

namespace App\Services;

use App\Core\PasswordPolicy;
use App\Core\Roles;
use App\Core\ValidationException;
use PDO;

/**
 * Cuentas que crea el Admin TI fuera del GECA:
 *  - administracion: p. ej. ADMIN-TI; sin contraseña de admin local.
 *  - prueba: una por rol para probar; no aparecen en reportes ni tablero.
 * Nacen activas y con el correo verificado (el admin responde por ellas);
 * el correo puede ser de cualquier dominio. La carga GECA nunca las toca
 * porque su ficha no viene en el archivo.
 */
final class CuentaService
{
    public const TIPOS = [
        'administracion' => 'Administración',
        'prueba'         => 'Prueba',
    ];

    public function __construct(private readonly PDO $pdo)
    {
    }

    /**
     * @param array<string, mixed> $in ficha, nombre, email, rol, tipo_cuenta, password, password_confirm
     * @param array<string, mixed> $admin
     */
    public function crear(array $in, array $admin): int
    {
        if (($admin['rol'] ?? '') !== 'admin_ti') {
            throw new ValidationException(['Solo el Admin TI puede crear cuentas.']);
        }
        $ficha = strtoupper(trim((string) ($in['ficha'] ?? '')));
        $nombre = trim((string) preg_replace('/\s+/u', ' ', (string) ($in['nombre'] ?? '')));
        $email = mb_strtolower(trim((string) ($in['email'] ?? '')));
        $rol = (string) ($in['rol'] ?? '');
        $tipo = (string) ($in['tipo_cuenta'] ?? '');
        $password = (string) ($in['password'] ?? '');

        $errors = [];
        if (preg_match('/^[A-Z0-9-]{3,20}$/', $ficha) !== 1) {
            $errors[] = 'La ficha debe tener de 3 a 20 letras, números o guiones (ej. ADMIN-TI).';
        } elseif ($this->existe('ficha', $ficha)) {
            $errors[] = "La ficha {$ficha} ya existe.";
        }
        if (mb_strlen($nombre) < 3 || mb_strlen($nombre) > 150) {
            $errors[] = 'El nombre debe tener entre 3 y 150 caracteres.';
        }
        if (mb_strlen($email) > 150 || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
            $errors[] = 'Captura un correo válido.';
        } elseif ($this->existe('email', $email)) {
            $errors[] = 'Ese correo ya lo usa otra cuenta.';
        }
        if (!array_key_exists($rol, Roles::LABELS)) {
            $errors[] = 'Elige un rol válido.';
        }
        if (!array_key_exists($tipo, self::TIPOS)) {
            $errors[] = 'Elige el tipo de cuenta: administración o prueba.';
        }
        $minimo = $rol === 'admin_ti' ? PasswordPolicy::MIN_ADMIN : PasswordPolicy::MIN_USER;
        $errors = array_merge($errors, PasswordPolicy::errors($password, [$ficha, $email, $nombre], $minimo));
        if (!hash_equals($password, (string) ($in['password_confirm'] ?? ''))) {
            $errors[] = 'La confirmación no coincide con la contraseña.';
        }
        if ($errors !== []) {
            throw new ValidationException($errors);
        }

        $this->pdo->prepare(
            'INSERT INTO usuarios (ficha, nombre, email, password_hash, rol, tipo_cuenta, registrado, activo, email_verificado_en)
             VALUES (?, ?, ?, ?, ?, ?, 1, 1, NOW())'
        )->execute([$ficha, $nombre, $email, password_hash($password, PASSWORD_DEFAULT), $rol, $tipo]);
        return (int) $this->pdo->lastInsertId();
    }

    /** Cambia el tipo de una cuenta existente (p. ej. marcar la cuenta del instalador como administración). */
    public function cambiarTipo(int $id, string $tipo, array $admin): void
    {
        if (($admin['rol'] ?? '') !== 'admin_ti') {
            throw new ValidationException(['Solo el Admin TI puede cambiar el tipo de cuenta.']);
        }
        if (!in_array($tipo, ['geca', 'administracion', 'prueba'], true)) {
            throw new ValidationException(['Tipo de cuenta inválido.']);
        }
        $this->pdo->prepare('UPDATE usuarios SET tipo_cuenta = ? WHERE id = ?')->execute([$tipo, $id]);
    }

    private function existe(string $campo, string $valor): bool
    {
        $stmt = $this->pdo->prepare($campo === 'ficha' ? 'SELECT 1 FROM usuarios WHERE ficha = ?' : 'SELECT 1 FROM usuarios WHERE email = ?');
        $stmt->execute([$valor]);
        return (bool) $stmt->fetchColumn();
    }
}
