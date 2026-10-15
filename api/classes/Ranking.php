<?php

/**
 * Who comes first in the discovery decks, and the badges that show why —
 * SQL fragments `Users::searchByRole()` and `getPublicProfile()` put into the
 * same query as the list (no per-card lookups). Both sides of the deck are
 * ranked the same way: guides for guests, guests for guides.
 *
 * The rank score, highest first (a newer account wins a tie):
 *
 *   verified identity (`users.verified_at`, 034)            +30
 *   reviews: a Bayesian average — 5 imaginary 4.0★ reviews
 *     plus the real ones — so one 5★ review can't beat a
 *     long record: (average − 3) × 15                       −30 … +30
 *     (no reviews yet: +15)
 *   finished trips (as guest or guide): 5 × log2(1 + n),
 *     capped at 25 — 1 trip +5, 3 trips +10, 7 trips +15     0 … +25
 *   a photo +5, a bio +5                                     0 … +10
 *
 * The score itself never leaves the server (so nobody tunes their profile to
 * a number); the client gets the facts behind it as badges:
 * `verified`, `top_rated` (≥ 5 reviews averaging ≥ 4.6) and
 * `trips_completed`.
 *
 * Levels and sanctions (step 7) add to this.
 */
final class Ranking
{
    /** Reviews counted as if every person started with them (the Bayesian prior). */
    private const PRIOR_REVIEWS = 5;
    private const PRIOR_AVERAGE = 4.0;
    /** A new account's boost (points) and how many days it takes to fade out. */
    private const NEW_BOOST = 15;
    private const NEW_BOOST_DAYS = 21;

    /**
     * Finished trips per user, as guest or guide — counting each *other
     * person* once, so the same pair matching, finishing a free trip and
     * unmatching over and over earns nothing. Joined as `ts`. `$userId`
     * narrows it to one person (a profile page) instead of everyone.
     */
    public static function tripsJoin(?int $userId = null): string
    {
        $only = static fn(string $column): string => $userId ? ' AND `' . $column . '` = ' . $userId : '';

        return 'LEFT JOIN (
                SELECT `uid`, COUNT(DISTINCT `other`) AS `trips_completed` FROM (
                    SELECT `guest_id` AS `uid`, `guide_id` AS `other` FROM `bookings`
                    WHERE `status` = "confirmed" AND `trip_status` = "finished"' . $only('guest_id') . '
                    UNION ALL
                    SELECT `guide_id` AS `uid`, `guest_id` AS `other` FROM `bookings`
                    WHERE `status` = "confirmed" AND `trip_status` = "finished"' . $only('guide_id') . '
                ) t GROUP BY `uid`
             ) ts ON ts.`uid` = u.`id`';
    }

    /**
     * Reviews as the ranking counts them: one per distinct author (their
     * reviews of this person averaged), so a second, third… trip with the
     * same person can't stack 5★ reviews. Joined as `rr`. The review count
     * and average *shown* on a profile still count every review (`Reviews`).
     */
    public static function reviewsJoin(?int $userId = null): string
    {
        return 'LEFT JOIN (
                SELECT `subject_id`, COUNT(*) AS `authors`, SUM(`author_average`) AS `author_sum` FROM (
                    SELECT `subject_id`, `author_id`, AVG(`rating`) AS `author_average` FROM `reviews`
                    ' . ($userId ? 'WHERE `subject_id` = ' . $userId : '') . '
                    GROUP BY `subject_id`, `author_id`
                ) a GROUP BY `subject_id`
             ) rr ON rr.`subject_id` = u.`id`';
    }

    /**
     * Columns for the query's SELECT: the badges, and `rank_score` for
     * ORDER BY (dropped before the row is returned — see `castRow()`).
     * Needs `u` (users), `p` (user_profiles), `rr` (`reviewsJoin()`) and `ts`
     * (`tripsJoin()`).
     */
    public static function columns(): string
    {
        return 'u.`verified_at` IS NOT NULL AS `verified`,
                COALESCE(ts.`trips_completed`, 0) AS `trips_completed`,
                (COALESCE(rr.`authors`, 0) >= 5 AND rr.`author_sum` / rr.`authors` >= 4.6) AS `top_rated`,
                ' . self::scoreSql() . ' AS `rank_score`';
    }

    public const ORDER_BY = 'ORDER BY `rank_score` DESC, u.`id` DESC';

    private static function scoreSql(): string
    {
        $prior = self::PRIOR_REVIEWS . ' * ' . self::PRIOR_AVERAGE;

        return '(
            IF(u.`verified_at` IS NULL, 0, 30)
            + ((' . $prior . ' + COALESCE(rr.`author_sum`, 0)) / (' . self::PRIOR_REVIEWS . ' + COALESCE(rr.`authors`, 0)) - 3) * 15
            + LEAST(25, 5 * LOG2(1 + COALESCE(ts.`trips_completed`, 0)))
            + IF(u.`image` IS NULL OR u.`image` = "", 0, 5)
            + IF(p.`bio` IS NULL OR p.`bio` = "", 0, 5)
            + GREATEST(0, ' . self::NEW_BOOST . ' * (1 - TIMESTAMPDIFF(HOUR, u.`created_at`, UTC_TIMESTAMP()) / (' . self::NEW_BOOST_DAYS . ' * 24)))
        )';
    }

    /** mysqli returns strings: cast the badges, and drop the score itself. */
    public static function castRow(array $row): array
    {
        $row['verified'] = (bool) (int) $row['verified'];
        $row['top_rated'] = (bool) (int) ($row['top_rated'] ?? 0);
        $row['trips_completed'] = (int) $row['trips_completed'];
        unset($row['rank_score']);

        return $row;
    }
}
