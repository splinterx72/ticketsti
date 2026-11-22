<?php

declare(strict_types=1);

namespace App\Services\Mail;

use RuntimeException;

/**
 * Envío de correo sin librerías externas, apto para hosting compartido.
 *
 * Drivers:
 *  - 'off'  : no envía nada.
 *  - 'mail' : función mail() del hosting (sendmail de cPanel).
 *  - 'smtp' : cliente SMTP nativo con SSL (465) o STARTTLS (587) y AUTH LOGIN.
 *
 * Los mensajes son multipart/alternative (texto + HTML) en UTF-8.
 */
final class Mailer
{
    /** @param array<string, mixed> $config sección 'mail' de config.php */
    public function __construct(private readonly array $config)
    {
    }

    public function enabled(): bool
    {
        return in_array($this->driver(), ['mail', 'smtp'], true);
    }

    public function driver(): string
    {
        return (string) ($this->config['driver'] ?? 'off');
    }

    /**
     * @throws RuntimeException si el envío falla
     */
    public function send(string $to, string $subject, string $text, string $html): void
    {
        if (!$this->enabled()) {
            return;
        }
        $to = self::cleanAddress($to);
        if ($to === '') {
            throw new RuntimeException('Destinatario inválido.');
        }

        $fromEmail = self::cleanAddress((string) ($this->config['from_email'] ?? ''));
        if ($fromEmail === '') {
            throw new RuntimeException('Configura mail.from_email con un correo válido.');
        }
        $fromName = self::cleanHeader((string) ($this->config['from_name'] ?? 'Mesa de Ayuda TI'));
        $subject = self::cleanHeader($subject);

        $boundary = 'b' . bin2hex(random_bytes(12));
        $domain = substr((string) strrchr($fromEmail, '@'), 1) ?: 'localhost';

        $headers = [
            'From'         => self::encodeName($fromName) . " <{$fromEmail}>",
            'Reply-To'     => $fromEmail,
            'Date'         => date(DATE_RFC2822),
            'Message-ID'   => '<' . bin2hex(random_bytes(16)) . '@' . $domain . '>',
            'MIME-Version' => '1.0',
            'Content-Type' => "multipart/alternative; boundary=\"{$boundary}\"",
            'X-Mailer'     => 'MesaAyudaTI',
            'Auto-Submitted' => 'auto-generated',
        ];

        $body = "--{$boundary}\r\n"
            . "Content-Type: text/plain; charset=UTF-8\r\n"
            . "Content-Transfer-Encoding: base64\r\n\r\n"
            . chunk_split(base64_encode($text), 76, "\r\n")
            . "--{$boundary}\r\n"
            . "Content-Type: text/html; charset=UTF-8\r\n"
            . "Content-Transfer-Encoding: base64\r\n\r\n"
            . chunk_split(base64_encode($html), 76, "\r\n")
            . "--{$boundary}--\r\n";

        $encodedSubject = mb_encode_mimeheader($subject, 'UTF-8', 'B', "\r\n");

        if ($this->driver() === 'mail') {
            $this->sendWithMail($to, $encodedSubject, $headers, $body, $fromEmail);
            return;
        }
        $this->sendWithSmtp($to, $encodedSubject, $headers, $body, $fromEmail);
    }

    /** @param array<string, string> $headers */
    private function sendWithMail(string $to, string $subject, array $headers, string $body, string $from): void
    {
        $lines = [];
        foreach ($headers as $k => $v) {
            $lines[] = "{$k}: {$v}";
        }
        // -f fija el remitente del sobre (mejora la entrega en cPanel).
        $ok = @mail($to, $subject, $body, implode("\r\n", $lines), '-f' . $from);
        if (!$ok) {
            throw new RuntimeException('La función mail() del servidor rechazó el envío.');
        }
    }

