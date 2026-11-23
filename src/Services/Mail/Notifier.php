<?php

declare(strict_types=1);

namespace App\Services\Mail;

use App\Config\Database;
use App\Services\Alcance;
use App\Services\TicketService;
use Throwable;

/**
 * Avisos por correo de los tickets.
 *
 * Los correos se encolan durante la petición y se envían al final, después de
 * entregar la respuesta al navegador (fastcgi_finish_request en PHP-FPM), para
 * que un SMTP lento nunca haga esperar al usuario. Un fallo de envío se
 * registra en el log y jamás interrumpe la operación.
 *
 * Reglas:
 *  - Ticket nuevo      → confirmación al solicitante + aviso a TI.
 *  - Respuesta de TI   → solicitante (las notas internas no se notifican).
 *  - Cambio de estado  → solicitante.
 *  - Respuesta del usuario → agente asignado (o TI si no hay asignado).
 *  - Asignación        → agente asignado.
 */
final class Notifier
{
    /** @var list<array{to: string, subject: string, text: string, html: string}> */
    private static array $queue = [];

    private static bool $registered = false;

    public static function ticketCreado(int $ticketId): void
    {
        $t = self::ticket($ticketId);
        if ($t === null) {
            return;
        }
        $url = self::ticketUrl($ticketId);

        self::queue(
            (string) $t['solicitante_email'],
            "[{$t['folio']}] Recibimos tu ticket: {$t['titulo']}",
            'Recibimos tu ticket',
            "Hola {$t['solicitante_nombre']}, registramos tu solicitud. Te avisaremos por este medio cuando TI la atienda.",
            self::resumen($t),
            $url,
            'Ver mi ticket'
        );

        $detalle = self::resumen($t);
        if ((int) $t['discrepancia_ubicacion'] === 1) {
            $detalle['Atención'] = 'La ubicación física reportada no coincide con la del GECA.';
        }
        foreach (self::avisoTicketNuevo($t['instalacion_id'] !== null ? (int) $t['instalacion_id'] : null) as $email) {
            if (strcasecmp($email, (string) $t['solicitante_email']) === 0) {
                continue;
            }
            self::queue(
                $email,
                "[{$t['folio']}] Nuevo ticket ({$t['prioridad']}): {$t['titulo']}",
                'Nuevo ticket en la bandeja',
                "{$t['solicitante_nombre']} (ficha {$t['solicitante_ficha']}) levantó un ticket.",
                $detalle,
                $url,
                'Atender ticket'
            );
        }
    }

    public static function comentario(int $ticketId, int $autorId, string $mensaje, bool $interna): void
    {
        if ($interna) {
            return;
        }
        $t = self::ticket($ticketId);
        if ($t === null) {
            return;
        }
        $url = self::ticketUrl($ticketId);
        $extracto = mb_strlen($mensaje) > 600 ? mb_substr($mensaje, 0, 600) . '…' : $mensaje;

        if ($autorId === (int) $t['usuario_solicitante_id']) {
            // El usuario respondió: avisar al agente asignado o al equipo TI.
            $destinos = $t['agente_email'] !== null
                ? [(string) $t['agente_email']]
                : self::responsables($t['instalacion_id'] !== null ? (int) $t['instalacion_id'] : null);
            foreach ($destinos as $email) {
                self::queue(
                    $email,
                    "[{$t['folio']}] Respuesta del usuario: {$t['titulo']}",
                    'El usuario respondió',
                    "{$t['solicitante_nombre']} escribió:",
                    ['Mensaje' => $extracto],
                    $url,
                    'Ver ticket'
                );
            }
            return;
        }

        self::queue(
            (string) $t['solicitante_email'],
            "[{$t['folio']}] TI respondió a tu ticket",
            'Tienes una respuesta de TI',
            "Sobre tu ticket \"{$t['titulo']}\":",
            ['Mensaje' => $extracto, 'Estado' => (string) $t['estado_nombre']],
            $url,
            'Responder'
        );
    }

    public static function ticketActualizado(int $ticketId, int $actorId, bool $cambioEstado, bool $cambioAgente): void
    {
        $t = self::ticket($ticketId);
        if ($t === null) {
            return;
        }
        $url = self::ticketUrl($ticketId);

        if ($cambioEstado) {
            $cerrado = (int) $t['es_cierre'] === 1;
            self::queue(
                (string) $t['solicitante_email'],
                "[{$t['folio']}] " . ($cerrado ? 'Ticket ' . mb_strtolower((string) $t['estado_nombre']) : 'Tu ticket cambió a: ' . $t['estado_nombre']),
                $cerrado ? 'Tu ticket fue ' . mb_strtolower((string) $t['estado_nombre']) : 'Tu ticket cambió de estado',
                "\"{$t['titulo']}\" ahora está en: {$t['estado_nombre']}."
                    . ($cerrado ? ' Si el problema continúa, responde desde el sistema o levanta un ticket nuevo.' : ''),
                ['Atiende' => (string) ($t['agente_nombre'] ?? 'Por asignar')],
                $url,
                'Ver ticket'
            );
        }

        if ($cambioAgente && $t['agente_email'] !== null && (int) $t['agente_asignado_id'] !== $actorId) {
            self::queue(
                (string) $t['agente_email'],
                "[{$t['folio']}] Se te asignó: {$t['titulo']}",
                'Tienes un ticket asignado',
                'Se te asignó el siguiente ticket:',
                self::resumen($t),
                $url,
                'Atender ticket'
            );
        }
    }

