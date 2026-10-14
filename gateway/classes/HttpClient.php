<?php

/**
 * Non-blocking HTTP/1.0 POSTs from the gateway to the backend services, run
 * inside `WebSocketServer`'s select loop: many requests can be in flight at
 * once, each answered by its callback when the service replies. HTTP/1.0
 * with `Connection: close` keeps parsing trivial — the body is whatever
 * arrives before the service closes the connection.
 */
class HttpClient implements StreamWatcher
{
    private const DNS_CACHE_SECONDS = 30;

    /**
     * @var array<int, array{socket: resource, out: string, in: string, done: callable,
     *   deadline: float, started: float}>
     */
    private $requests = [];
    /** @var array<string, array{0: string, 1: int}> host → [ip, resolved at] */
    private $dns = [];

    /**
     * POSTs `$body` (JSON) to `$url`. `$done(int $status, ?array $json, ?string $error)`
     * gets the status and decoded body, or status 0 and an error when there
     * was no usable answer.
     *
     * @param array<string, string> $headers
     */
    public function post(string $url, array $headers, string $body, callable $done, float $timeoutSeconds = 30.0): void
    {
        $parts = parse_url($url);
        $host = (string) ($parts['host'] ?? '');
        $port = (int) ($parts['port'] ?? 80);
        $path = (string) ($parts['path'] ?? '/');

        $ip = $this->resolve($host);
        $socket = $ip === null ? false : @stream_socket_client(
            "tcp://{$ip}:{$port}",
            $errno,
            $error,
            0,
            STREAM_CLIENT_CONNECT | STREAM_CLIENT_ASYNC_CONNECT
        );
        if (!$socket) {
            $done(0, null, "cannot connect to {$host}:{$port}");
            return;
        }
        stream_set_blocking($socket, false);

        $head = "POST {$path} HTTP/1.0\r\nHost: {$host}\r\nConnection: close\r\n"
            . "Content-Type: application/json\r\nContent-Length: " . strlen($body) . "\r\n";
        foreach ($headers as $name => $value) {
            $head .= $name . ': ' . str_replace(["\r", "\n"], ' ', $value) . "\r\n";
        }

        $now = microtime(true);
        $this->requests[(int) $socket] = [
            'socket' => $socket,
            'out' => $head . "\r\n" . $body,
            'in' => '',
            'done' => $done,
            'deadline' => $now + $timeoutSeconds,
            'started' => $now,
        ];
    }

    public function inFlight(): int
    {
        return count($this->requests);
    }

    public function readStreams(): array
    {
        $streams = [];
        foreach ($this->requests as $request) {
            if ($request['out'] === '') {
                $streams[] = $request['socket'];
            }
        }

        return $streams;
    }

    public function writeStreams(): array
    {
        $streams = [];
        foreach ($this->requests as $request) {
            if ($request['out'] !== '') {
                $streams[] = $request['socket'];
            }
        }

        return $streams;
    }

    public function onWritable($stream): void
    {
        $id = (int) $stream;
        if (!isset($this->requests[$id])) {
            return;
        }
        $written = @fwrite($stream, $this->requests[$id]['out']);
        if ($written === false || ($written === 0 && feof($stream))) {
            $this->fail($id, 'connection refused or reset');
            return;
        }
        $this->requests[$id]['out'] = (string) substr($this->requests[$id]['out'], $written);
    }

    public function onReadable($stream): void
    {
        $id = (int) $stream;
        if (!isset($this->requests[$id])) {
            return;
        }
        $data = @fread($stream, 65536);
        if ($data === false) {
            $this->fail($id, 'read failed');
            return;
        }
        if ($data !== '') {
            $this->requests[$id]['in'] .= $data;
            return;
        }
        if (feof($stream)) {
            $this->complete($id);
        }
    }

    public function tick(): void
    {
        $now = microtime(true);
        foreach ($this->requests as $id => $request) {
            if ($now > $request['deadline']) {
                $this->fail($id, 'timed out');
            }
        }
    }

    private function complete(int $id): void
    {
        $request = $this->requests[$id];
        unset($this->requests[$id]);
        @fclose($request['socket']);

        $split = strpos($request['in'], "\r\n\r\n");
        if ($split === false || !preg_match('#^HTTP/1\.[01] (\d{3})#', $request['in'], $match)) {
            ($request['done'])(0, null, $request['in'] === '' ? 'empty response' : 'malformed response');
            return;
        }

        $json = json_decode(substr($request['in'], $split + 4), true);
        if (!is_array($json)) {
            ($request['done'])(0, null, 'response was not JSON (status ' . $match[1] . ')');
            return;
        }

        ($request['done'])((int) $match[1], $json, null);
    }

    private function fail(int $id, string $error): void
    {
        $request = $this->requests[$id];
        unset($this->requests[$id]);
        @fclose($request['socket']);
        ($request['done'])(0, null, $error);
    }

    /** gethostbyname() blocks, so its answers are cached; an IP passes straight through. */
    private function resolve(string $host): ?string
    {
        if (filter_var($host, FILTER_VALIDATE_IP)) {
            return $host;
        }
        $cached = $this->dns[$host] ?? null;
        if ($cached && time() - $cached[1] < self::DNS_CACHE_SECONDS) {
            return $cached[0];
        }

        $ip = gethostbyname($host);
        if ($ip === $host) {
            // Keep serving the last good answer through a DNS hiccup.
            return $cached[0] ?? null;
        }
        $this->dns[$host] = [$ip, time()];

        return $ip;
    }
}
