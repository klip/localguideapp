<?php

/**
 * The edge of the backend: every browser holds one WebSocket to a gateway,
 * and the gateway turns its messages into internal HTTP calls to the
 * services — never touching the database itself. It keeps nothing but the
 * open connections in memory, so any number of gateways can run behind a
 * load balancer, each with its own share of connections.
 *
 * Client → gateway
 * - `{ type: "request", id, action, accessToken?, actingRole?, ...payload }`,
 *   answered by `{ type: "response", id, status, body }`. The caller is
 *   identified by an **access token**, verified right here with the public key
 *   (`Jwt`, no call to anyone); a token that's forged, expired or revoked is
 *   answered 401 here. Signing in isn't done here at all — it's HTTP, for the
 *   cookie (`auth/classes/AuthHttp.php`). Account actions
 *   (`AUTH_ACTIONS`) go to the auth service, the rest to the API, with the
 *   caller as `X-User-Id` and their token as `Authorization: Bearer` (each
 *   service verifies it again — `ServiceHttp`).
 * - `{ type: "auth", accessToken }` — who's on this connection,
 *   so pushes reach them; empty signs it out. Answered by `{ type: "auth",
 *   userId }`. A token binding ends when the token expires, unless the client
 *   re-binds with a fresh one (it does after every refresh).
 * - Revoked sign-ins (`Revocations`: logout, password reset, sign out
 *   everywhere, a reused refresh token) arrive on Redis `auth:revoked`; the
 *   gateway keeps them in memory, refuses those tokens and unbinds their
 *   sockets at once, rather than when the tokens expire.
 *
 * Gateway → client
 * - `{ type: "notification", notification }`, `{ type: "badges", matches,
 *   trips, messages }`, `{ type: "message", message }` (a chat message,
 *   live only), `{ type: "messages_read", reader_id, up_to_id, read_at }`
 *   and `{ type: "trip_plan", planId, status }` (a trip's guides are ready),
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
    /** Routed to the auth service (signing in itself is HTTP — `AuthHttp`). */
    private const AUTH_ACTIONS = [
        'resendTwoFactor', 'requestPasswordReset', 'resetPassword', 'forgetTrustedDevices',
        'changePassword', 'addRole', 'myDevices', 'signOutDevice', 'secureAccount',
    ];
    /** Refused here: sign-in actions (HTTP only now) and service actions only the gateway may call. */
    private const NOT_FOR_CLIENTS = ['register', 'login', 'verifyTwoFactor', 'logout', 'session', 'pushState', 'staffCancelBooking', 'staffComplaintReviewed', 'staffSettleDispute'];
    /** How often expired token bindings and old revocations are cleared. */
    private const HOUSEKEEPING_EVERY_SECONDS = 20;
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
     * @var array<int, array{userId: ?int, tokenExpires: ?int, tokenSid: ?string,
     *   tokenIat: ?int, userAgent: string, badges: ?string, notes: array<int, true>}> by connection id
     */
    private $connections = [];
    /** @var array<int, array<int, true>> user id → their connection ids */
    private $users = [];
    private $lastHousekeeping = 0;
    /** @var array<string, int> revoked sign-in (sid) → when */
    private $revokedSids = [];
    /** @var array<int, int> user id → tokens issued at or before this are revoked */
    private $revokedUsers = [];

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
                // gone; ask for each user's current state instead — and
                // re-read the revocations that may have been missed.
                $this->loadRevocations();
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
        $this->redis->subscribe(Revocations::CHANNEL);
        $this->redis->connect();
        $this->loadRevocations();

        $server->run(
            function (int $id, array $headers): void {
                $this->connections[$id] = [
                    'userId' => null,
                    'tokenExpires' => null,
                    'tokenSid' => null,
                    'tokenIat' => null,
                    'userAgent' => mb_substr((string) ($headers['user-agent'] ?? ''), 0, 255),
                    // The browser's address, from nginx's X-Forwarded-For (`ClientIp`).
                    'clientIp' => ClientIp::resolve((string) ($headers[':peer'] ?? ''), (string) ($headers['x-forwarded-for'] ?? '')),
                    'badges' => null,
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
                    $this->bindToken($id, (string) ($message['accessToken'] ?? ''));
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

        if (in_array($action, self::NOT_FOR_CLIENTS, true)) {
            $reply(404, ['error' => 'Unknown action.']);
            return;
        }

        $userAgent = $this->connections[$id]['userAgent'] ?? '';
        $clientIp = $this->connections[$id]['clientIp'] ?? '';
        $token = (string) ($message['accessToken'] ?? '');
        unset($message['accessToken']);
        $claims = $token !== '' ? $this->verifyToken($token) : null;

        if (in_array($action, self::AUTH_ACTIONS, true)) {
            // Identified by a valid token, the auth service gets the caller
            // (changePassword, addRole…); some need none (a reset link).
            unset($message['actingRole']);
            if ($claims !== null) {
                $this->call($this->authUrl, $message, (int) $claims['sub'], $requestId, $userAgent, $reply, null, $token, $clientIp);
            } else {
                $this->call($this->authUrl, $message, null, $requestId, $userAgent, $reply, null, null, $clientIp);
            }
            return;
        }

        if ($token !== '') {
            if ($claims === null) {
                $reply(401, ['error' => 'Your session has expired. Please log in again.']);
                return;
            }
            $userId = (int) $claims['sub'];
            $actingRole = $this->actingRole($message, array_values(array_filter((array) ($claims['roles'] ?? []), 'is_string')));
            if ($actingRole === false) {
                $reply(403, ['error' => 'Your account doesn’t have that role.']);
                return;
            }
            $this->call($this->apiUrl, $message, $userId, $requestId, $userAgent,
                function (int $status, array $body) use ($reply, $userId): void {
                    $reply($status, $body, $userId);
                }, $actingRole, $token, $clientIp);
            return;
        }

        // Anonymous: the API decides what a signed-out caller may do.
        unset($message['actingRole']);
        $this->call($this->apiUrl, $message, null, $requestId, $userAgent, $reply, null, null, $clientIp);
    }

    /**
     * The role a request acts as (an account can hold both): the one it asks
     * for if the user holds it, else their first (registration) role; `false`
     * when it asks for one they don't hold. Takes `actingRole` out of the
     * payload.
     *
     * @return string|null|false
     */
    private function actingRole(array &$message, array $roles)
    {
        $asked = (string) ($message['actingRole'] ?? '');
        unset($message['actingRole']);
        if ($asked !== '' && !in_array($asked, $roles, true)) {
            return false;
        }

        return $asked !== '' ? $asked : ($roles[0] ?? null);
    }

    /**
     * One internal HTTP call. `$done(int $status, array $body)` always runs:
     * an unreachable service comes back as 503. `$bearer` is the caller's
     * access token, which the service verifies again.
     */
    private function call(string $url, array $payload, ?int $userId, string $requestId, string $userAgent, callable $done, ?string $actingRole = null, ?string $bearer = null, string $clientIp = ''): void
    {
        $headers = [
            'X-Internal-Token' => $this->internalToken,
            'X-Request-Id' => $requestId,
            'X-Client-User-Agent' => $userAgent,
        ];
        if ($clientIp !== '') {
            $headers['X-Client-Ip'] = $clientIp;
        }
        if ($bearer !== null) {
            $headers['Authorization'] = 'Bearer ' . $bearer;
        }
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

    /**
     * Binds a connection by access token — verified here, no call needed.
     * The binding lasts until the token expires (`onTick()`); the client
     * re-binds with each new token.
     */
    private function bindToken(int $id, string $token): void
    {
        if (!isset($this->connections[$id])) {
            return;
        }
        $claims = $token !== '' ? $this->verifyToken($token) : null;
        $userId = (int) ($claims['sub'] ?? 0);

        // Re-binding the same user with a fresh token: keep the subscription.
        if ($userId > 0 && $this->connections[$id]['userId'] === $userId) {
            $this->rememberToken($id, $claims);
            $this->send($id, ['type' => 'auth', 'userId' => $userId]);
            return;
        }

        $this->detach($id);
        if ($userId <= 0) {
            $this->send($id, ['type' => 'auth', 'userId' => null]);
            return;
        }

        $this->connections[$id]['userId'] = $userId;
        $this->rememberToken($id, $claims);
        $this->users[$userId][$id] = true;
        $this->redis->subscribe(self::CHANNEL_PREFIX . $userId);
        $this->send($id, ['type' => 'auth', 'userId' => $userId]);
        $this->fetchState($userId, [$id]);
    }

    private function rememberToken(int $id, array $claims): void
    {
        $this->connections[$id]['tokenExpires'] = (int) $claims['exp'];
        $this->connections[$id]['tokenSid'] = (string) ($claims['sid'] ?? '');
        $this->connections[$id]['tokenIat'] = (int) ($claims['iat'] ?? 0);
    }

    /** A token's claims if it's genuine, current and not revoked; else `null`. */
    private function verifyToken(string $token): ?array
    {
        $claims = Jwt::verify($token, 'app');

        return $claims !== null && !Revocations::isRevoked($claims, $this->revokedSids, $this->revokedUsers) ? $claims : null;
    }

    /** Everything revoked lately (`Revocations::load()`), at start-up and after a Redis outage. */
    private function loadRevocations(): void
    {
        try {
            [$sids, $users] = Revocations::load(new RedisClient());
        } catch (Throwable $e) {
            Log::warning('Could not load revocations', ['error' => $e->getMessage()]);
            return;
        }
        $this->revokedSids = $sids + $this->revokedSids;
        $this->revokedUsers = $users + $this->revokedUsers;
        $this->dropRevokedBindings();
    }

    /** `{ sid | userId, at }` from `auth:revoked`. */
    private function onRevoked(array $message): void
    {
        $at = (int) ($message['at'] ?? time());
        if (isset($message['sid'])) {
            $this->revokedSids[(string) $message['sid']] = $at;
        }
        if (isset($message['userId'])) {
            $this->revokedUsers[(int) $message['userId']] = $at;
        }
        $this->dropRevokedBindings();
    }

    /** Unbinds every socket whose token is now revoked — no more pushes. */
    private function dropRevokedBindings(): void
    {
        foreach ($this->connections as $id => $connection) {
            if ($connection['tokenExpires'] === null) {
                continue;
            }
            $claims = ['sid' => $connection['tokenSid'], 'sub' => $connection['userId'], 'iat' => $connection['tokenIat']];
            if (Revocations::isRevoked($claims, $this->revokedSids, $this->revokedUsers)) {
                $this->detach($id);
                $this->send($id, ['type' => 'auth', 'userId' => null]);
            }
        }
    }

    /** Takes a connection off its user (unsubscribing when it was their last one here). */
    private function detach(int $id): void
    {
        $userId = $this->connections[$id]['userId'] ?? null;
        if (isset($this->connections[$id])) {
            $this->connections[$id]['userId'] = null;
            $this->connections[$id]['tokenExpires'] = null;
            $this->connections[$id]['tokenSid'] = null;
            $this->connections[$id]['tokenIat'] = null;
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
        if ($channel === Revocations::CHANNEL) {
            $message = json_decode($payload, true);
            if (is_array($message)) {
                $this->onRevoked($message);
            }
            return;
        }

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
            } elseif (($message['type'] ?? '') === 'trip_plan') {
                // A trip's guide suggestions are ready (or failed) — `TripPlanner`.
                // Live only: the planner page fetches the plan when opened.
                $this->send($id, [
                    'type' => 'trip_plan',
                    'planId' => (int) ($message['planId'] ?? 0),
                    'status' => (string) ($message['status'] ?? ''),
                ]);
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
        if (time() - $this->lastHousekeeping < self::HOUSEKEEPING_EVERY_SECONDS) {
            return;
        }
        $this->lastHousekeeping = time();

        // Revocations outlive every token they could match only so long.
        $cutoff = time() - Revocations::TTL_SECONDS;
        $this->revokedSids = array_filter($this->revokedSids, static fn(int $at): bool => $at > $cutoff);
        $this->revokedUsers = array_filter($this->revokedUsers, static fn(int $at): bool => $at > $cutoff);

        // Token bindings end with their token (a minute's leeway, as in `Jwt`).
        foreach ($this->connections as $id => $connection) {
            if ($connection['tokenExpires'] !== null && $connection['tokenExpires'] + 60 < time()) {
                $this->detach($id);
                $this->send($id, ['type' => 'auth', 'userId' => null]);
            }
        }
    }

    private function send(int $id, array $message): void
    {
        $this->server->send($id, (string) json_encode($message));
    }
}
