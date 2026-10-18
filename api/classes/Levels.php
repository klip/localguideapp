<?php

/**
 * Levels, the perks they bring, bonus packages and sanctions (041).
 *
 * **Level** — from successful trips (confirmed and finished) that *qualify*:
 * paid through RockGuide, or with someone whose identity is verified — so a
 * handful of sock-puppet accounts finishing free trips with each other can't
 * buy perks. Each other person counts once (the same count the ranking and
 * the trips badge use). The thresholds grow exponentially:
 *
 *   0 New · 1 Starter (1 trip) · 2 Explorer (3) · 3 Pathfinder (6) ·
 *   4 Trailblazer (12) · 5 Pioneer (24) · 6 Legend (48) · 7 Icon (96)
 *
 * **Sanctions** (`user_sanctions`, set by moderators — e.g. after a
 * complaint) take levels away while active: the *effective* level is the
 * earned one minus every active sanction, never below 0. Everything below
 * uses the effective level, and so does the ranking (`Ranking`). While
 * sanctioned, bonuses are suspended: none can be claimed and a running one
 * doesn't apply.
 *
 * **Perks**
 *   - guests: more picks per £5 pack — 5 + level, at most 10 (`picksPerPack()`);
 *   - guides: a smaller platform fee on their bookings — 15% falling to 5%
 *     (`commissionRate()`), recorded on each booking by `Bookings::pay()`.
 *
 * **Bonus packages** (`bonus_grants`), granted once each and claimed when the
 * person wants them (a visitor claims free days when they're actually
 * travelling):
 *   - guests reaching level 2 / 4 / 6: 3 / 7 / 14 days of free picks —
 *     unlimited picks that don't use the pack (`Selections` marks them);
 *   - guides reaching level 4 / 6: 7 / 30 days without a platform fee;
 *   - guides with exceptional reviews — 10, 25, 50 distinct reviewers
 *     averaging 4.8★ or more: 30 days without a platform fee each time.
 *
 * `sync()` runs when a trip finishes and when a review is written: it grants
 * what was earned and tells the person about a level-up or a new bonus.
 */
class Levels
{
    /** Trips needed for each level (index = level). */
    public const THRESHOLDS = [0, 1, 3, 6, 12, 24, 48, 96];
    public const NAMES = ['new', 'starter', 'explorer', 'pathfinder', 'trailblazer', 'pioneer', 'legend', 'icon'];

    private const BASE_PACK = 5;
    private const MAX_PACK = 10;
    /** The guide's platform fee by level (index = level). */
    private const COMMISSION = [0.15, 0.15, 0.14, 0.12, 0.10, 0.08, 0.06, 0.05];

    /** level => days, per role. */
    private const GUEST_LEVEL_BONUSES = [2 => 3, 4 => 7, 6 => 14];
    private const GUIDE_LEVEL_BONUSES = [4 => 7, 6 => 30];
    /** Distinct reviewers at which an exceptionally reviewed guide earns 30 fee-free days. */
    private const REVIEW_MILESTONES = [10, 25, 50];
    private const EXCEPTIONAL_AVERAGE = 4.8;
    private const REVIEW_BONUS_DAYS = 30;

    /** @var db */
    private $db;
    /** @var Notifications|null */
    private $notifications;

    public function __construct(db $db, ?Notifications $notifications = null)
    {
        $this->db = $db;
        $this->notifications = $notifications;
    }

    public static function levelForTrips(int $trips): int
    {
        $level = 0;
        foreach (self::THRESHOLDS as $candidate => $needed) {
            if ($trips >= $needed) {
                $level = $candidate;
            }
        }

        return $level;
    }

    public static function picksPerPack(int $level): int
    {
        return min(self::MAX_PACK, self::BASE_PACK + max(0, $level));
    }

    public static function commissionRate(int $level): float
    {
        return self::COMMISSION[max(0, min(count(self::COMMISSION) - 1, $level))];
    }

    /**
     * SQL for the effective level of `u`, given `ts.trips_completed`
     * (`Ranking::tripsJoin()`) and `sx.levels` (`sanctionsJoin()`) — so the
     * deck can rank by it in one query.
     */
    public static function effectiveLevelSql(): string
    {
        $cases = [];
        foreach (array_reverse(self::THRESHOLDS, true) as $level => $needed) {
            if ($level > 0) {
                $cases[] = 'WHEN COALESCE(ts.`trips_completed`, 0) >= ' . $needed . ' THEN ' . $level;
            }
        }

        return 'GREATEST(0, (CASE ' . implode(' ', $cases) . ' ELSE 0 END) - COALESCE(sx.`levels`, 0))';
    }

