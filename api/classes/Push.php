<?php

/**
 * Server → browser pushes, through Redis pub/sub. Each signed-in user has a
 * channel, `user:<id>`, that every gateway holding a connection for them
 * subscribes to; whatever is published there reaches all their open tabs,
 * whichever gateway replica each one is connected to.
 *
 * Two message types, the same the client always received:
 *   { type: "notification", notification: { id, kind, message, created_at } }
 *   { type: "badges", matches, trips }   — the menu counters (`Badges`)
 *
 * Badges are recomputed once per request, not per change: code calls
 * `touch()` for each user a change concerns, and `flush()` (end of request,
 * end of a scheduler pass) publishes their counts. Gateways drop a counts
 * message that matches what a connection already has.
 *
 * Never throws — a push is a courtesy. Unread notifications are fetched again
 * whenever a connection signs in (`pushState`), so nothing is lost while
 * Redis is down; only counters may lag until the next change.
 */
class Push
{
    /** @var RedisClient */
    private $redis;
    /** @var Badges */
    private $badges;
    /** @var array<int, true> */
    private $touched = [];

    public function __construct(RedisClient $redis, Badges $badges)
    {
        $this->redis = $redis;
        $this->badges = $badges;
    }

    /** The gateway subscribes by the same name (`Gateway::CHANNEL_PREFIX`). */
    public static function channel(int $userId): string
    {
        return 'user:' . $userId;
    }

    /** Publishes to one user; returns how many gateways received it (0 = not connected anywhere, or Redis is down). */
    public function toUser(int $userId, array $message): int
    {
        try {
            return (int) $this->redis->command('PUBLISH', self::channel($userId), json_encode($message));
        } catch (Throwable $e) {
            Log::warning('Push failed', ['user_id' => $userId, 'type' => $message['type'] ?? '', 'error' => $e->getMessage()]);
            return 0;
        }
    }

    /** Marks users whose counters may have changed; `flush()` sends them. */
    public function touch(int ...$userIds): void
    {
        foreach ($userIds as $userId) {
            if ($userId > 0) {
                $this->touched[$userId] = true;
            }
        }
    }

    /** Publishes fresh counters to every touched user. */
    public function flush(): void
    {
        if (!$this->touched) {
            return;
        }
        $userIds = array_keys($this->touched);
        $this->touched = [];

        try {
            $counts = $this->badges->countsFor($userIds);
        } catch (Throwable $e) {
            Log::exception('Counting badges failed', $e);
            return;
        }
        foreach ($counts as $userId => $count) {
            $this->toUser($userId, ['type' => 'badges'] + $count);
        }
    }
}
