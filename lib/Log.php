<?php

/**
 * Structured logging for every BeLikeLocal service: one JSON object per line on
 * stderr, which Docker (and later Kubernetes) collects; Grafana Alloy ships it
 * to Loki (see deploy/observability/). Nothing is written to files.
 *
 *   {"ts":"2026-10-14T09:12:03.512Z","level":"error","service":"api",
 *    "msg":"…","request_id":"9f2c…","action":"decide", …context}
 *
 * `service` comes from `install()`; `context()` adds fields to every later
 * line (the gateway's request id, the action) until `resetContext()`.
 * Levels below `LOG_LEVEL` (default `info`) are dropped.
 *
 * Never log secrets, passwords, login codes, reset links or session hashes.
 * Email addresses go through `maskEmail()`.
 */
final class Log
{
    private const LEVELS = ['debug' => 10, 'info' => 20, 'warning' => 30, 'error' => 40];

    /** @var string */
    private static $service = 'belikelocal';
    /** @var array<string, mixed> */
    private static $context = [];
    /** @var int|null */
    private static $minLevel;
    /** @var resource|null */
    private static $stream;

    /**
     * Names the service and turns PHP's own warnings, uncaught exceptions and
     * fatal errors into log lines too.
     */
    public static function install(string $service): void
    {
        self::$service = $service;

        set_error_handler(static function (int $severity, string $message, string $file, int $line): bool {
            if (!(error_reporting() & $severity)) {
                return false;
            }
            self::write($severity & (E_WARNING | E_USER_WARNING | E_NOTICE | E_USER_NOTICE | E_DEPRECATED | E_USER_DEPRECATED) ? 'warning' : 'error', $message, [
                'file' => $file,
                'line' => $line,
            ]);

            return true;
        });

        set_exception_handler(static function (Throwable $e): void {
            self::exception('Uncaught exception', $e);
        });

        register_shutdown_function(static function (): void {
            $error = error_get_last();
            if ($error && ($error['type'] & (E_ERROR | E_PARSE | E_CORE_ERROR | E_COMPILE_ERROR))) {
                self::write('error', $error['message'], ['file' => $error['file'], 'line' => $error['line'], 'fatal' => true]);
            }
        });
    }

    /** @param array<string, mixed> $fields added to every following line */
    public static function context(array $fields): void
    {
        self::$context = $fields + self::$context;
    }

    public static function resetContext(): void
    {
        self::$context = [];
    }

    public static function debug(string $message, array $context = []): void
    {
        self::write('debug', $message, $context);
    }

    public static function info(string $message, array $context = []): void
    {
        self::write('info', $message, $context);
    }

    public static function warning(string $message, array $context = []): void
    {
        self::write('warning', $message, $context);
    }

    public static function error(string $message, array $context = []): void
    {
        self::write('error', $message, $context);
    }

    /** An exception as an error line: its class, message and where it was thrown (no trace arguments). */
    public static function exception(string $message, Throwable $e, array $context = []): void
    {
        self::write('error', $message, $context + [
            'exception' => get_class($e),
            'error' => $e->getMessage(),
            'file' => $e->getFile(),
            'line' => $e->getLine(),
        ]);
    }

    /** "raihlini@gmail.com" → "ra•••@gmail.com" — enough to tell recipients apart in logs. */
    public static function maskEmail(string $email): string
    {
        $at = strrpos($email, '@');
        if ($at === false) {
            return '•••';
        }

        return substr($email, 0, min(2, $at)) . '•••' . substr($email, $at);
    }

    private static function write(string $level, string $message, array $context): void
    {
        if (self::LEVELS[$level] < self::minLevel()) {
            return;
        }

        $line = json_encode(
            ['ts' => self::timestamp(), 'level' => $level, 'service' => self::$service, 'msg' => $message]
                + $context + self::$context,
            JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE | JSON_PARTIAL_OUTPUT_ON_ERROR
        );

        if (self::$stream === null) {
            // php-fpm passes a worker's stderr through to the container log
            // (catch_workers_output); the CLI services write to it directly.
            self::$stream = fopen('php://stderr', 'wb') ?: null;
        }
        if (self::$stream) {
            fwrite(self::$stream, $line . "\n");
        }
    }

    private static function minLevel(): int
    {
        if (self::$minLevel === null) {
            $name = strtolower((string) (getenv('LOG_LEVEL') ?: 'info'));
            self::$minLevel = self::LEVELS[$name] ?? self::LEVELS['info'];
        }

        return self::$minLevel;
    }

    private static function timestamp(): string
    {
        $now = microtime(true);

        return gmdate('Y-m-d\TH:i:s', (int) $now) . sprintf('.%03dZ', (int) (($now - floor($now)) * 1000));
    }
}