    /** Active sanction levels per user, joined as `sx`. */
    public static function sanctionsJoin(?int $userId = null): string
    {
        return 'LEFT JOIN (
                SELECT `user_id`, SUM(`levels`) AS `levels` FROM `user_sanctions`
                WHERE `lifted_at` IS NULL AND (`expires_at` IS NULL OR `expires_at` > UTC_TIMESTAMP())'
                . ($userId ? ' AND `user_id` = ' . $userId : '') . '
                GROUP BY `user_id`
             ) sx ON sx.`user_id` = u.`id`';
    }

    /**
     * SQL condition: booking `b` with the other person `o` is a qualifying
     * trip — finished, and paid through RockGuide or with a verified person.
     */
    public const QUALIFYING_TRIP = 'b.`status` = "confirmed" AND b.`trip_status` = "finished"
        AND (b.`payment_status` = "paid" OR o.`verified_at` IS NOT NULL)';

    public function tripsCompleted(int $userId): int
    {
        return (int) $this->db->db_GetFieldFromQuery(
            'SELECT COUNT(DISTINCT `other`) AS `n` FROM (
                SELECT b.`guide_id` AS `other` FROM `bookings` b JOIN `users` o ON o.`id` = b.`guide_id`
                WHERE b.`guest_id` = ' . $userId . ' AND ' . self::QUALIFYING_TRIP . '
                UNION ALL
                SELECT b.`guest_id` AS `other` FROM `bookings` b JOIN `users` o ON o.`id` = b.`guest_id`
                WHERE b.`guide_id` = ' . $userId . ' AND ' . self::QUALIFYING_TRIP . '
             ) t',
            'n'
        );
    }

    /** @return array{levels: int, until: ?string, reason: ?string} the active sanctions, summed (soonest expiry shown) */
    public function activeSanctions(int $userId): array
    {
        $row = $this->db->db_GetRow(
            'SELECT COALESCE(SUM(`levels`), 0) AS `levels`,
                    IF(SUM(`expires_at` IS NULL) > 0, NULL, UNIX_TIMESTAMP(MAX(`expires_at`))) AS `until_ts`,
                    SUBSTRING_INDEX(GROUP_CONCAT(`reason` ORDER BY `id` DESC SEPARATOR "\n"), "\n", 1) AS `reason`
             FROM `user_sanctions`
             WHERE `user_id` = ' . $userId . ' AND `lifted_at` IS NULL
               AND (`expires_at` IS NULL OR `expires_at` > UTC_TIMESTAMP())'
        );
        $levels = (int) ($row['levels'] ?? 0);

        return [
            'levels' => $levels,
            'until' => $levels > 0 && $row['until_ts'] !== null ? gmdate('Y-m-d\TH:i:s\Z', (int) $row['until_ts']) : null,
            'reason' => $levels > 0 ? ($row['reason'] ?: null) : null,
        ];
    }

    public function effectiveLevel(int $userId): int
    {
        return max(0, self::levelForTrips($this->tripsCompleted($userId)) - $this->activeSanctions($userId)['levels']);
    }

    /** When `$userId`'s active bonus of `$kind` ends (ISO), or null without one — or while sanctioned. */
    public function activeBonusUntil(int $userId, string $kind): ?string
    {
        if ($this->activeSanctions($userId)['levels'] > 0) {
            return null;
        }
        $ts = $this->db->db_GetFieldFromQuery(
            'SELECT UNIX_TIMESTAMP(MAX(`ends_at`)) AS `ts` FROM `bonus_grants`
             WHERE `user_id` = ' . $userId . ' AND `kind` = "' . $this->db->db_Escape($kind) . '"
               AND `starts_at` <= UTC_TIMESTAMP() AND `ends_at` > UTC_TIMESTAMP()',
            'ts'
        );

        return $ts ? gmdate('Y-m-d\TH:i:s\Z', (int) $ts) : null;
    }

    /** The guide's platform fee right now: 0 during a no-fee bonus, else by level. */
    public function guideCommissionRate(int $guideId): float
    {
        return $this->activeBonusUntil($guideId, 'no_fee') ? 0.0 : self::commissionRate($this->effectiveLevel($guideId));
    }

    /**
     * Grants whatever `$userId` has earned and isn't granted yet, and tells
     * them about a level-up (`users.level` is the level last announced) or a
     * new bonus.
     */
    public function sync(int $userId): void
    {
        $user = $this->db->db_GetRow(
            'SELECT u.`id`, u.`level`, ' . Roles::nameSql('u.`id`') . ' AS `role` FROM `users` u
             WHERE u.`id` = ' . $userId
        );
        if (!$user || !in_array($user['role'], Roles::APP_ROLES, true)) {
            return;
        }

        // `users.level` is the highest level announced, so a level comes with
        // one announcement — not another each time a sanction expires.
        $earned = self::levelForTrips($this->tripsCompleted($userId));
        $level = max(0, $earned - $this->activeSanctions($userId)['levels']);
        $previous = (int) $user['level'];
        if ($level > $previous) {
            $this->db->db_Execute('UPDATE `users` SET `level` = ' . $level . ' WHERE `id` = ' . $userId . ' AND `level` < ' . $level);
        }
        if ($level > $previous && $this->db->db_GetAffectedRows() > 0) {
            $this->notify($userId, 'level_up', 'You reached level ' . $level . ' · ' . ucfirst(self::NAMES[$level]) . '! '
                . ($user['role'] === 'guest'
                    ? 'Your next packs have ' . self::picksPerPack($level) . ' picks.'
                    : 'Your platform fee is now ' . round(self::commissionRate($level) * 100) . '%.'));
        }

        $bonuses = $user['role'] === 'guest' ? self::GUEST_LEVEL_BONUSES : self::GUIDE_LEVEL_BONUSES;
        $kind = $user['role'] === 'guest' ? 'free_picks' : 'no_fee';
        foreach ($bonuses as $atLevel => $days) {
            if ($level >= $atLevel) {
                $this->grant($userId, $kind, $days, 'level_' . $atLevel);
            }
        }

        if ($user['role'] === 'guide') {
            $reviews = $this->db->db_GetRow(
                'SELECT COUNT(*) AS `authors`, AVG(`author_average`) AS `average` FROM (
                    SELECT AVG(`rating`) AS `author_average` FROM `reviews`
                    WHERE `subject_id` = ' . $userId . ' GROUP BY `author_id`
                 ) a'
            );
            $authors = (int) ($reviews['authors'] ?? 0);
            $average = (float) ($reviews['average'] ?? 0);
            foreach (self::REVIEW_MILESTONES as $milestone) {
                if ($authors >= $milestone && $average >= self::EXCEPTIONAL_AVERAGE) {
                    $this->grant($userId, 'no_fee', self::REVIEW_BONUS_DAYS, 'reviews_' . $milestone);
                }
            }
        }
    }

    /**
     * Everything the profile page shows about it: level, progress, perks,
     * bonuses (available / queued / active / used) and any sanction.
     */
    public function stateFor(int $userId, string $role): array
    {
        $trips = $this->tripsCompleted($userId);
        $earned = self::levelForTrips($trips);
        $sanctions = $this->activeSanctions($userId);
        $level = max(0, $earned - $sanctions['levels']);
        $next = $earned + 1 < count(self::THRESHOLDS) ? self::THRESHOLDS[$earned + 1] : null;

        $bonuses = array_map(static fn(array $row): array => [
            'id' => (int) $row['id'],
            'kind' => $row['kind'],
            'days' => (int) $row['days'],
            'reason' => $row['reason'],
            // `queued`: claimed while another of its kind runs — it starts when that ends.
            'state' => $row['starts_ts'] === null ? 'available'
                : ((int) $row['starts_ts'] > time() ? 'queued' : ((int) $row['ends_ts'] > time() ? 'active' : 'used')),
            'starts_at' => $row['starts_ts'] === null ? null : gmdate('Y-m-d\TH:i:s\Z', (int) $row['starts_ts']),
            'ends_at' => $row['ends_ts'] === null ? null : gmdate('Y-m-d\TH:i:s\Z', (int) $row['ends_ts']),
        ], $this->db->db_GetArray(
            'SELECT `id`, `kind`, `days`, `reason`, UNIX_TIMESTAMP(`starts_at`) AS `starts_ts`, UNIX_TIMESTAMP(`ends_at`) AS `ends_ts`
             FROM `bonus_grants` WHERE `user_id` = ' . $userId . ' ORDER BY `id`'
        ) ?: []);

        return [
            'level' => $level,
            'name' => self::NAMES[$level],
            'trips_completed' => $trips,
            'next_level_trips' => $next,
            // The level those trips lead to (by earned level — a sanction lowers the badge, not the count).
            'next_level' => $next === null ? null : $earned + 1,
            'sanction' => $sanctions['levels'] > 0 ? $sanctions : null,
            'picks_per_pack' => $role === 'guest' ? self::picksPerPack($level) : null,
            'commission_rate' => $role === 'guide' ? self::commissionRate($level) : null,
            'free_picks_until' => $role === 'guest' ? $this->activeBonusUntil($userId, 'free_picks') : null,
            'no_fee_until' => $role === 'guide' ? $this->activeBonusUntil($userId, 'no_fee') : null,
            'bonuses' => $bonuses,
        ];
    }

    /**
     * Starts one of `$userId`'s available bonuses now — or right after an
     * active one of the same kind ends, so claiming two doesn't waste days.
     */
    public function claim(int $userId, int $bonusId): void
    {
        $grant = $this->db->db_GetRow(
            'SELECT `id`, `kind`, `days`, `starts_at` FROM `bonus_grants`
             WHERE `id` = ' . $bonusId . ' AND `user_id` = ' . $userId
        );
        if (!$grant) {
            throw new RuntimeException('That bonus wasn’t found.');
        }
        if ($grant['starts_at'] !== null) {
            throw new RuntimeException('That bonus has already been used.');
        }
        if ($this->activeSanctions($userId)['levels'] > 0) {
            throw new RuntimeException('Bonuses can’t be claimed while your level is lowered.');
        }

        $start = 'GREATEST(UTC_TIMESTAMP(), COALESCE((SELECT MAX(`ends_at`) FROM (
                SELECT `ends_at` FROM `bonus_grants`
                WHERE `user_id` = ' . $userId . ' AND `kind` = "' . $this->db->db_Escape($grant['kind']) . '" AND `ends_at` > UTC_TIMESTAMP()
             ) x), UTC_TIMESTAMP()))';
        // In a transaction, with the user's grants locked, so two claims at
        // once queue one after the other instead of overlapping.
        $this->db->db_Execute('START TRANSACTION');
        try {
            $this->db->db_GetArray('SELECT `id` FROM `bonus_grants` WHERE `user_id` = ' . $userId . ' FOR UPDATE');
            $this->db->db_Execute(
                'UPDATE `bonus_grants`
                 SET `starts_at` = ' . $start . ', `ends_at` = ' . $start . ' + INTERVAL ' . (int) $grant['days'] . ' DAY
                 WHERE `id` = ' . (int) $grant['id'] . ' AND `starts_at` IS NULL'
            );
            $this->db->db_Execute('COMMIT');
        } catch (Throwable $e) {
            $this->db->db_Execute('ROLLBACK');
            throw $e;
        }
    }

    private function grant(int $userId, string $kind, int $days, string $reason): void
    {
        $this->db->db_Execute(
            'INSERT IGNORE INTO `bonus_grants` (`user_id`, `kind`, `days`, `reason`)
             VALUES (' . $userId . ', "' . $this->db->db_Escape($kind) . '", ' . $days . ', "' . $this->db->db_Escape($reason) . '")'
        );
        if ($this->db->db_GetAffectedRows() > 0) {
            // The reason in the text, so two grants of the same size aren't
            // folded into one by `notifyInApp()`'s duplicate check.
            $why = strpos($reason, 'reviews_') === 0
                ? 'for ' . substr($reason, 8) . ' excellent reviewers'
                : 'for reaching level ' . substr($reason, 6);
            $this->notify($userId, 'bonus_granted', $kind === 'free_picks'
                ? "You've earned {$days} days of free picks ({$why})! Claim them on your profile whenever you're ready."
                : "You've earned {$days} days without a platform fee ({$why})! Claim them on your profile whenever you like.");
        }
    }

    private function notify(int $userId, string $kind, string $message): void
    {
        if ($this->notifications) {
            $this->notifications->notifyInApp($userId, $kind, $message);
        }
    }
}
