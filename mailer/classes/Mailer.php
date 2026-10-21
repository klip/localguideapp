<?php

/**
 * Sends plain-text email over SMTP with STARTTLS and AUTH LOGIN — enough for
 * Gmail (smtp.gmail.com:587 with an App Password), with no Composer and no
 * PHPMailer. Only the mail worker uses it (`MailWorker`); every other service
 * queues messages with `MailQueue` instead. Settings come from the worker's
 * environment:
 *
 *   SMTP_HOST, SMTP_PORT (default 587), SMTP_USER, SMTP_PASS
 *   MAIL_FROM (default SMTP_USER), MAIL_FROM_NAME (default "BeLikeLocal")
 *   MAIL_FORWARDS    — temporary: `address=forward-to` pairs, comma-separated,
 *                      for our own addresses with no mailbox behind them yet
 *                      (belikelocal.club has no mail service). Remove each
 *                      pair once the address can receive mail itself.
 *   MAIL_REDIRECT_TO — development only: every message goes to this address
 *                      instead, with the real recipient noted in the body.
 *
 * `send()` throws a `MailerException`; its `permanent` flag tells the worker
 * whether trying again could help (a 5xx reply, a bad address, no config:
 * no; a timeout or a 4xx: yes).
 */
class Mailer
{
    private const TIMEOUT_SECONDS = 10;

    public static function isConfigured(): bool
    {
        return self::env('SMTP_HOST') !== '' && self::env('SMTP_USER') !== '' && self::env('SMTP_PASS') !== '';
    }

    /** @throws MailerException */
    public function send(string $to, string $subject, string $body): void
    {
        if (!self::isConfigured()) {
            throw new MailerException('SMTP is not configured', true);
        }

        $forward = self::forwardFor($to);
        if ($forward !== null) {
            $body = "[Forwarded: addressed to {$to}]\n\n" . $body;
            $to = $forward;
        }

        $redirect = self::env('MAIL_REDIRECT_TO');
        if ($redirect !== '') {
            $body = "[Development: originally addressed to {$to}]\n\n" . $body;
            $to = $redirect;
        }

        $to = self::headerSafe($to);
        if (!filter_var($to, FILTER_VALIDATE_EMAIL)) {
            throw new MailerException('invalid recipient address', true);
        }

        try {
            $this->deliver(self::env('SMTP_HOST'), (int) (self::env('SMTP_PORT') ?: 587), self::env('SMTP_USER'), self::env('SMTP_PASS'), $to, $subject, $body);
        } catch (MailerException $e) {
            throw $e;
        } catch (Throwable $e) {
            throw new MailerException($e->getMessage(), false);
        }
    }

    /** Where `MAIL_FORWARDS` sends mail for `$to`, if anywhere. */
    private static function forwardFor(string $to): ?string
    {
        foreach (explode(',', self::env('MAIL_FORWARDS')) as $pair) {
            [$from, $target] = array_map('trim', array_pad(explode('=', $pair, 2), 2, ''));
            if ($from !== '' && strcasecmp($from, trim($to)) === 0 && filter_var($target, FILTER_VALIDATE_EMAIL)) {
                return $target;
            }
        }

        return null;
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
        $fromName = self::env('MAIL_FROM_NAME') ?: 'BeLikeLocal';
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
            // Never echo credentials: only the server's reply goes in the
            // message. 5xx is a permanent refusal; anything else (4xx, a
            // dropped connection) may work on a later attempt.
            throw new MailerException('unexpected SMTP reply: ' . trim($reply), $code >= 500 && $code < 600);
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

/** A failed send. `permanent` — retrying won't help. */
class MailerException extends Exception
{
    /** @var bool */
    public $permanent;

    public function __construct(string $message, bool $permanent)
    {
        parent::__construct($message);
        $this->permanent = $permanent;
    }
}
