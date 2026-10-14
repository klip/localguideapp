<?php

/**
 * A small RFC 6455 WebSocket server on plain PHP streams — no Composer, no
 * extensions — for the gateway (`bin/gateway.php`). It only speaks the
 * protocol: the HTTP upgrade handshake, text frames (fragmented or not),
 * ping/pong and close, plus a plain `GET /healthz` for probes. What the
 * messages mean is `Gateway`'s business, through the callbacks passed to
 * `run()`.
 *
 * One process, one `stream_select()` loop: every socket is non-blocking,
 * outgoing data is buffered per connection, and the loop wakes at least once
 * a second to call `onTick`. Watchers (`addWatcher()`) put their own sockets
 * in the same loop, which is how the gateway waits on backend services and
 * Redis without ever blocking a connection.
 *
 * `stream_select()` is limited to file descriptors below 1024, so one process
 * holds roughly a thousand connections; run more replicas for more.
 */
class WebSocketServer
{
    private const GUID = '258EAFA5-E914-47DA-95CA-C5AB0DC85B11';
    /** Largest message accepted — a profile photo is a ~1–2 MB data URI. */
    private const MAX_MESSAGE_BYTES = 4 * 1024 * 1024;
    private const MAX_HANDSHAKE_BYTES = 16 * 1024;
    /** Ping an idle connection after this long; drop one silent for twice as long. */
    private const PING_AFTER_SECONDS = 30;

    /** @var resource */
    private $server;
    /** @var callable(string): bool */
    private $allowOrigin;
    /**
     * @var array<int, array{socket: resource, handshaken: bool, in: string, out: string,
     *   fragments: string, lastSeen: int, pinged: bool, closing: bool}>
     */
    private $clients = [];
    /** @var StreamWatcher[] */
    private $watchers = [];

    /** @param callable(string): bool $allowOrigin given the handshake's Origin header ('' if none) */
    public function __construct(string $address, callable $allowOrigin)
    {
        $server = @stream_socket_server($address, $errno, $error);
        if (!$server) {
            throw new RuntimeException("Could not listen on {$address}: {$error} ({$errno})");
        }
        stream_set_blocking($server, false);
        $this->server = $server;
        $this->allowOrigin = $allowOrigin;
    }

    public function addWatcher(StreamWatcher $watcher): void
    {
        $this->watchers[] = $watcher;
    }

    /**
     * Serves forever.
     *
     * @param callable(int, array<string, string>): void $onOpen a connection finished its
     *   handshake; given its request headers (lower-cased names)
     * @param callable(int, string): void $onMessage a complete text message arrived
     * @param callable(int): void $onClose a connection went away (either side)
     * @param callable(): void $onTick about once a second
     */
    public function run(callable $onOpen, callable $onMessage, callable $onClose, callable $onTick): void
    {
        $lastTick = 0.0;

        while (true) {
            $read = [$this->server];
            $write = [];
            foreach ($this->clients as $client) {
                $read[] = $client['socket'];
                if ($client['out'] !== '') {
                    $write[] = $client['socket'];
                }
            }
            // Which watcher owns which of its sockets, by resource id.
            $owners = [];
            foreach ($this->watchers as $watcher) {
                foreach ($watcher->readStreams() as $stream) {
                    $read[] = $stream;
                    $owners[(int) $stream] = $watcher;
                }
                foreach ($watcher->writeStreams() as $stream) {
                    $write[] = $stream;
                    $owners[(int) $stream] = $watcher;
                }
            }
            $except = null;

            if (@stream_select($read, $write, $except, 1) === false) {
                // Interrupted by a signal — just go round again.
                continue;
            }

            foreach ($write as $socket) {
                if (isset($owners[(int) $socket])) {
                    $owners[(int) $socket]->onWritable($socket);
                    continue;
                }
                $this->flush((int) $socket, $onClose);
            }

            foreach ($read as $socket) {
                if ($socket === $this->server) {
                    $this->accept();
                    continue;
                }
                if (isset($owners[(int) $socket])) {
                    $owners[(int) $socket]->onReadable($socket);
                    continue;
                }
                $this->readFrom((int) $socket, $onOpen, $onMessage, $onClose);
            }

            if (microtime(true) - $lastTick >= 1.0) {
                $lastTick = microtime(true);
                $this->keepAlive($onClose);
                foreach ($this->watchers as $watcher) {
                    $watcher->tick();
                }
                $onTick();
            }
        }
    }

