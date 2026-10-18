<?php

/**
 * The edge of the backend: every browser holds one WebSocket to a gateway,
 * and the gateway turns its messages into internal HTTP calls to the
 * services — never touching the database itself. It keeps nothing but the
 * open connections in memory, so any number of gateways can run behind a
 * load balancer, each with its own share of connections.
 *
 * Client → gateway (unchanged for the client)
 * - `{ type: "request", id, action, sessionHash?, ...payload }`, answered by
 *   `{ type: "response", id, status, body }`. Account actions
 *   (`AUTH_ACTIONS`) go to the auth service as they are; everything else to
 *   the API, after the auth service has turned `sessionHash` into a user id
 *   (`resolve`, which also slides the session). A hash that resolves to no
 *   one is answered 401 here; the API itself never sees a hash.
 * - `{ type: "auth", sessionHash }` — who's on this connection, so pushes
 *   reach them; `""` signs it out. Answered by `{ type: "auth", userId }`.
 *
 * Gateway → client
 * - `{ type: "notification", notification }`, `{ type: "badges", matches,
 *   trips, messages }`, `{ type: "message", message }` (a chat message,
 *   live only) and `{ type: "messages_read", reader_id, up_to_id, read_at }`,
 *   which the services publish to Redis channel `user:<id>`
 *   (`Push`). This gateway subscribes to that channel while it holds a
 *   connection for the user, and on sign-in fetches what was waiting
 *   (`pushState`).
 *
 * Every request runs concurrently (`HttpClient`), so a slow backend call
 * never holds up anyone else.
 */
class Gateway
{
    /** Routed to the auth service, `sessionHash` and all. */
    private const AUTH_ACTIONS = [
        'register', 'login', 'verifyTwoFactor', 'resendTwoFactor', 'requestPasswordReset',
        'resetPassword', 'forgetTrustedDevices', 'changePassword', 'logout', 'session',
    ];
    /** Service actions only the gateway may call. */
    private const INTERNAL_ACTIONS = ['resolve', 'peekMany', 'pushState'];
    /** How often bound sessions are re-checked; one that expired stops getting pushes. */
    private const SESSION_CHECK_EVERY_SECONDS = 20;
    private const SERVICE_TIMEOUT_SECONDS = 25.0;
    /** Notification ids remembered per connection, so one is never shown twice. */
    private const SEEN_NOTES = 50;
    /** Redis channel per user — the API publishes to it (`Push::channel()`). */
    private const CHANNEL_PREFIX = 'user:';
    private const UNAVAILABLE = 'The service is unavailable right now. Please try again in a moment.';

    /** @var WebSocketServer|null */
    private $server;
    /** @var HttpClient */
    private $http;
    /** @var RedisSubscriber */
    private $redis;
    /** @var string */
    private $apiUrl;
    /** @var string */
    private $authUrl;
    /** @var string */
    private $internalToken;

    /**
     * @var array<int, array{userId: ?int, hash: ?string, userAgent: string, badges: ?string,
     *   bindSeq: int, notes: array<int, true>}> by connection id
     */
    private $connections = [];
    /** @var array<int, array<int, true>> user id → their connection ids */
    private $users = [];
    private $lastSessionCheck = 0;

    public function __construct(string $apiUrl, string $authUrl, string $internalToken)
    {
        $this->apiUrl = $apiUrl;
        $this->authUrl = $authUrl;
        $this->internalToken = $internalToken;
        $this->http = new HttpClient();
        $this->redis = new RedisSubscriber(
            function (string $channel, string $payload): void {
                $this->onPublished($channel, $payload);
            },
            function (): void {
                // Whatever was published while the subscription was down is
                // gone; ask for each user's current state instead.
                foreach ($this->users as $userId => $connIds) {
                    $this->fetchState($userId, array_keys($connIds));
                }
            }
        );
    }

