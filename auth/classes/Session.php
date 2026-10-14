<?php

/**
 * Server-side sessions, held in Redis by the auth service (they used to be
 * the MySQL `sessions` table, which took a write on every request). A session
 * is a random 64-hex hash mapped to a user id, with a *sliding* expiry: every
 * `resolve()` pushes the key's TTL back to `TTL_SECONDS`, so a session lives
 * as long as it's used at least that often, and Redis deletes it by itself
 * once it isn't.
 *
 * Keys hold the SHA-256 of the hash, never the hash itself, so a Redis dump
 * can't be replayed as live sessions:
 *
 *   session:<sha256>        → user id, TTL = TTL_SECONDS (sliding)
 *   user_sessions:<userId>  → set of <sha256>, for signing out everywhere
 */
class Session
{
    /** Seconds of inactivity before a session expires. */
    public const TTL_SECONDS = 300;
    /** The per-user index outlives its sessions; stale members are harmless. */
    private const INDEX_TTL_SECONDS = 86400;

    /** @var RedisClient */
    private $redis;

    public function __construct(RedisClient $redis)
    {
        $this->redis = $redis;
    }

    /** Creates a session for a just-registered/logged-in user and returns its hash. */
    public function create(int $userId): string
    {
        $hash = bin2hex(random_bytes(32));
        $digest = hash('sha256', $hash);

        $this->redis->pipeline([
            ['SET', self::key($digest), (string) $userId, 'EX', (string) self::TTL_SECONDS],
            ['SADD', self::index($userId), $digest],
            ['EXPIRE', self::index($userId), (string) self::INDEX_TTL_SECONDS],
        ]);

        return $hash;
    }

    /**
     * The session's user id, sliding its expiry forward (GETEX reads and
     * re-arms the TTL in one step); `null` for an unknown or expired hash.
     */
    public function resolve(string $hash): ?int
    {
        if (!self::isWellFormed($hash)) {
            return null;
        }

        $userId = (int) $this->redis->command('GETEX', self::key(hash('sha256', $hash)), 'EX', (string) self::TTL_SECONDS);
        if ($userId <= 0) {
            return null;
        }
        $this->redis->command('EXPIRE', self::index($userId), (string) self::INDEX_TTL_SECONDS);

        return $userId;
    }

    /**
     * Which of `$hashes` are still live, *without* sliding them — for the
     * gateway re-checking the connections it pushes to, which mustn't keep
     * an idle session alive by itself.
     *
     * @param string[] $hashes
     * @return array<string, int> hash → user id, live ones only
     */
    public function peekMany(array $hashes): array
    {
        $hashes = array_values(array_filter(array_unique($hashes), [self::class, 'isWellFormed']));
        if (!$hashes) {
            return [];
        }

        $keys = array_map(static function (string $hash): string {
            return self::key(hash('sha256', $hash));
        }, $hashes);
        $values = $this->redis->command('MGET', ...$keys);

        $live = [];
        foreach ($hashes as $i => $hash) {
            if ((int) ($values[$i] ?? 0) > 0) {
                $live[$hash] = (int) $values[$i];
            }
        }

        return $live;
    }

    /** Ends a session — logout. Silently no-ops for an unknown hash. */
    public function destroy(string $hash): void
    {
        if (!self::isWellFormed($hash)) {
            return;
        }
        $digest = hash('sha256', $hash);
        $userId = (int) $this->redis->command('GETDEL', self::key($digest));
        if ($userId > 0) {
            $this->redis->command('SREM', self::index($userId), $digest);
        }
    }

    /** Signs a user out everywhere — after a password reset (see `AccountSecurity::resetPassword()`). */
    public function destroyAllFor(int $userId): void
    {
        $digests = (array) $this->redis->command('SMEMBERS', self::index($userId));
        $keys = array_map([self::class, 'key'], $digests);
        $keys[] = self::index($userId);
        $this->redis->command('DEL', ...$keys);
    }

    private static function isWellFormed(string $hash): bool
    {
        return (bool) preg_match('/^[0-9a-f]{64}$/', $hash);
    }

    private static function key(string $digest): string
    {
        return 'session:' . $digest;
    }

    private static function index(int $userId): string
    {
        return 'user_sessions:' . $userId;
    }
}
