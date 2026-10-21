<?php

/**
 * Staff sign-ins (docs/auth-cms-plan.md, phase 6): the app's `Tokens`
 * scheme for the CMS, stricter.
 *
 * - Access token: a 15-minute JWT with `aud: cms` (an app token is refused
 *   by the CMS and the other way round), `sub: "staff:<id>"` — never readable
 *   as an app user id — `sid` and `role`.
 * - Refresh token: 32 random bytes in the CMS's own HttpOnly cookie
 *   (`__Host-rg_cms_refresh`), its SHA-256 in `cms_refresh_tokens`;
 *   single-use, rotated on every refresh, a reused one revokes the sign-in.
 *   A staff sign-in lasts **12 hours**, however active — no remember-me.
 * - Revocations go out through `Revocations` (logout, a disabled member, a
 *   role change), so the CMS API refuses those access tokens at once.
 */
final class StaffTokens
{
    public const ACCESS_TTL_SECONDS = 900;
    public const SIGN_IN_TTL_SECONDS = 12 * 3600;
    private const GRACE_SECONDS = 30;
    public const AUDIENCE = 'cms';

    /** @var db */
    private $db;
    /** @var RedisClient */
    private $redis;

    public function __construct(db $db, RedisClient $redis)
    {
        $this->db = $db;
        $this->redis = $redis;
    }

    /** @return array{refreshToken: string, sid: string} */
    public function issue(int $staffId, string $ip, string $userAgent): array
    {
        $sid = bin2hex(random_bytes(16));
        $now = time();

        return ['refreshToken' => $this->insert($staffId, $sid, null, $now, $now + self::SIGN_IN_TTL_SECONDS, $ip, $userAgent), 'sid' => $sid];
    }

    /** @return array{refreshToken: string, sid: string, staffId: int} */
    public function rotate(string $refreshToken, string $ip, string $userAgent): array
    {
        if (!preg_match('/^[A-Za-z0-9_-]{43}$/', $refreshToken)) {
            throw new AuthenticationException('Your CMS session has ended. Please sign in again.');
        }
        $now = time();
        $this->db->db_Execute('START TRANSACTION');
        try {
            $row = $this->db->db_GetRow(
                'SELECT `id`, `staff_id`, `sid`, UNIX_TIMESTAMP(`expires_at`) AS `expires`,
                        UNIX_TIMESTAMP(`used_at`) AS `used`, `revoked_at`
                 FROM `cms_refresh_tokens` WHERE `token_hash` = "' . hash('sha256', $refreshToken) . '" FOR UPDATE'
            );
            if (!$row || $row['revoked_at'] !== null || (int) $row['expires'] < $now) {
                $this->db->db_Execute('COMMIT');
                throw new AuthenticationException('Your CMS session has ended. Please sign in again.');
            }
            if ($row['used'] !== null && $now - (int) $row['used'] > self::GRACE_SECONDS) {
                $this->revokeSids([(string) $row['sid']], 'reuse_detected');
                $this->db->db_Execute('COMMIT');
                Log::warning('Staff refresh token reused; sign-in revoked', ['staff_id' => (int) $row['staff_id']]);
                throw new AuthenticationException('Your CMS session has ended. Please sign in again.');
            }
            if ($row['used'] === null) {
                $this->db->db_Execute('UPDATE `cms_refresh_tokens` SET `used_at` = UTC_TIMESTAMP() WHERE `id` = ' . (int) $row['id']);
            }
            // The sign-in's 12 hours carry over: rotating never extends them.
            $refresh = $this->insert((int) $row['staff_id'], (string) $row['sid'], (int) $row['id'], $now, (int) $row['expires'], $ip, $userAgent);
            $this->db->db_Execute('COMMIT');
        } catch (Throwable $e) {
            $this->db->db_Execute('ROLLBACK');
            throw $e;
        }

        return ['refreshToken' => $refresh, 'sid' => (string) $row['sid'], 'staffId' => (int) $row['staff_id']];
    }

