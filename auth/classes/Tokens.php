<?php

/**
 * Access and refresh tokens (docs/auth-cms-plan.md, phase 1).
 *
 * - **Access token**: a 15-minute JWT (`Jwt::sign()`), checked by the gateway
 *   and every service with the public key — no lookup per request. Claims:
 *   `aud` (app), `sub` (user id), `sid` (the sign-in family), `roles`, `iat`,
 *   `exp`, `jti`.
 * - **Refresh token**: 32 random bytes the browser keeps in an HttpOnly cookie
 *   (`AuthHttp`); `refresh_tokens` holds only its SHA-256. Opaque rather than
 *   signed: every use is looked up anyway, which is what makes it revocable.
 *
 * Every refresh token is single-use. `rotate()` marks it used and issues a
 * child in the same family (`sid`). Presenting a used one again means it was
 * copied — unless it's within `GRACE_SECONDS` of its first use (two tabs, a
 * retried request), which gets a sibling instead — so the whole family is
 * revoked and the caller signed out.
 */
class Tokens
{
    public const ACCESS_TTL_SECONDS = 900;
    public const REFRESH_TTL_SECONDS = 30 * 86400;
    public const ABSOLUTE_TTL_SECONDS = 90 * 86400;
    private const GRACE_SECONDS = 30;

    /** @var db */
    private $db;
    /** @var Accounts */
    private $accounts;
    /** @var RedisClient */
    private $redis;

    public function __construct(db $db, Accounts $accounts, RedisClient $redis)
    {
        $this->db = $db;
        $this->accounts = $accounts;
        $this->redis = $redis;
    }

    /**
     * A new sign-in: a fresh family. Returns `refreshToken` (for the cookie
     * only) plus what the client gets in the body (`clientFields()`).
     *
     * @return array{refreshToken: string, accessToken: string, accessExpiresAt: int}
     */
    public function issue(int $userId, ?string $deviceId, string $userAgent): array
    {
        $now = time();
        $sid = bin2hex(random_bytes(16));
        $refresh = $this->insert($userId, $sid, $deviceId, null, $now, $now + self::ABSOLUTE_TTL_SECONDS, $userAgent);
        Log::info('Signed in', ['user_id' => $userId, 'sid' => substr($sid, 0, 8)]);

        return ['refreshToken' => $refresh] + $this->access($userId, $sid, $now);
    }

    /**
     * Swaps a refresh token for a new pair. 401 (`AuthenticationException`)
     * for anything unknown, expired, revoked or reused.
     *
     * @return array{refreshToken: string, accessToken: string, accessExpiresAt: int, userId: int}
     */
    public function rotate(string $refreshToken, ?string $deviceId, string $userAgent): array
    {
        if (!preg_match('/^[A-Za-z0-9_-]{43}$/', $refreshToken)) {
            throw new AuthenticationException('Your session has expired. Please log in again.');
        }
        $hash = hash('sha256', $refreshToken);
        $now = time();

        $this->db->db_Execute('START TRANSACTION');
        try {
            $row = $this->db->db_GetRow(
                'SELECT `id`, `user_id`, `sid`, `device_id`, UNIX_TIMESTAMP(`expires_at`) AS `expires`,
                        UNIX_TIMESTAMP(`absolute_expires_at`) AS `absolute`, UNIX_TIMESTAMP(`used_at`) AS `used`,
                        `revoked_at`
                 FROM `refresh_tokens` WHERE `token_hash` = "' . $hash . '" FOR UPDATE'
            );
            if (!$row || $row['revoked_at'] !== null || (int) $row['expires'] < $now || (int) $row['absolute'] < $now) {
                $this->db->db_Execute('COMMIT');
                throw new AuthenticationException('Your session has expired. Please log in again.');
            }
            $userId = (int) $row['user_id'];

            if ($row['used'] !== null && $now - (int) $row['used'] > self::GRACE_SECONDS) {
                $this->revokeFamily((string) $row['sid'], 'reuse_detected');
                $this->db->db_Execute('COMMIT');
                Log::warning('Refresh token reused; family revoked', ['user_id' => $userId, 'sid' => substr((string) $row['sid'], 0, 8)]);
                throw new AuthenticationException('Your session has expired. Please log in again.');
            }
            if ($row['used'] === null) {
                $this->db->db_Execute('UPDATE `refresh_tokens` SET `used_at` = "' . gmdate('Y-m-d H:i:s', $now) . '" WHERE `id` = ' . (int) $row['id']);
            }
            if ($deviceId !== null && $row['device_id'] !== null && !hash_equals((string) $row['device_id'], $deviceId)) {
                // Both cookies travel together, so this is odd — noted for
                // the risk scoring of phase 4, not refused.
                Log::warning('Refresh from a different device cookie', ['user_id' => $userId]);
            }

            $refresh = $this->insert($userId, (string) $row['sid'], $deviceId ?? $row['device_id'], (int) $row['id'], $now, (int) $row['absolute'], $userAgent);
            $this->db->db_Execute('COMMIT');
        } catch (Throwable $e) {
            $this->db->db_Execute('ROLLBACK');
            throw $e;
        }

        return ['refreshToken' => $refresh, 'userId' => $userId] + $this->access($userId, (string) $row['sid'], $now);
    }

