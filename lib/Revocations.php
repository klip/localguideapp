<?php

/**
 * Ending sign-ins *now* (docs/auth-cms-plan.md, phase 2). A refresh token is
 * revoked in MySQL, but the access tokens already handed out stay valid for
 * up to 15 minutes — fine for most things, not for logout, a password reset,
 * a stolen token or (later) a frozen account. So the auth service also
 * records the revocation in Redis and publishes it, and every gateway keeps
 * the list in memory: no lookup per request.
 *
 *   revoked:sid:<sid>   → unix time   one sign-in (logout, reuse detected,
 *                                     and each live one on a password reset
 *                                     or "sign out everywhere")
 *   revoked:user:<id>   → unix time   every token of the account issued at
 *                                     or before that time (for freezing an
 *                                     account, not used yet)
 *
 * Both expire once every access token they could match has expired anyway
 * (`TTL_SECONDS`), and each is published on `CHANNEL` as `{ sid | userId, at }`.
 * Only the gateway checks it: the services are reachable only through it.
 */
final class Revocations
{
    public const CHANNEL = 'auth:revoked';
    /** The access token lifetime plus `Jwt`'s clock leeway. */
    public const TTL_SECONDS = 900 + 60;

    /** Sign-ins by sid — logout, a reused token, or every live one of an account. */
    public static function revokeSids(RedisClient $redis, array $sids): void
    {
        $commands = [];
        foreach (array_unique(array_map('strval', $sids)) as $sid) {
            array_push($commands, ...self::commands('revoked:sid:' . $sid, ['sid' => $sid]));
        }
        if ($commands) {
            $redis->pipeline($commands);
        }
    }

    /**
     * Every token of the account issued up to now, whatever its sign-in —
     * for freezing an account (a sign-in in the same second counts too, which
     * is right there). Signing out everywhere revokes the live sids instead,
     * so signing straight back in works.
     */
    public static function revokeUser(RedisClient $redis, int $userId): void
    {
        $redis->pipeline(self::commands('revoked:user:' . $userId, ['userId' => $userId]));
    }

    /**
     * Whether verified token claims are revoked by `$sids` (sid → at) or
     * `$users` (user id → at).
     */
    public static function isRevoked(array $claims, array $sids, array $users): bool
    {
        $sid = (string) ($claims['sid'] ?? '');
        if ($sid !== '' && isset($sids[$sid])) {
            return true;
        }
        $userId = (int) ($claims['sub'] ?? 0);

        return isset($users[$userId]) && (int) ($claims['iat'] ?? 0) <= $users[$userId];
    }

    /**
     * Every live entry, for a gateway starting up or back from a Redis
     * outage: `[sids, users]`, each id → at.
     *
     * @return array{0: array<string, int>, 1: array<int, int>}
     */
    public static function load(RedisClient $redis): array
    {
        $sids = [];
        $users = [];
        $cursor = '0';
        do {
            [$cursor, $keys] = $redis->command('SCAN', $cursor, 'MATCH', 'revoked:*', 'COUNT', '500');
            $keys = (array) $keys;
            $values = $keys ? (array) $redis->command('MGET', ...$keys) : [];
            foreach ($keys as $i => $key) {
                $at = (int) ($values[$i] ?? 0);
                if ($at <= 0) {
                    continue;
                }
                if (strncmp($key, 'revoked:sid:', 12) === 0) {
                    $sids[substr($key, 12)] = $at;
                } elseif (strncmp($key, 'revoked:user:', 13) === 0) {
                    $users[(int) substr($key, 13)] = $at;
                }
            }
        } while ((string) $cursor !== '0');

        return [$sids, $users];
    }

    private static function commands(string $key, array $subject): array
    {
        $at = time();

        return [
            ['SET', $key, (string) $at, 'EX', (string) self::TTL_SECONDS],
            ['PUBLISH', self::CHANNEL, (string) json_encode($subject + ['at' => $at])],
        ];
    }
}
