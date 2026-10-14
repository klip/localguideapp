<?php

/**
 * The gateway's Redis pub/sub connection, non-blocking inside the select
 * loop. It subscribes to `user:<id>` for every user with a socket on this
 * gateway, and hands each published message to `$onMessage`.
 *
 * If Redis goes away it reconnects (every few seconds), re-subscribes, and
 * calls `$onReconnect` — messages published meanwhile were lost, so the
 * gateway re-fetches what its users should be seeing.
 */
class RedisSubscriber implements StreamWatcher
{
    private const RETRY_SECONDS = 3;

    /** @var string */
    private $host;
    /** @var int */
    private $port;
    /** @var string */
    private $password;
    /** @var callable(string, string): void */
    private $onMessage;
    /** @var callable(): void */
    private $onReconnect;
    /** @var resource|null */
    private $socket;
    private $in = '';
    private $out = '';
    /** @var array<string, true> */
    private $channels = [];
    private $retryAt = 0;
    private $everConnected = false;

    public function __construct(callable $onMessage, callable $onReconnect)
    {
        $this->host = getenv('REDIS_HOST') ?: 'redis';
        $this->port = (int) (getenv('REDIS_PORT') ?: 6379);
        $this->password = (string) (getenv('REDIS_PASSWORD') ?: '');
        $this->onMessage = $onMessage;
        $this->onReconnect = $onReconnect;
    }

    public function subscribe(string $channel): void
    {
        if (isset($this->channels[$channel])) {
            return;
        }
        $this->channels[$channel] = true;
        if ($this->socket) {
            $this->out .= Resp::encode(['SUBSCRIBE', $channel]);
        }
    }

    public function unsubscribe(string $channel): void
    {
        if (!isset($this->channels[$channel])) {
            return;
        }
        unset($this->channels[$channel]);
        if ($this->socket) {
            $this->out .= Resp::encode(['UNSUBSCRIBE', $channel]);
        }
    }

    public function isConnected(): bool
    {
        return $this->socket !== null;
    }

    public function readStreams(): array
    {
        return $this->socket ? [$this->socket] : [];
    }

    public function writeStreams(): array
    {
        return $this->socket && $this->out !== '' ? [$this->socket] : [];
    }

    public function onWritable($stream): void
    {
        if ($stream !== $this->socket) {
            return;
        }
        $written = @fwrite($stream, $this->out);
        if ($written === false) {
            $this->lost('write failed');
            return;
        }
        $this->out = (string) substr($this->out, $written);
    }

    public function onReadable($stream): void
    {
        if ($stream !== $this->socket) {
            return;
        }
        $data = @fread($stream, 65536);
        if ($data === false || ($data === '' && feof($stream))) {
            $this->lost('connection closed');
            return;
        }
        $this->in .= $data;

        $offset = 0;
        try {
            while (($parsed = Resp::parse($this->in, $offset)) !== null) {
                [$reply, $offset] = $parsed;
                $this->handle($reply);
            }
        } catch (RedisException $e) {
            $this->lost($e->getMessage());
            return;
        }
        $this->in = (string) substr($this->in, $offset);
    }

    public function tick(): void
    {
        if ($this->socket || time() < $this->retryAt) {
            return;
        }
        $this->connect();
    }

    /** Connects (blocking, but only for up to two seconds) and re-subscribes. */
    public function connect(): void
    {
        $socket = @stream_socket_client("tcp://{$this->host}:{$this->port}", $errno, $error, 2);
        if (!$socket) {
            $this->retryAt = time() + self::RETRY_SECONDS;
            Log::warning('Redis unavailable; pushes paused', ['error' => $error]);
            return;
        }
        stream_set_blocking($socket, false);
        $this->socket = $socket;
        $this->in = '';
        $this->out = $this->password !== '' ? Resp::encode(['AUTH', $this->password]) : '';
        if ($this->channels) {
            $this->out .= Resp::encode(array_merge(['SUBSCRIBE'], array_keys($this->channels)));
        }

        if ($this->everConnected) {
            Log::info('Redis reconnected; pushes resumed', ['channels' => count($this->channels)]);
            ($this->onReconnect)();
        }
        $this->everConnected = true;
    }

    private function handle($reply): void
    {
        if ($reply instanceof RespError) {
            // A bad password, most likely — nothing else works until it's fixed.
            Log::error('Redis refused the subscriber', ['error' => $reply->message]);
            return;
        }
        if (is_array($reply) && ($reply[0] ?? null) === 'message' && count($reply) === 3) {
            ($this->onMessage)((string) $reply[1], (string) $reply[2]);
        }
        // `+OK` for AUTH and the (un)subscribe confirmations need nothing.
    }

    private function lost(string $why): void
    {
        if ($this->socket) {
            @fclose($this->socket);
        }
        $this->socket = null;
        $this->retryAt = time() + self::RETRY_SECONDS;
        Log::warning('Lost the Redis subscription; reconnecting', ['error' => $why]);
    }
}
