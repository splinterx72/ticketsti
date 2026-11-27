<?php

declare(strict_types=1);

namespace App\Services;

use App\Core\Roles;
use App\Core\ValidationException;
use App\Services\Mail\Mailer;
use App\Services\Mail\Notifier;
use PDO;

/**
 * Plantillas de correos comunes. Las gestionan supervisores, coordinadores y
 * Admin TI; las envía todo TI con el módulo "plantillas" encendido.
 * Variables: {nombre} {ficha} {folio} {titulo} {instalacion} {agente} {fecha}.
 */
final class PlantillaService
{
    public const VARIABLES = [
        '{nombre}' => 'Nombre del trabajador', '{ficha}' => 'Ficha', '{folio}' => 'Folio del ticket',
        '{titulo}' => 'Asunto del ticket', '{instalacion}' => 'Instalación del ticket', '{agente}' => 'Quien envía', '{fecha}' => 'Fecha de hoy',
    ];

    public function __construct(private readonly PDO $pdo)
    {
    }

    /** @param array<string, mixed> $user */
    public static function puedeGestionar(array $user): bool
    {
        return in_array($user['rol'] ?? '', Roles::SUPERVISION, true);
    }

    /** @return list<array<string, mixed>> */
    public function todas(bool $soloActivas = false): array
    {
        return $this->pdo->query(
            'SELECT p.*, u.nombre AS creado_por_nombre FROM plantillas_correo p LEFT JOIN usuarios u ON u.id = p.creado_por'
            . ($soloActivas ? ' WHERE p.activa = 1' : '') . ' ORDER BY p.nombre'
        )->fetchAll();
    }

    /** @return array<string, mixed>|null */
    public function find(int $id): ?array
    {
        $stmt = $this->pdo->prepare('SELECT * FROM plantillas_correo WHERE id = ?');
        $stmt->execute([$id]);
        return $stmt->fetch() ?: null;
    }

    /**
     * @param array<string, mixed> $in
     * @param array<string, mixed> $user
     */
    public function guardar(?int $id, array $in, array $user): int
    {
        $nombre = trim((string) ($in['nombre'] ?? ''));
        $asunto = trim(str_replace(["\r", "\n"], ' ', (string) ($in['asunto'] ?? '')));
        $cuerpo = trim(str_replace("\r\n", "\n", (string) ($in['cuerpo'] ?? '')));
        $activa = !empty($in['activa']) ? 1 : 0;
        $errors = [];
        if ($nombre === '' || mb_strlen($nombre) > 100) {
            $errors[] = 'Escribe el nombre de la plantilla (máximo 100 caracteres).';
        }
        if ($asunto === '' || mb_strlen($asunto) > 180) {
            $errors[] = 'Escribe el asunto (máximo 180 caracteres).';
        }
        if (mb_strlen($cuerpo) < 5 || mb_strlen($cuerpo) > 5000) {
            $errors[] = 'El mensaje debe tener entre 5 y 5000 caracteres.';
        }
        if ($errors === []) {
            $stmt = $this->pdo->prepare('SELECT COUNT(*) FROM plantillas_correo WHERE nombre = ? AND id <> ?');
            $stmt->execute([$nombre, $id ?? 0]);
            if ((int) $stmt->fetchColumn() > 0) {
                $errors[] = 'Ya existe una plantilla con ese nombre.';
            }
        }
        if ($errors !== []) {
            throw new ValidationException($errors);
        }
        if ($id === null) {
            $this->pdo->prepare('INSERT INTO plantillas_correo (nombre, asunto, cuerpo, activa, creado_por) VALUES (?, ?, ?, ?, ?)')
                ->execute([$nombre, $asunto, $cuerpo, $activa, (int) $user['id']]);
            return (int) $this->pdo->lastInsertId();
        }
        $this->pdo->prepare('UPDATE plantillas_correo SET nombre = ?, asunto = ?, cuerpo = ?, activa = ? WHERE id = ?')
            ->execute([$nombre, $asunto, $cuerpo, $activa, $id]);
        return $id;
    }

    public function eliminar(int $id): void
    {
        $this->pdo->prepare('DELETE FROM plantillas_correo WHERE id = ?')->execute([$id]);
    }