    public function run(WebSocketServer $server): void
    {
        $this->server = $server;
        $server->addWatcher($this->http);
        $server->addWatcher($this->redis);
        $this->redis->connect();

        $server->run(
            function (int $id, array $headers): void {
                $this->connections[$id] = [
                    'userId' => null,
                    'hash' => null,
                    'userAgent' => mb_substr((string) ($headers['user-agent'] ?? ''), 0, 255),
                    'badges' => null,
                    'bindSeq' => 0,
                    'notes' => [],
                ];
            },
            function (int $id, string $message): void {
                $this->onMessage($id, $message);
            },
            function (int $id): void {
                $this->detach($id);
                unset($this->connections[$id]);
            },
            function (): void {
                $this->onTick();
            }
        );
    }

    private function onMessage(int $id, string $raw): void
    {
        $message = json_decode($raw, true);
        if (!is_array($message)) {
            return;
        }

        try {
            switch ($message['type'] ?? '') {
                case 'request':
                    $this->onRequest($id, $message);
                    break;
                case 'auth':
                    $this->bind($id, (string) ($message['sessionHash'] ?? ''));
                    break;
            }
        } catch (Throwable $e) {
            Log::exception('Gateway failed on a message', $e);
            if (($message['type'] ?? '') === 'request') {
                $this->send($id, ['type' => 'response', 'id' => $message['id'] ?? null, 'status' => 500, 'body' => ['error' => 'Unexpected server error.']]);
            }
        }
    }

    private function onRequest(int $id, array $message): void
    {
        $clientRequestId = $message['id'] ?? null;
        $action = (string) ($message['action'] ?? '');
        unset($message['type'], $message['id']);

        $requestId = bin2hex(random_bytes(8));
        $started = microtime(true);
        $reply = function (int $status, array $body, ?int $userId = null) use ($id, $clientRequestId, $action, $requestId, $started): void {
            $this->send($id, ['type' => 'response', 'id' => $clientRequestId, 'status' => $status, 'body' => $body]);
            Log::info('Request', array_filter([
                'request_id' => $requestId,
                'action' => $action,
                'status' => $status,
                'ms' => (int) round((microtime(true) - $started) * 1000),
                'user_id' => $userId,
            ], static function ($value) {
                return $value !== null;
            }));
        };

        if (in_array($action, self::INTERNAL_ACTIONS, true)) {
            $reply(404, ['error' => 'Unknown action.']);
            return;
        }

        $userAgent = $this->connections[$id]['userAgent'] ?? '';
        if (in_array($action, self::AUTH_ACTIONS, true)) {
            $this->call($this->authUrl, $message, null, $requestId, $userAgent, $reply);
            return;
        }

        $hash = (string) ($message['sessionHash'] ?? '');
        unset($message['sessionHash']);
        if ($hash === '') {
            unset($message['actingRole']);
            $this->call($this->apiUrl, $message, null, $requestId, $userAgent, $reply);
            return;
        }

        $this->call($this->authUrl, ['action' => 'resolve', 'sessionHash' => $hash], null, $requestId, $userAgent,
            function (int $status, array $body) use ($message, $requestId, $userAgent, $reply): void {
                if ($status !== 200) {
                    $reply($status === 503 ? 503 : 500, ['error' => $status === 503 ? self::UNAVAILABLE : 'Unexpected server error.']);
                    return;
                }
                $userId = (int) ($body['userId'] ?? 0);
                if ($userId <= 0) {
                    $reply(401, ['error' => 'Your session has expired. Please log in again.']);
                    return;
                }
                // The role this request acts as (an account can hold both):
                // one the user holds, else their first (registration) role.
                $roles = array_values(array_filter((array) ($body['roles'] ?? []), 'is_string'));
                $actingRole = (string) ($message['actingRole'] ?? '');
                unset($message['actingRole']);
                if ($actingRole !== '' && !in_array($actingRole, $roles, true)) {
                    $reply(403, ['error' => 'Your account doesn’t have that role.']);
                    return;
                }
                $actingRole = $actingRole !== '' ? $actingRole : ($roles[0] ?? null);
                $this->call($this->apiUrl, $message, $userId, $requestId, $userAgent,
                    function (int $status, array $body) use ($reply, $userId): void {
                        $reply($status, $body, $userId);
                    }, $actingRole);
            });
    }