    /**
     * Envío inmediato (página de prueba). Devuelve null si salió bien o el error.
     */
    public static function sendTest(string $to): ?string
    {
        $mailer = self::mailer();
        if (!$mailer->enabled()) {
            return 'El envío de correos está desactivado (mail.driver = off en config/config.php).';
        }
        [$text, $html] = self::render(
            'Correo de prueba',
            'Si recibiste este mensaje, los avisos por correo de la Mesa de Ayuda TI funcionan correctamente.',
            ['Método' => $mailer->driver(), 'Enviado' => date('d/m/Y H:i')],
            self::baseUrl(),
            'Abrir Mesa de Ayuda'
        );
        try {
            $mailer->send($to, 'Prueba de correo — Mesa de Ayuda TI', $text, $html);
            return null;
        } catch (Throwable $e) {
            return $e->getMessage();
        }
    }

    // ------------------------------------------------------------------

    /**
     * @param array<string, string> $datos
     */
    private static function queue(string $to, string $subject, string $title, string $intro, array $datos, string $url, string $cta): void
    {
        if (Mailer::cleanAddress($to) === '' || !self::mailer()->enabled()) {
            return;
        }
        [$text, $html] = self::render($title, $intro, $datos, $url, $cta);
        self::$queue[] = ['to' => $to, 'subject' => $subject, 'text' => $text, 'html' => $html];

        if (!self::$registered) {
            self::$registered = true;
            register_shutdown_function([self::class, 'flush']);
        }
    }

    /** @internal se ejecuta al terminar la petición. */
    public static function flush(): void
    {
        if (self::$queue === []) {
            return;
        }
        if (session_status() === PHP_SESSION_ACTIVE) {
            session_write_close();
        }
        if (function_exists('fastcgi_finish_request')) {
            fastcgi_finish_request();
        }
        if (function_exists('set_time_limit')) {
            @set_time_limit(60);
        }
        ignore_user_abort(true);

        $mailer = self::mailer();
        $sent = [];
        foreach (self::$queue as $msg) {
            $key = strtolower($msg['to']) . '|' . $msg['subject'];
            if (isset($sent[$key])) {
                continue;
            }
            $sent[$key] = true;
            try {
                $mailer->send($msg['to'], $msg['subject'], $msg['text'], $msg['html']);
            } catch (Throwable $e) {
                error_log('[Correo] No se envió "' . $msg['subject'] . '" a ' . $msg['to'] . ': ' . $e->getMessage());
            }
        }
        self::$queue = [];
    }