    /**
     * @param array<string, mixed> $remitente
     * @return array<string, string>
     */
    public function variables(int $destinatarioId, ?int $ticketId, array $remitente): array
    {
        $stmt = $this->pdo->prepare('SELECT nombre, ficha FROM usuarios WHERE id = ?');
        $stmt->execute([$destinatarioId]);
        $u = $stmt->fetch() ?: ['nombre' => '', 'ficha' => ''];
        $t = ['folio' => '', 'titulo' => '', 'instalacion' => ''];
        if ($ticketId !== null) {
            $stmt = $this->pdo->prepare('SELECT t.folio, t.titulo, i.nombre AS instalacion FROM tickets t LEFT JOIN instalaciones i ON i.id = t.instalacion_id WHERE t.id = ?');
            $stmt->execute([$ticketId]);
            $t = ($stmt->fetch() ?: []) + $t;
        }
        return [
            '{nombre}' => (string) $u['nombre'], '{ficha}' => (string) $u['ficha'],
            '{folio}' => (string) $t['folio'], '{titulo}' => (string) $t['titulo'], '{instalacion}' => (string) ($t['instalacion'] ?? ''),
            '{agente}' => (string) ($remitente['nombre'] ?? ''), '{fecha}' => date('d/m/Y'),
        ];
    }

    /** @param array<string, string> $vars */
    public static function rellenar(string $texto, array $vars): string
    {
        return strtr($texto, $vars);
    }

    /**
     * Envía el correo (las variables se reemplazan aquí), lo registra y, si es
     * de un ticket, deja la constancia en su seguimiento (visible al trabajador).
     *
     * @param array<string, mixed> $remitente
     */
    public function enviar(array $remitente, int $destinatarioId, ?int $ticketId, string $asunto, string $cuerpo, ?int $plantillaId): void
    {
        $stmt = $this->pdo->prepare('SELECT email FROM usuarios WHERE id = ?');
        $stmt->execute([$destinatarioId]);
        $email = Mailer::cleanAddress((string) $stmt->fetchColumn());
        $asunto = trim(str_replace(["\r", "\n"], ' ', $asunto));
        $cuerpo = trim(str_replace("\r\n", "\n", $cuerpo));
        $errors = [];
        if ($email === '') {
            $errors[] = 'El trabajador no tiene un correo registrado.';
        }
        if ($asunto === '' || mb_strlen($asunto) > 180) {
            $errors[] = 'Escribe el asunto (máximo 180 caracteres).';
        }
        if (mb_strlen($cuerpo) < 5 || mb_strlen($cuerpo) > 5000) {
            $errors[] = 'El mensaje debe tener entre 5 y 5000 caracteres.';
        }
        if ($errors !== []) {
            throw new ValidationException($errors);
        }
        $vars = $this->variables($destinatarioId, $ticketId, $remitente);
        $asunto = self::rellenar($asunto, $vars);
        $cuerpo = self::rellenar($cuerpo, $vars);

        Notifier::libre($email, $asunto, $cuerpo, $ticketId);
        $this->pdo->prepare('INSERT INTO correos_enviados (plantilla_id, destinatario_id, email, ticket_id, asunto, enviado_por) VALUES (?, ?, ?, ?, ?, ?)')
            ->execute([$plantillaId, $destinatarioId, $email, $ticketId, mb_substr($asunto, 0, 180), (int) $remitente['id']]);
        if ($ticketId !== null) {
            $this->pdo->prepare('INSERT INTO comentarios (ticket_id, usuario_id, mensaje, es_nota_interna) VALUES (?, ?, ?, 0)')
                ->execute([$ticketId, (int) $remitente['id'], "[Correo enviado] {$asunto}\n\n{$cuerpo}"]);
        }
    }

    /** @return list<array<string, mixed>> últimos correos enviados a un trabajador */
    public function enviadosA(int $destinatarioId): array
    {
        $stmt = $this->pdo->prepare(
            'SELECT c.enviado_en, c.asunto, c.ticket_id, t.folio, u.nombre AS enviado_por_nombre
               FROM correos_enviados c LEFT JOIN tickets t ON t.id = c.ticket_id LEFT JOIN usuarios u ON u.id = c.enviado_por
              WHERE c.destinatario_id = ? ORDER BY c.id DESC LIMIT 20'
        );
        $stmt->execute([$destinatarioId]);
        return $stmt->fetchAll();
    }
}