    /**
     * One internal HTTP call. `$done(int $status, array $body)` always runs:
     * an unreachable service comes back as 503.
     */
    private function call(string $url, array $payload, ?int $userId, string $requestId, string $userAgent, callable $done, ?string $actingRole = null): void
    {
        $headers = [
            'X-Internal-Token' => $this->internalToken,
            'X-Request-Id' => $requestId,
            'X-Client-User-Agent' => $userAgent,
        ];
        if ($userId) {
            $headers['X-User-Id'] = (string) $userId;
        }
        if ($userId && $actingRole) {
            $headers['X-Acting-Role'] = $actingRole;
        }

        $this->http->post($url, $headers, (string) json_encode($payload), static function (int $status, ?array $json, ?string $error) use ($url, $payload, $requestId, $done): void {
            if ($status === 0 || $json === null) {
                Log::error('Backend service unreachable', [
                    'url' => $url,
                    'action' => $payload['action'] ?? '',
                    'request_id' => $requestId,
                    'error' => $error,
                ]);
                $done(503, ['error' => self::UNAVAILABLE]);
                return;
            }
            $done($status, $json);
        }, self::SERVICE_TIMEOUT_SECONDS);
    }

    // ------------------------------------------------------------ pushes

    private function bind(int $id, string $hash): void
    {
        if (!isset($this->connections[$id])) {
            return;
        }
        $this->detach($id);
        $seq = ++$this->connections[$id]['bindSeq'];

        if ($hash === '') {
            $this->send($id, ['type' => 'auth', 'userId' => null]);
            return;
        }

        $this->call($this->authUrl, ['action' => 'resolve', 'sessionHash' => $hash], null, bin2hex(random_bytes(8)), '',
            function (int $status, array $body) use ($id, $seq, $hash): void {
                // The connection closed, or re-identified, while we waited.
                if (!isset($this->connections[$id]) || $this->connections[$id]['bindSeq'] !== $seq) {
                    return;
                }
                $userId = $status === 200 ? (int) ($body['userId'] ?? 0) : 0;
                if ($userId <= 0) {
                    $this->send($id, ['type' => 'auth', 'userId' => null]);
                    return;
                }

                $this->connections[$id]['userId'] = $userId;
                $this->connections[$id]['hash'] = $hash;
                $this->users[$userId][$id] = true;
                $this->redis->subscribe(self::CHANNEL_PREFIX . $userId);
                $this->send($id, ['type' => 'auth', 'userId' => $userId]);
                $this->fetchState($userId, [$id]);
            });
    }

    /** Takes a connection off its user (unsubscribing when it was their last one here). */
    private function detach(int $id): void
    {
        $userId = $this->connections[$id]['userId'] ?? null;
        if (isset($this->connections[$id])) {
            $this->connections[$id]['userId'] = null;
            $this->connections[$id]['hash'] = null;
            $this->connections[$id]['badges'] = null;
        }
        if (!$userId) {
            return;
        }
        unset($this->users[$userId][$id]);
        if (!$this->users[$userId]) {
            unset($this->users[$userId]);
            $this->redis->unsubscribe(self::CHANNEL_PREFIX . $userId);
        }
    }

    /** What these connections should be showing: the unread backlog and the counters. */
    private function fetchState(int $userId, array $connIds): void
    {
        $this->call($this->apiUrl, ['action' => 'pushState'], $userId, bin2hex(random_bytes(8)), '',
            function (int $status, array $body) use ($userId, $connIds): void {
                if ($status !== 200) {
                    return;
                }
                foreach ($connIds as $id) {
                    if (($this->connections[$id]['userId'] ?? null) !== $userId) {
                        continue;
                    }
                    foreach ((array) ($body['notifications'] ?? []) as $note) {
                        $this->deliverNote($id, (array) $note);
                    }
                    $this->deliverBadges($id, (array) ($body['badges'] ?? []));
                }
            });
    }

