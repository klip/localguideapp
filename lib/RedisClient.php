<?php

/**
 * A small blocking Redis client over plain PHP streams (no phpredis needed):
 * single commands, pipelines and blocking pops. Connects lazily, and
 * reconnects once if a long-lived process finds its connection gone.
 *
 * Configured from the environment: REDIS_HOST (default `redis`), REDIS_PORT
 * (6379), REDIS_PASSWORD (optional — sent with AUTH), REDIS_DB (0).
 */
class RedisClient
{
    private const CONNECT_TIMEOUT_SECONDS = 2;
    private const READ_TIMEOUT_SECONDS = 5;

    /** @var string */
    private $host;
    /** @var int */
    private $port;
    /** @var string */
    private $password;
    /** @var int */
    private $database;
    /** @var resource|null */
    private $socket;

    public function __construct(?string $host = null, ?int $port = null, ?string $password = null)
    {
        $this->host = $host ?? (getenv('REDIS_HOST') ?: 'redis');
        $this->port = $port ?? (int) (getenv('REDIS_PORT') ?: 6379);
        $this->password = $password ?? (string) (getenv('REDIS_PASSWORD') ?: '');
        $this->database = (int) (getenv('REDIS_DB') ?: 0);
    }

    /**
     * Runs one command and returns its reply.
     *
     * @throws RedisException on an error reply or a connection problem
     */
    public function command(string ...$args)
    {
        $reply = $this->pipeline([$args])[0];
        if ($reply instanceof RespError) {
            throw new RedisException('Redis: ' . $reply->message);
        }

        return $reply;
    }

    /**
     * Sends several commands in one round trip and returns their replies in
     * order; an error reply is a `RespError` in its slot.
     *
     * @param array<int, array<int, string|int>> $commands
     * @return array<int, mixed>
     * @throws RedisException on a connection problem
     */
    public function pipeline(array $commands, float $readTimeout = self::READ_TIMEOUT_SECONDS): array
    {
        $payload = '';
        foreach ($commands as $command) {
            $payload .= Resp::encode($command);
        }

        try {
            return $this->roundTrip($payload, count($commands), $readTimeout);
        } catch (RedisException $e) {
            // A worker that sat idle may find Redis closed its socket; one
            // fresh connection is worth a try before giving up.
            $this->disconnect();

            return $this->roundTrip($payload, count($commands), $readTimeout);
        }
    }

    /**
     * BRPOP with a timeout in seconds: `[list, value]`, or `null` when the
     * timeout passed with nothing to pop.
     */
    public function blockingPop(string $list, int $timeoutSeconds): ?array
    {
        $reply = $this->pipeline([['BRPOP', $list, (string) $timeoutSeconds]], $timeoutSeconds + self::READ_TIMEOUT_SECONDS)[0];
        if ($reply instanceof RespError) {
            throw new RedisException('Redis: ' . $reply->message);
        }

        return $reply;
    }

    public function disconnect(): void
    {
        if ($this->socket) {
            @fclose($this->socket);
        }
        $this->socket = null;
    }

    private function roundTrip(string $payload, int $replies, float $readTimeout): array
    {
        $this->connect();

        for ($written = 0; $written < strlen($payload);) {
            $n = @fwrite($this->socket, substr($payload, $written));
            if ($n === false || $n === 0) {
                $this->disconnect();
                throw new RedisException('Redis: write failed.');
            }
            $written += $n;
        }

        stream_set_timeout($this->socket, (int) ceil($readTimeout));
        $buffer = '';
        $offset = 0;
        $out = [];
        while (count($out) < $replies) {
            $parsed = Resp::parse($buffer, $offset);
            if ($parsed !== null) {
                [$out[], $offset] = $parsed;
                continue;
            }
            $chunk = @fread($this->socket, 65536);
            if ($chunk === false || $chunk === '') {
                $timedOut = (stream_get_meta_data($this->socket)['timed_out'] ?? false);
                $this->disconnect();
                throw new RedisException($timedOut ? 'Redis: read timed out.' : 'Redis: connection closed.');
            }
            $buffer .= $chunk;
        }

        return $out;
    }

    private function connect(): void
    {
        if ($this->socket) {
            return;
        }

        $socket = @stream_socket_client(
            'tcp://' . $this->host . ':' . $this->port,
            $errno,
            $error,
            self::CONNECT_TIMEOUT_SECONDS
        );
        if (!$socket) {
            throw new RedisException("Redis: cannot connect to {$this->host}:{$this->port} ({$error}).");
        }
        $this->socket = $socket;

        $setup = [];
        if ($this->password !== '') {
            $setup[] = ['AUTH', $this->password];
        }
        if ($this->database !== 0) {
            $setup[] = ['SELECT', (string) $this->database];
        }
        if ($setup) {
            $payload = '';
            foreach ($setup as $command) {
                $payload .= Resp::encode($command);
            }
            foreach ($this->roundTrip($payload, count($setup), self::READ_TIMEOUT_SECONDS) as $reply) {
                if ($reply instanceof RespError) {
                    $this->disconnect();
                    // The reply names the problem (WRONGPASS…), never the password.
                    throw new RedisException('Redis: ' . $reply->message);
                }
            }
        }
    }
}
