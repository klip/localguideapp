<?php

/**
 * Levels, the perks they bring, bonus packages and sanctions (041).
 *
 * **Level** — per role (a guide level and a traveller level for someone who
 * is both), from **paid** trips only (confirmed, finished and paid through
 * BeLikeLocal — `PAID_TRIP`): free trips never progress anyone, and every paid
 * trip counts, repeat trips with the same person included. A traveller earns
 * 1 point per paid trip; a guide `1 + 0.5 × min(1, fee / £100)` — slightly
 * more for a better-paid trip, capped (`pointsSql()`). A guide with finished
 * trips but no paid one is a **Volunteer** (shown instead of a level, with
 * their trips count). The thresholds, in points, grow exponentially:
 *
 *   0 New · 1 Starter (1) · 2 Explorer (3) · 3 Pathfinder (6) ·
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
    /** Points needed for each level (index = level). */
    public const THRESHOLDS = [0, 1, 3, 6, 12, 24, 48, 96];
    public const NAMES = ['new', 'starter', 'explorer', 'pathfinder', 'trailblazer', 'pioneer', 'legend', 'icon'];
    public const TOP_LEVEL = 7;

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

    public static function picksPerPack(int $level): int
    {
        return min(self::MAX_PACK, self::BASE_PACK + max(0, $level));
    }

    public static function commissionRate(int $level): float
    {
        return self::COMMISSION[max(0, min(count(self::COMMISSION) - 1, $level))];
    }

    /**
     * SQL for the effective level of `u` in the role listed, given
     * `ts.level_points` (`Ranking::tripsJoin()`) and `sx.levels`
     * (`sanctionsJoin()` — sanctions minus staff adjustments) — so the deck
     * can rank by it in one query. Never below 0 or above the top level.
     */
    public static function effectiveLevelSql(string $ts = 'ts', string $sx = 'sx'): string
    {
        $cases = [];
        foreach (array_reverse(self::THRESHOLDS, true) as $level => $needed) {
            if ($level > 0) {
                $cases[] = 'WHEN COALESCE(' . $ts . '.`level_points`, 0) >= ' . $needed . ' THEN ' . $level;
            }
        }

        return 'LEAST(' . self::TOP_LEVEL . ', GREATEST(0, (CASE ' . implode(' ', $cases) . ' ELSE 0 END) - COALESCE(' . $sx . '.`levels`, 0)))';
    }

    /**
     * Per user, the levels taken off in `$role`, joined as `sx.levels`: active
     * sanctions (person-wide) minus active staff adjustments for that role
     * (`level_adjustments`, which can be negative too — so `sx.levels` can be
     * negative, i.e. a net award).
     */
    public static function sanctionsJoin(?int $userId = null, ?string $role = null, string $alias = 'sx'): string
    {
        $user = $userId ? ' AND `user_id` = ' . $userId : '';
        $roleCondition = $role === 'guide' || $role === 'guest'
            ? ' AND (`role` IS NULL OR `role` = "' . $role . '")'
            : ' AND `role` IS NULL';

        return 'LEFT JOIN (
                SELECT `user_id`, SUM(`levels`) AS `levels` FROM (
                    SELECT `user_id`, `levels` FROM `user_sanctions`
                    WHERE `lifted_at` IS NULL AND (`expires_at` IS NULL OR `expires_at` > UTC_TIMESTAMP())' . $user . '
                    UNION ALL
                    SELECT `user_id`, -`delta` FROM `level_adjustments`
                    WHERE `lifted_at` IS NULL AND (`expires_at` IS NULL OR `expires_at` > UTC_TIMESTAMP())' . $user . $roleCondition . '
                ) changes GROUP BY `user_id`
             ) ' . $alias . ' ON ' . $alias . '.`user_id` = u.`id`';
    }

    /** Net staff adjustment to `$userId`'s level in `$role` (active `level_adjustments`). */
    public function activeAdjustment(int $userId, string $role): int
    {
        return (int) $this->db->db_GetFieldFromQuery(
            'SELECT COALESCE(SUM(`delta`), 0) AS `d` FROM `level_adjustments`
             WHERE `user_id` = ' . $userId . ' AND `lifted_at` IS NULL
               AND (`expires_at` IS NULL OR `expires_at` > UTC_TIMESTAMP())
               AND (`role` IS NULL OR `role` = "' . ($role === 'guide' ? 'guide' : 'guest') . '")',
            'd'
        );
    }

    /** SQL condition: booking `b` is a finished trip (counted on the trips badge, free or not). */
    public const FINISHED_TRIP = 'b.`status` = "confirmed" AND b.`trip_status` = "finished"';

    /** SQL condition: booking `b` is a finished trip paid through BeLikeLocal — the only kind that earns a level. */
    public const PAID_TRIP = '(b.`status` = "confirmed" AND b.`trip_status` = "finished" AND b.`payment_status` = "paid")';

    /** A guide's points per paid trip: 1, plus up to `GUIDE_FEE_BONUS` as the fee nears `GUIDE_FEE_REFERENCE`. */
    private const GUIDE_FEE_BONUS = 0.5;
    private const GUIDE_FEE_REFERENCE = 100;

    /**
     * SQL: the level points booking `b` (a paid trip) earns its `$role`
     * side — 1 for the traveller; for the guide `1 + 0.5 × min(1, fee / £100)`,
     * slightly more for a better-paid trip, capped so price can't buy levels.
     */
    public static function pointsSql(string $role): string
    {
        return $role === 'guide'
            ? '(1 + ' . self::GUIDE_FEE_BONUS . ' * LEAST(1, COALESCE(b.`amount`, 0) / ' . self::GUIDE_FEE_REFERENCE . '))'
            : '1';
    }

    /**
     * `$userId`'s record in `$role`: every finished trip, the paid ones, and
     * the level points those earned.
     *
     * @return array{trips: int, paid: int, points: float}
     */
    public function recordFor(int $userId, string $role): array
    {
        $side = $role === 'guide' ? 'guide_id' : 'guest_id';
        $row = $this->db->db_GetRow(
            'SELECT COUNT(*) AS `trips`, COALESCE(SUM(' . self::PAID_TRIP . '), 0) AS `paid`,
                    COALESCE(SUM(IF(' . self::PAID_TRIP . ', ' . self::pointsSql($role) . ', 0)), 0) AS `points`
             FROM `bookings` b
             WHERE ' . self::FINISHED_TRIP . ' AND b.`' . $side . '` = ' . $userId
        ) ?: [];

        return [
            'trips' => (int) ($row['trips'] ?? 0),
            'paid' => (int) ($row['paid'] ?? 0),
            'points' => round((float) ($row['points'] ?? 0), 2),
        ];
    }

    public static function levelForPoints(float $points): int
    {
        $level = 0;
        foreach (self::THRESHOLDS as $candidate => $needed) {
            // A hair of tolerance so 1.1 + 1.9 points read as the 3 they are.
            if ($points + 1e-9 >= $needed) {
                $level = $candidate;
            }
        }

        return $level;
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

    /** `$userId`'s level in `$role`, after any active sanction. */
    public function effectiveLevel(int $userId, string $role): int
    {
        $level = self::levelForPoints($this->recordFor($userId, $role)['points'])
            - $this->activeSanctions($userId)['levels'] + $this->activeAdjustment($userId, $role);

        return max(0, min(self::TOP_LEVEL, $level));
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
               AND `revoked_at` IS NULL AND `starts_at` <= UTC_TIMESTAMP() AND `ends_at` > UTC_TIMESTAMP()',
            'ts'
        );

        return $ts ? gmdate('Y-m-d\TH:i:s\Z', (int) $ts) : null;
    }

    /** The guide's platform fee right now: 0 during a no-fee bonus, else by level. */
    public function guideCommissionRate(int $guideId): float
    {
        return $this->activeBonusUntil($guideId, 'no_fee') ? 0.0 : self::commissionRate($this->effectiveLevel($guideId, 'guide'));
    }

    /**
     * Grants whatever `$userId` has earned in each role they hold and isn't
     * granted yet, and tells them about a level-up or a new bonus.
     */
    public function sync(int $userId): void
    {
        foreach (Roles::of($userId, $this->db) as $role) {
            $this->syncRole($userId, $role);
        }
    }

    /** `sync()` for one role (one they hold): `role_profiles.level` is the level last announced in it. */
    private function syncRole(int $userId, string $role): void
    {
        $previous = (int) ($this->db->db_GetRow(
            'SELECT `level` FROM `role_profiles` WHERE `user_id` = ' . $userId . ' AND `role` = "' . $this->db->db_Escape($role) . '"'
        )['level'] ?? 0);

        // The highest level announced, so a level comes with one
        // announcement — not another each time a sanction expires.
        $level = $this->effectiveLevel($userId, $role);
        if ($level > $previous) {
            // Only ever raised; the row appears with the first level.
            $this->db->db_Execute(
                'INSERT INTO `role_profiles` (`user_id`, `role`, `level`)
                 VALUES (' . $userId . ', "' . $this->db->db_Escape($role) . '", ' . $level . ')
                 ON DUPLICATE KEY UPDATE `level` = IF(`level` < VALUES(`level`), VALUES(`level`), `level`)'
            );
        }
        if ($level > $previous && $this->db->db_GetAffectedRows() > 0) {
            $this->notify($userId, 'level_up', ($role === 'guest' ? 'As a traveller, you' : 'As a guide, you')
                . ' reached level ' . $level . ' · ' . ucfirst(self::NAMES[$level]) . '! '
                . ($role === 'guest'
                    ? 'Your next packs have ' . self::picksPerPack($level) . ' picks.'
                    : 'Your platform fee is now ' . round(self::commissionRate($level) * 100) . '%.'), $role);
        }

        $bonuses = $role === 'guest' ? self::GUEST_LEVEL_BONUSES : self::GUIDE_LEVEL_BONUSES;
        $kind = $role === 'guest' ? 'free_picks' : 'no_fee';
        foreach ($bonuses as $atLevel => $days) {
            if ($level >= $atLevel) {
                $this->grant($userId, $kind, $days, $role . '_level_' . $atLevel);
            }
        }

        if ($role === 'guide') {
            $reviews = $this->db->db_GetRow(
                'SELECT COUNT(*) AS `authors`, AVG(`author_average`) AS `average` FROM (
                    SELECT AVG(`rating`) AS `author_average` FROM `reviews`
                    WHERE `subject_id` = ' . $userId . ' AND `subject_role` = "guide" GROUP BY `author_id`
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
        $record = $this->recordFor($userId, $role);
        $earned = self::levelForPoints($record['points']);
        $sanctions = $this->activeSanctions($userId);
        $level = max(0, $earned - $sanctions['levels']);
        $next = $earned + 1 < count(self::THRESHOLDS) ? self::THRESHOLDS[$earned + 1] : null;
        // Each role's own bonuses: free picks for travelling, fee-free days for guiding.
        $kind = $role === 'guest' ? 'free_picks' : 'no_fee';

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
             FROM `bonus_grants` WHERE `user_id` = ' . $userId . ' AND `kind` = "' . $kind . '" AND `revoked_at` IS NULL ORDER BY `id`'
        ) ?: []);

        return [
            'role' => $role,
            'level' => $level,
            'name' => self::NAMES[$level],
            // Every finished trip in this role; only the paid ones earn points.
            'trips_completed' => $record['trips'],
            'paid_trips' => $record['paid'],
            'points' => $record['points'],
            'next_level_points' => $next,
            // A guide who has only guided for free.
            'volunteer' => $role === 'guide' && $record['trips'] > 0 && $record['paid'] === 0,
            // The level those points lead to (by earned level — a sanction lowers the badge, not the count).
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
             WHERE `id` = ' . $bonusId . ' AND `user_id` = ' . $userId . ' AND `revoked_at` IS NULL'
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
                WHERE `user_id` = ' . $userId . ' AND `kind` = "' . $this->db->db_Escape($grant['kind']) . '" AND `ends_at` > UTC_TIMESTAMP() AND `revoked_at` IS NULL
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
                : 'for reaching level ' . substr($reason, strrpos($reason, '_') + 1);
            $this->notify($userId, 'bonus_granted', $kind === 'free_picks'
                ? "You've earned {$days} days of free picks ({$why})! Claim them on your profile whenever you're ready."
                : "You've earned {$days} days without a platform fee ({$why})! Claim them on your profile whenever you like.",
                $kind === 'free_picks' ? 'guest' : 'guide');
        }
    }

    private function notify(int $userId, string $kind, string $message, string $role): void
    {
        if ($this->notifications) {
            $this->notifications->notifyInApp($userId, $kind, $message, null, $role);
        }
    }
}