    private function onPublished(string $channel, string $payload): void
    {
        $userId = (int) substr($channel, strlen(self::CHANNEL_PREFIX));
        $message = json_decode($payload, true);
        if ($userId <= 0 || !is_array($message)) {
            return;
        }

        foreach (array_keys($this->users[$userId] ?? []) as $id) {
            if (($message['type'] ?? '') === 'notification') {
                $this->deliverNote($id, (array) ($message['notification'] ?? []));
            } elseif (($message['type'] ?? '') === 'badges') {
                $this->deliverBadges($id, $message);
            } elseif (($message['type'] ?? '') === 'message' && is_array($message['message'] ?? null)) {
                // A chat message (`Messages`): live only — the conversation
                // is fetched again whenever it's opened, so nothing to replay.
                $this->send($id, ['type' => 'message', 'message' => $message['message']]);
            } elseif (($message['type'] ?? '') === 'messages_read') {
                // The other side read up to `up_to_id` — the sender's "Seen".
                $this->send($id, [
                    'type' => 'messages_read',
                    'reader_id' => (int) ($message['reader_id'] ?? 0),
                    'up_to_id' => (int) ($message['up_to_id'] ?? 0),
                    'read_at' => (string) ($message['read_at'] ?? ''),
                ]);
            }
        }
    }

    private function deliverNote(int $id, array $note): void
    {
        $noteId = (int) ($note['id'] ?? 0);
        if ($noteId <= 0 || isset($this->connections[$id]['notes'][$noteId])) {
            return;
        }
        $this->connections[$id]['notes'][$noteId] = true;
        if (count($this->connections[$id]['notes']) > self::SEEN_NOTES) {
            // array_shift() would renumber the id keys.
            unset($this->connections[$id]['notes'][array_key_first($this->connections[$id]['notes'])]);
        }
        $this->send($id, ['type' => 'notification', 'notification' => $note]);
    }

    private function deliverBadges(int $id, array $counts): void
    {
        $counts = [
            'matches' => (int) ($counts['matches'] ?? 0),
            'trips' => (int) ($counts['trips'] ?? 0),
            'messages' => (int) ($counts['messages'] ?? 0),
        ];
        $encoded = json_encode($counts);
        if (!isset($this->connections[$id]) || $this->connections[$id]['badges'] === $encoded) {
            return;
        }
        $this->connections[$id]['badges'] = $encoded;
        $this->send($id, ['type' => 'badges'] + $counts);
    }

    private function onTick(): void
    {
        if (time() - $this->lastSessionCheck < self::SESSION_CHECK_EVERY_SECONDS) {
            return;
        }
        $this->lastSessionCheck = time();

        $hashes = [];
        foreach ($this->connections as $connection) {
            if ($connection['hash'] !== null) {
                $hashes[$connection['hash']] = true;
            }
        }
        if (!$hashes) {
            return;
        }

        // Without sliding them: an open tab mustn't keep a session alive.
        $this->call($this->authUrl, ['action' => 'peekMany', 'hashes' => array_keys($hashes)], null, bin2hex(random_bytes(8)), '',
            function (int $status, array $body) use ($hashes): void {
                if ($status !== 200) {
                    return;
                }
                $live = (array) ($body['live'] ?? []);
                foreach ($this->connections as $id => $connection) {
                    $hash = $connection['hash'];
                    if ($hash !== null && isset($hashes[$hash]) && !isset($live[$hash])) {
                        $this->detach($id);
                        $this->send($id, ['type' => 'auth', 'userId' => null]);
                    }
                }
            });
    }

    private function send(int $id, array $message): void
    {
        $this->server->send($id, (string) json_encode($message));
    }
}