    /** @return array{accessToken: string, accessExpiresAt: int} */
    public function access(int $staffId, string $sid, string $role): array
    {
        $now = time();

        return [
            'accessToken' => Jwt::sign([
                'aud' => self::AUDIENCE,
                'sub' => 'staff:' . $staffId,
                'sid' => $sid,
                'role' => $role,
                'iat' => $now,
                'exp' => $now + self::ACCESS_TTL_SECONDS,
                'jti' => bin2hex(random_bytes(8)),
            ]),
            'accessExpiresAt' => $now + self::ACCESS_TTL_SECONDS,
        ];
    }

    public function revokeByToken(string $refreshToken, string $reason): void
    {
        if (!preg_match('/^[A-Za-z0-9_-]{43}$/', $refreshToken)) {
            return;
        }
        $sid = $this->db->db_GetRow(
            'SELECT `sid` FROM `cms_refresh_tokens` WHERE `token_hash` = "' . hash('sha256', $refreshToken) . '"'
        )['sid'] ?? null;
        if ($sid) {
            $this->revokeSids([(string) $sid], $reason);
        }
    }

    /** Every sign-in of a member — disabled, role changed, password reset. */
    public function revokeAllFor(int $staffId, string $reason): void
    {
        $sids = array_column((array) $this->db->db_GetArray(
            'SELECT DISTINCT `sid` FROM `cms_refresh_tokens` WHERE `staff_id` = ' . $staffId . ' AND `revoked_at` IS NULL'
        ), 'sid');
        $this->revokeSids($sids, $reason);
    }

    /** Verified staff claims for an access token — signature, audience, not revoked; else null. */
    public static function verify(string $token, RedisClient $redis): ?array
    {
        $claims = Jwt::verify($token, self::AUDIENCE);
        if ($claims === null || !preg_match('/^staff:(\d+)$/', (string) ($claims['sub'] ?? ''), $m)) {
            return null;
        }
        // The CMS keeps nothing between requests, so it asks Redis — staff
        // traffic is light, and this is what makes a revocation immediate.
        $sid = (string) ($claims['sid'] ?? '');
        if ($sid === '' || $redis->command('EXISTS', 'revoked:sid:' . $sid)) {
            return null;
        }
        $claims['staffId'] = (int) $m[1];

        return $claims;
    }

    private function revokeSids(array $sids, string $reason): void
    {
        foreach ($sids as $sid) {
            $this->db->db_Execute(
                'UPDATE `cms_refresh_tokens` SET `revoked_at` = UTC_TIMESTAMP(), `revoke_reason` = "' . $this->db->db_Escape($reason) . '"
                 WHERE `sid` = "' . $this->db->db_Escape((string) $sid) . '" AND `revoked_at` IS NULL'
            );
        }
        try {
            Revocations::revokeSids($this->redis, $sids);
        } catch (Throwable $e) {
            Log::warning('Could not announce a staff revocation', ['error' => $e->getMessage()]);
        }
    }

    private function insert(int $staffId, string $sid, ?int $parentId, int $now, int $expires, string $ip, string $userAgent): string
    {
        $token = Jwt::base64url(random_bytes(32));
        $id = $this->db->db_Insert('cms_refresh_tokens', array_filter([
            'staff_id' => $staffId,
            'sid' => $sid,
            'token_hash' => hash('sha256', $token),
            'parent_id' => $parentId,
            'issued_at' => gmdate('Y-m-d H:i:s', $now),
            'expires_at' => gmdate('Y-m-d H:i:s', $expires),
            'ip' => $ip ?: null,
            'user_agent' => mb_substr($userAgent, 0, 255),
        ], static fn($value): bool => $value !== null));
        if (!$id) {
            throw new RuntimeException('Could not sign in. Please try again.');
        }

        return $token;
    }
}