    /**
     * A new access token in an existing family — after the account's roles
     * change (`addRole`), so the `roles` claim is current at once.
     *
     * @return array{accessToken: string, accessExpiresAt: int}
     */
    public function reissueAccess(int $userId, string $sid): array
    {
        return $this->access($userId, $sid, time());
    }

    /** Logout: ends the sign-in the refresh token belongs to (no-op for an unknown one). */
    public function revokeByToken(string $refreshToken, string $reason): void
    {
        if (!preg_match('/^[A-Za-z0-9_-]{43}$/', $refreshToken)) {
            return;
        }
        $sid = $this->db->db_GetRow(
            'SELECT `sid` FROM `refresh_tokens` WHERE `token_hash` = "' . hash('sha256', $refreshToken) . '"'
        )['sid'] ?? null;
        if ($sid) {
            $this->revokeFamily((string) $sid, $reason);
        }
    }

    /**
     * The account a live refresh token belongs to (`null` for anything
     * unknown, expired or revoked) — "sign out everywhere" asks with the cookie.
     */
    public function userForToken(string $refreshToken): ?int
    {
        if (!preg_match('/^[A-Za-z0-9_-]{43}$/', $refreshToken)) {
            return null;
        }
        $row = $this->db->db_GetRow(
            'SELECT `user_id` FROM `refresh_tokens`
             WHERE `token_hash` = "' . hash('sha256', $refreshToken) . '" AND `revoked_at` IS NULL
               AND `expires_at` > UTC_TIMESTAMP() AND `absolute_expires_at` > UTC_TIMESTAMP()'
        );

        return $row ? (int) $row['user_id'] : null;
    }

    /**
     * Every sign-in of the account — password reset, "sign out everywhere" —
     * including the access tokens already out (`Revocations`).
     */
    public function revokeAllFor(int $userId, string $reason): void
    {
        // Each live sign-in by its sid, so a sign-in right after this one
        // (a new sid) isn't caught by it.
        $sids = array_column((array) $this->db->db_GetArray(
            'SELECT DISTINCT `sid` FROM `refresh_tokens` WHERE `user_id` = ' . $userId . ' AND `revoked_at` IS NULL'
        ), 'sid');
        $this->db->db_Execute(
            'UPDATE `refresh_tokens` SET `revoked_at` = UTC_TIMESTAMP(), `revoke_reason` = "' . $this->db->db_Escape($reason) . '"
             WHERE `user_id` = ' . $userId . ' AND `revoked_at` IS NULL'
        );
        $this->announce(static fn(RedisClient $redis) => Revocations::revokeSids($redis, $sids));
    }

    /** One sign-in, refresh and access tokens alike. */
    private function revokeFamily(string $sid, string $reason): void
    {
        $this->db->db_Execute(
            'UPDATE `refresh_tokens` SET `revoked_at` = UTC_TIMESTAMP(), `revoke_reason` = "' . $this->db->db_Escape($reason) . '"
             WHERE `sid` = "' . $this->db->db_Escape($sid) . '" AND `revoked_at` IS NULL'
        );
        $this->announce(static fn(RedisClient $redis) => Revocations::revokeSids($redis, [$sid]));
    }

    /**
     * Tells the gateways (`Revocations`). Best-effort: the refresh tokens are
     * already revoked in MySQL, so without Redis the access tokens just run
     * out within 15 minutes.
     */
    private function announce(callable $publish): void
    {
        try {
            $publish($this->redis);
        } catch (Throwable $e) {
            Log::warning('Could not announce a revocation', ['error' => $e->getMessage()]);
        }
    }

    /** Inserts one refresh token row and returns the token itself. */
    private function insert(int $userId, string $sid, ?string $deviceId, ?int $parentId, int $now, int $absolute, string $userAgent): string
    {
        $token = Jwt::base64url(random_bytes(32));
        // `db_Insert()` quotes every value, so NULLs are left out (the columns default to NULL).
        $id = $this->db->db_Insert('refresh_tokens', array_filter([
            'user_id' => $userId,
            'sid' => $sid,
            'device_id' => $deviceId,
            'token_hash' => hash('sha256', $token),
            'parent_id' => $parentId,
            'issued_at' => gmdate('Y-m-d H:i:s', $now),
            'expires_at' => gmdate('Y-m-d H:i:s', min($now + self::REFRESH_TTL_SECONDS, $absolute)),
            'absolute_expires_at' => gmdate('Y-m-d H:i:s', $absolute),
            'user_agent' => mb_substr($userAgent, 0, 255),
        ], static fn($value): bool => $value !== null));
        if (!$id) {
            throw new RuntimeException('Could not sign in. Please try again.');
        }

        return $token;
    }

    /** @return array{accessToken: string, accessExpiresAt: int} */
    private function access(int $userId, string $sid, int $now): array
    {
        $expires = $now + self::ACCESS_TTL_SECONDS;

        return [
            'accessToken' => Jwt::sign([
                'aud' => 'app',
                'sub' => (string) $userId,
                'sid' => $sid,
                'roles' => $this->accounts->getRoles($userId),
                'iat' => $now,
                'exp' => $expires,
                'jti' => bin2hex(random_bytes(8)),
            ]),
            'accessExpiresAt' => $expires,
        ];
    }
}
