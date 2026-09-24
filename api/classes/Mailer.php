<?php

/**
 * Sends plain-text email over SMTP with STARTTLS and AUTH LOGIN — enough for
 * Gmail (smtp.gmail.com:587 with an App Password), with no Composer and no
 * PHPMailer. Everything comes from the server environment, set with
 * `fastcgi_param` in nginx/conf.d/api.rockguide.com.conf like `PEPPER`/`DB_*`:
 *
 *   SMTP_HOST, SMTP_PORT (default 587), SMTP_USER, SMTP_PASS
 *   MAIL_FROM (default SMTP_USER), MAIL_FROM_NAME (default "RockGuide")
 *   MAIL_REDIRECT_TO — development only: every message goes to this address
 *                      instead, with the real recipient noted in the body.
 *
 * `send()` never throws. Email is a side channel here — a payment or a
 * cancellation must not fail because Gmail was slow — so a missing config or
 * an SMTP error is logged through `db::writeLog()` and reported as `false`.
 */
class Mailer
{
    private const TIMEOUT_SECONDS = 10;

    /** @var db */
    private $db;

    public function __construct(db $db)
    {
        $this->db = $db;
    }

    public function send(string $to, string $subject, string $body): bool
    {
        $host = self::env('SMTP_HOST');
        $user = self::env('SMTP_USER');
        $pass = self::env('SMTP_PASS');

        if ($host === '' || $user === '' || $pass === '') {
            $this->db->writeLog('Mailer: SMTP is not configured, not sending "' . $subject . '" to ' . $to);
            return false;
        }

        $redirect = self::env('MAIL_REDIRECT_TO');
        if ($redirect !== '') {
            $body = "[Development: originally addressed to {$to}]\n\n" . $body;
            $to = $redirect;
        }

        $to = self::headerSafe($to);
        if (!filter_var($to, FILTER_VALIDATE_EMAIL)) {
            $this->db->writeLog('Mailer: invalid recipient "' . $to . '"');
            return false;
        }

        try {
            $this->deliver($host, (int) (self::env('SMTP_PORT') ?: 587), $user, $pass, $to, $subject, $body);
            return true;
        } catch (Throwable $e) {
            $this->db->writeLog('Mailer: sending "' . $subject . '" to ' . $to . ' failed: ' . $e->getMessage());
            return false;
        }
    }

    private function deliver(string $host, int $port, string $user, string $pass, string $to, string $subject, string $body): void
    {
        $socket = @stream_socket_client('tcp://' . $host . ':' . $port, $errno, $errstr, self::TIMEOUT_SECONDS);
        if (!$socket) {
            throw new RuntimeException("connect failed ({$errno}): {$errstr}");
        }
        stream_set_timeout($socket, self::TIMEOUT_SECONDS);

        try {
            $this->expect($socket, 220);
            $this->command($socket, 'EHLO ' . self::heloName(), 250);
            $this->command($socket, 'STARTTLS', 220);
            if (!stream_socket_enable_crypto($socket, true, STREAM_CRYPTO_METHOD_TLSv1_2_CLIENT | STREAM_CRYPTO_METHOD_TLSv1_3_CLIENT)) {
                throw new RuntimeException('STARTTLS negotiation failed');
            }
            $this->command($socket, 'EHLO ' . self::heloName(), 250);
            $this->command($socket, 'AUTH LOGIN', 334);
            $this->command($socket, base64_encode($user), 334);
            $this->command($socket, base64_encode($pass), 235);

            $from = self::headerSafe(self::env('MAIL_FROM') ?: $user);
            $this->command($socket, 'MAIL FROM:<' . $from . '>', 250);
            $this->command($socket, 'RCPT TO:<' . $to . '>', [250, 251]);
            $this->command($socket, 'DATA', 354);
            // Base64 body lines never start with ".", so no dot-stuffing is needed.
            $this->command($socket, $this->message($from, $to, $subject, $body) . "\r\n.", 250);
            $this->command($socket, 'QUIT', 221);
        } finally {
            fclose($socket);
        }
    }

    private function message(string $from, string $to, string $subject, string $body): string
    {
        $fromName = self::env('MAIL_FROM_NAME') ?: 'RockGuide';
        $domain = substr(strrchr($from, '@') ?: '@localhost', 1);

        $headers = [
            'Date: ' . date(DATE_RFC2822),
            'From: ' . self::encodeHeader($fromName) . ' <' . $from . '>',
            'To: <' . $to . '>',
            'Subject: ' . self::encodeHeader(self::headerSafe($subject)),
            'Message-ID: <' . bin2hex(random_bytes(12)) . '@' . $domain . '>',
            'MIME-Version: 1.0',
            'Content-Type: text/plain; charset=UTF-8',
            'Content-Transfer-Encoding: base64',
        ];

        $normalised = str_replace(["\r\n", "\r"], "\n", $body);

        return implode("\r\n", $headers) . "\r\n\r\n" . rtrim(chunk_split(base64_encode(str_replace("\n", "\r\n", $normalised)), 76, "\r\n"));
    }

    /** @param resource $socket */
    private function command($socket, string $line, $expected): string
    {
        fwrite($socket, $line . "\r\n");
        return $this->expect($socket, $expected);
    }

    /**
     * Reads one (possibly multi-line) SMTP reply and checks its code.
     *
     * @param resource $socket
     * @param int|int[] $expected
     */
    private function expect($socket, $expected): string
    {
        $reply = '';
        while (($line = fgets($socket, 515)) !== false) {
            $reply .= $line;
            // "250-..." continues, "250 ..." ends the reply.
            if (strlen($line) < 4 || $line[3] !== '-') {
                break;
            }
        }

        $code = (int) substr($reply, 0, 3);
        if (!in_array($code, (array) $expected, true)) {
            // Never echo credentials: only the server's reply goes in the message.
            throw new RuntimeException('unexpected SMTP reply: ' . trim($reply));
        }

        return $reply;
    }

    /** RFC 2047 so a name or subject with non-ASCII text survives. */
    private static function encodeHeader(string $value): string
    {
        return preg_match('/[^\x20-\x7E]/', $value) ? '=?UTF-8?B?' . base64_encode($value) . '?=' : $value;
    }

    /** Header injection guard: a CR/LF in a subject or address would start a new header. */
    private static function headerSafe(string $value): string
    {
        return trim(str_replace(["\r", "\n"], ' ', $value));
    }

    private static function heloName(): string
    {
        return preg_replace('/[^A-Za-z0-9.-]/', '', (string) ($_SERVER['SERVER_NAME'] ?? '')) ?: 'localhost';
    }

    private static function env(string $key): string
    {
        $value = $_SERVER[$key] ?? getenv($key);

        return ($value === false || $value === null) ? '' : trim((string) $value);
    }
}