    /** Queues a text message. Silently ignored for an unknown or closing connection. */
    public function send(int $id, string $message): void
    {
        if (!isset($this->clients[$id]) || !$this->clients[$id]['handshaken'] || $this->clients[$id]['closing']) {
            return;
        }
        $this->clients[$id]['out'] .= self::frame(0x1, $message);
    }

    // ------------------------------------------------------------ internals

    private function accept(): void
    {
        $socket = @stream_socket_accept($this->server, 0);
        if (!$socket) {
            return;
        }
        stream_set_blocking($socket, false);
        $this->clients[(int) $socket] = [
            'socket' => $socket,
            'handshaken' => false,
            'in' => '',
            'out' => '',
            'fragments' => '',
            'lastSeen' => time(),
            'pinged' => false,
            'closing' => false,
        ];
    }

    private function readFrom(int $id, callable $onOpen, callable $onMessage, callable $onClose): void
    {
        if (!isset($this->clients[$id])) {
            return;
        }
        $data = @fread($this->clients[$id]['socket'], 65536);
        if ($data === false || ($data === '' && feof($this->clients[$id]['socket']))) {
            $this->drop($id, $onClose);
            return;
        }
        if ($data === '') {
            return;
        }

        $client = &$this->clients[$id];
        $client['in'] .= $data;
        $client['lastSeen'] = time();
        $client['pinged'] = false;

        if (!$client['handshaken']) {
            $end = strpos($client['in'], "\r\n\r\n");
            if ($end === false) {
                if (strlen($client['in']) > self::MAX_HANDSHAKE_BYTES) {
                    $this->drop($id, $onClose);
                }
                return;
            }
            $request = substr($client['in'], 0, $end);
            $client['in'] = (string) substr($client['in'], $end + 4);
            $headers = $this->handshake($id, $request);
            if ($headers === null) {
                return;
            }
            $onOpen($id, $headers);
        }

        $this->readFrames($id, $onMessage, $onClose);
    }

    /**
     * Answers the HTTP upgrade request and returns its headers; `null` (the
     * connection is answered and dropped) if it isn't a valid one — or if
     * it's the health probe.
     *
     * @return array<string, string>|null
     */
    private function handshake(int $id, string $request): ?array
    {
        $lines = explode("\r\n", $request);
        $headers = [];
        foreach (array_slice($lines, 1) as $line) {
            $parts = explode(':', $line, 2);
            if (count($parts) === 2) {
                $headers[strtolower(trim($parts[0]))] = trim($parts[1]);
            }
        }

        if (preg_match('#^GET /healthz(\?\S*)? HTTP/1\.[01]$#', $lines[0] ?? '')) {
            $this->rejectHandshake($id, '200 OK');
            return null;
        }

        $key = $headers['sec-websocket-key'] ?? '';
        $valid = preg_match('#^GET \S+ HTTP/1\.1$#', $lines[0] ?? '')
            && stripos($headers['upgrade'] ?? '', 'websocket') !== false
            && ($headers['sec-websocket-version'] ?? '') === '13'
            && strlen((string) base64_decode($key, true)) === 16;

        if (!$valid) {
            $this->rejectHandshake($id, '400 Bad Request');
            return null;
        }
        if (!($this->allowOrigin)($headers['origin'] ?? '')) {
            $this->rejectHandshake($id, '403 Forbidden');
            return null;
        }

        $accept = base64_encode(sha1($key . self::GUID, true));
        $this->clients[$id]['out'] .= "HTTP/1.1 101 Switching Protocols\r\n"
            . "Upgrade: websocket\r\nConnection: Upgrade\r\n"
            . "Sec-WebSocket-Accept: {$accept}\r\n\r\n";
        $this->clients[$id]['handshaken'] = true;

        return $headers;
    }

    private function rejectHandshake(int $id, string $status): void
    {
        @fwrite($this->clients[$id]['socket'], "HTTP/1.1 {$status}\r\nConnection: close\r\nContent-Length: 0\r\n\r\n");
        @fclose($this->clients[$id]['socket']);
        unset($this->clients[$id]);
    }