    /** @param array<string, string> $headers */
    private function sendWithSmtp(string $to, string $subject, array $headers, string $body, string $from): void
    {
        $smtp = (array) ($this->config['smtp'] ?? []);
        $host = (string) ($smtp['host'] ?? '');
        $port = (int) ($smtp['port'] ?? 465);
        $encryption = strtolower((string) ($smtp['encryption'] ?? 'ssl'));
        $timeout = max(3, (int) ($smtp['timeout'] ?? 10));
        if ($host === '') {
            throw new RuntimeException('Configura mail.smtp.host.');
        }

        $remote = ($encryption === 'ssl' ? 'ssl://' : 'tcp://') . $host . ':' . $port;
        $context = stream_context_create(['ssl' => [
            'verify_peer'      => true,
            'verify_peer_name' => true,
            'SNI_enabled'      => true,
        ]]);
        $errno = 0;
        $errstr = '';
        $socket = @stream_socket_client($remote, $errno, $errstr, $timeout, STREAM_CLIENT_CONNECT, $context);
        if ($socket === false) {
            throw new RuntimeException("No se pudo conectar a {$host}:{$port} ({$errstr}).");
        }
        stream_set_timeout($socket, $timeout);

        try {
            $this->expect($socket, [220]);
            $ehloHost = (string) ($_SERVER['SERVER_NAME'] ?? 'localhost');
            $ehloHost = preg_match('/^[A-Za-z0-9.-]+$/', $ehloHost) === 1 ? $ehloHost : 'localhost';
            $this->command($socket, "EHLO {$ehloHost}", [250]);

            if ($encryption === 'tls') {
                $this->command($socket, 'STARTTLS', [220]);
                if (!@stream_socket_enable_crypto($socket, true, STREAM_CRYPTO_METHOD_TLSv1_2_CLIENT | STREAM_CRYPTO_METHOD_TLSv1_3_CLIENT)) {
                    throw new RuntimeException('No se pudo iniciar TLS con el servidor SMTP.');
                }
                $this->command($socket, "EHLO {$ehloHost}", [250]);
            }

            $user = (string) ($smtp['username'] ?? '');
            if ($user !== '') {
                $this->command($socket, 'AUTH LOGIN', [334]);
                $this->command($socket, base64_encode($user), [334]);
                $this->command($socket, base64_encode((string) ($smtp['password'] ?? '')), [235], 'Usuario o contraseña SMTP incorrectos.');
            }

            $this->command($socket, "MAIL FROM:<{$from}>", [250]);
            $this->command($socket, "RCPT TO:<{$to}>", [250, 251]);
            $this->command($socket, 'DATA', [354]);

            $data = '';
            foreach ($headers as $k => $v) {
                $data .= "{$k}: {$v}\r\n";
            }
            $data .= "To: <{$to}>\r\nSubject: {$subject}\r\n\r\n" . $body;
            // Dot-stuffing (RFC 5321 §4.5.2).
            $data = (string) preg_replace('/^\./m', '..', str_replace(["\r\n", "\n"], ["\n", "\r\n"], $data));
            fwrite($socket, $data . "\r\n.\r\n");
            $this->expect($socket, [250]);
            @fwrite($socket, "QUIT\r\n");
        } finally {
            fclose($socket);
        }
    }

    /**
     * @param resource  $socket
     * @param list<int> $codes
     */
    private function command($socket, string $line, array $codes, ?string $friendly = null): string
    {
        fwrite($socket, $line . "\r\n");
        try {
            return $this->expect($socket, $codes);
        } catch (RuntimeException $e) {
            throw new RuntimeException($friendly ?? $e->getMessage(), 0, $e);
        }
    }

    /**
     * Lee una respuesta (posiblemente multilínea) y valida su código.
     *
     * @param resource  $socket
     * @param list<int> $codes
     */
    private function expect($socket, array $codes): string
    {
        $response = '';
        while (($line = fgets($socket, 515)) !== false) {
            $response .= $line;
            if (strlen($line) < 4 || $line[3] === ' ') {
                break;
            }
        }
        $meta = stream_get_meta_data($socket);
        if (!empty($meta['timed_out'])) {
            throw new RuntimeException('El servidor SMTP no respondió a tiempo.');
        }
        $code = (int) substr($response, 0, 3);
        if (!in_array($code, $codes, true)) {
            throw new RuntimeException('Respuesta SMTP inesperada: ' . trim(mb_substr($response, 0, 200)));
        }
        return $response;
    }

    public static function cleanAddress(string $email): string
    {
        $email = trim(str_replace(["\r", "\n", '<', '>'], '', $email));
        return filter_var($email, FILTER_VALIDATE_EMAIL) ? $email : '';
    }

    /** Evita inyección de cabeceras. */
    private static function cleanHeader(string $value): string
    {
        return trim((string) preg_replace('/[\r\n\t]+/', ' ', $value));
    }

    private static function encodeName(string $name): string
    {
        return preg_match('/^[A-Za-z0-9 .\-]+$/', $name) === 1
            ? '"' . $name . '"'
            : mb_encode_mimeheader($name, 'UTF-8', 'B', "\r\n");
    }
}