    /**
     * @param array<string, string> $datos
     * @return array{0: string, 1: string}
     */
    private static function render(string $title, string $intro, array $datos, string $url, string $cta): array
    {
        $app = (string) config('app.name', 'Mesa de Ayuda TI');

        $text = $title . "\n\n" . $intro . "\n\n";
        foreach ($datos as $k => $v) {
            $text .= $k . ': ' . $v . "\n";
        }
        $text .= "\n{$cta}: {$url}\n\n— {$app}\nEste es un aviso automático; no respondas a este correo.\n";

        $h = static fn (string $v): string => htmlspecialchars($v, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
        $rows = '';
        foreach ($datos as $k => $v) {
            $rows .= '<tr><td style="padding:6px 12px 6px 0;color:#6c757d;vertical-align:top;white-space:nowrap">' . $h($k)
                . '</td><td style="padding:6px 0;color:#212529">' . nl2br($h($v)) . '</td></tr>';
        }
        $html = '<!doctype html><html lang="es"><body style="margin:0;background:#f4f6f9;font-family:Segoe UI,Arial,sans-serif">'
            . '<table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="background:#f4f6f9;padding:24px 12px"><tr><td align="center">'
            . '<table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="max-width:560px;background:#ffffff;border-radius:10px;overflow:hidden">'
            . '<tr><td style="background:#0d6efd;color:#ffffff;padding:16px 24px;font-size:16px;font-weight:600">' . $h($app) . '</td></tr>'
            . '<tr><td style="padding:24px">'
            . '<h1 style="margin:0 0 12px;font-size:20px;color:#212529">' . $h($title) . '</h1>'
            . '<p style="margin:0 0 16px;color:#495057;line-height:1.5">' . $h($intro) . '</p>'
            . ($rows !== '' ? '<table role="presentation" cellpadding="0" cellspacing="0" style="font-size:14px;margin-bottom:20px">' . $rows . '</table>' : '')
            . '<a href="' . $h($url) . '" style="display:inline-block;background:#0d6efd;color:#ffffff;text-decoration:none;padding:10px 20px;border-radius:6px;font-weight:600">' . $h($cta) . '</a>'
            . '</td></tr>'
            . '<tr><td style="padding:12px 24px;background:#f8f9fa;color:#6c757d;font-size:12px">Aviso automático; no respondas a este correo.</td></tr>'
            . '</table></td></tr></table></body></html>';

        return [$text, $html];
    }

    /** @return array<string, mixed>|null */
    private static function ticket(int $id): ?array
    {
        try {
            $t = (new TicketService(Database::getInstance()))->find($id);
            if ($t === null) {
                return null;
            }
            $stmt = Database::getInstance()->prepare('SELECT email FROM usuarios WHERE id = ?');
            $stmt->execute([(int) ($t['agente_asignado_id'] ?? 0)]);
            $agente = $stmt->fetchColumn();
            $t['agente_email'] = $agente !== false ? $agente : null;
            return $t;
        } catch (Throwable $e) {
            error_log('[Correo] ' . $e->getMessage());
            return null;
        }
    }

    /**
     * @param array<string, mixed> $t
     * @return array<string, string>
     */
    private static function resumen(array $t): array
    {
        $datos = [
            'Folio'     => (string) $t['folio'],
            'Asunto'    => (string) $t['titulo'],
            'Tipo'      => tipo_label((string) $t['tipo_ticket']),
            'Prioridad' => ucfirst((string) $t['prioridad']),
        ];
        if ($t['serie'] !== null) {
            $datos['Equipo'] = $t['serie'] . ' · ' . componente_label((string) $t['componente_afectado']);
        }
        if ($t['ubicacion_reportada'] !== null) {
            $datos['Ubicación'] = (string) $t['ubicacion_reportada'];
        }
        return $datos;
    }

    /**
     * Ticket nuevo (spec 7.7): supervisores vigentes de la instalación +
     * mesa de ayuda institucional (Remedy) + lista fija notify_new_tickets_to.
     * Sin instalación (catálogo aún vacío): todo el personal de TI.
     *
     * @return list<string>
     */
    private static function avisoTicketNuevo(?int $instalacionId): array
    {
        $destinos = self::responsables($instalacionId);
        $destinos[] = Mailer::cleanAddress((string) config('mail.mesa_institucional', ''));
        foreach ((array) config('mail.notify_new_tickets_to', []) as $e) {
            $destinos[] = Mailer::cleanAddress((string) $e);
        }
        return self::unicos($destinos);
    }

    /**
     * Responsables de una instalación: sus supervisores vigentes. Sin
     * instalación, todo el personal de TI activo.
     *
     * @return list<string>
     */
    private static function responsables(?int $instalacionId): array
    {
        if ($instalacionId !== null) {
            $emails = array_map(
                static fn (array $s): string => (string) $s['email'],
                (new Alcance(Database::getInstance()))->supervisoresDe($instalacionId)
            );
            return self::unicos($emails);
        }
        $rows = Database::getInstance()->query(
            "SELECT email FROM usuarios
              WHERE rol IN ('agente_ti', 'supervisor', 'admin_ti') AND registrado = 1 AND activo = 1 AND email IS NOT NULL"
        )->fetchAll(\PDO::FETCH_COLUMN);
        return self::unicos(array_map('strval', $rows));
    }

    /**
     * @param list<string> $emails
     * @return list<string>
     */
    private static function unicos(array $emails): array
    {
        $out = [];
        foreach ($emails as $e) {
            $e = Mailer::cleanAddress($e);
            if ($e !== '') {
                $out[strtolower($e)] = $e;
            }
        }
        return array_values($out);
    }

    /**
     * Mensajes encolados aún no enviados (se envían al terminar la petición).
     *
     * @return list<array{to: string, subject: string}>
     */
    public static function cola(): array
    {
        return array_map(static fn (array $m): array => ['to' => $m['to'], 'subject' => $m['subject']], self::$queue);
    }

    /** Descarta la cola sin enviar (uso en pruebas y tras un rollback). */
    public static function reiniciarCola(): void
    {
        self::$queue = [];
    }

    private static function ticketUrl(int $id): string
    {
        return self::baseUrl() . '/tickets/' . $id;
    }

    private static function baseUrl(): string
    {
        return rtrim((string) config('app.base_url', ''), '/');
    }

    private static function mailer(): Mailer
    {
        return new Mailer((array) config('mail', ['driver' => 'off']));
    }
}