    /** Pulls every complete frame out of the input buffer. */
    private function readFrames(int $id, callable $onMessage, callable $onClose): void
    {
        while (isset($this->clients[$id])) {
            $buffer = $this->clients[$id]['in'];
            $length = strlen($buffer);
            if ($length < 2) {
                return;
            }

            $first = ord($buffer[0]);
            $second = ord($buffer[1]);
            $fin = ($first & 0x80) !== 0;
            $opcode = $first & 0x0f;
            $masked = ($second & 0x80) !== 0;
            $payloadLength = $second & 0x7f;
            $offset = 2;

            if ($payloadLength === 126) {
                if ($length < 4) {
                    return;
                }
                $payloadLength = unpack('n', substr($buffer, 2, 2))[1];
                $offset = 4;
            } elseif ($payloadLength === 127) {
                if ($length < 10) {
                    return;
                }
                $payloadLength = unpack('J', substr($buffer, 2, 8))[1];
                $offset = 10;
            }

            // Clients must mask every frame (RFC 6455 §5.1).
            if (!$masked || $payloadLength > self::MAX_MESSAGE_BYTES) {
                $this->closeWith($id, $masked ? 1009 : 1002);
                return;
            }
            if ($length < $offset + 4 + $payloadLength) {
                return;
            }

            $mask = substr($buffer, $offset, 4);
            $payload = substr($buffer, $offset + 4, $payloadLength);
            $this->clients[$id]['in'] = (string) substr($buffer, $offset + 4 + $payloadLength);
            $payload = $payload ^ str_repeat($mask, intdiv($payloadLength, 4) + 1);
            $payload = substr($payload, 0, $payloadLength);

            switch ($opcode) {
                case 0x0: // continuation
                case 0x1: // text
                    $fragments = $this->clients[$id]['fragments'] . $payload;
                    if (strlen($fragments) > self::MAX_MESSAGE_BYTES) {
                        $this->closeWith($id, 1009);
                        return;
                    }
                    if (!$fin) {
                        $this->clients[$id]['fragments'] = $fragments;
                        break;
                    }
                    $this->clients[$id]['fragments'] = '';
                    $onMessage($id, $fragments);
                    break;

                case 0x8: // close — answer it, then hang up once it's sent
                    $this->closeWith($id, 1000);
                    return;

                case 0x9: // ping
                    $this->clients[$id]['out'] .= self::frame(0xA, $payload);
                    break;

                case 0xA: // pong — lastSeen already bumped
                    break;

                default: // binary or reserved — not part of this protocol
                    $this->closeWith($id, 1003);
                    return;
            }
        }
    }

    private function flush(int $id, callable $onClose): void
    {
        if (!isset($this->clients[$id])) {
            return;
        }
        $client = &$this->clients[$id];
        $written = @fwrite($client['socket'], $client['out']);
        if ($written === false) {
            $this->drop($id, $onClose);
            return;
        }
        $client['out'] = (string) substr($client['out'], $written);

        if ($client['out'] === '' && $client['closing']) {
            $this->drop($id, $onClose);
        }
    }

    /** Pings quiet connections and drops ones that never answered the last ping. */
    private function keepAlive(callable $onClose): void
    {
        $now = time();
        foreach (array_keys($this->clients) as $id) {
            $client = $this->clients[$id];
            $idle = $now - $client['lastSeen'];
            if (!$client['handshaken']) {
                if ($idle > 10) {
                    $this->drop($id, $onClose);
                }
                continue;
            }
            if ($idle >= 2 * self::PING_AFTER_SECONDS && $client['pinged']) {
                $this->drop($id, $onClose);
            } elseif ($idle >= self::PING_AFTER_SECONDS && !$client['pinged']) {
                $this->clients[$id]['out'] .= self::frame(0x9, '');
                $this->clients[$id]['pinged'] = true;
            }
        }
    }

    /** Sends a close frame; the connection is dropped once it's flushed. */
    private function closeWith(int $id, int $code): void
    {
        $this->clients[$id]['out'] .= self::frame(0x8, pack('n', $code));
        $this->clients[$id]['closing'] = true;
        $this->clients[$id]['in'] = '';
    }

    private function drop(int $id, callable $onClose): void
    {
        if (!isset($this->clients[$id])) {
            return;
        }
        $wasOpen = $this->clients[$id]['handshaken'];
        @fclose($this->clients[$id]['socket']);
        unset($this->clients[$id]);
        if ($wasOpen) {
            $onClose($id);
        }
    }

    /** One unmasked, unfragmented server frame. */
    private static function frame(int $opcode, string $payload): string
    {
        $length = strlen($payload);
        $head = chr(0x80 | $opcode);
        if ($length < 126) {
            $head .= chr($length);
        } elseif ($length < 65536) {
            $head .= chr(126) . pack('n', $length);
        } else {
            $head .= chr(127) . pack('J', $length);
        }

        return $head . $payload;
    }
}
