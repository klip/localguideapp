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
 *   level (`Levels`: from finished trips, minus any active
 *     sanction): 5 × level — Starter +5 … Icon +35         0 … +35
 *   a photo +5, a bio +5                                     0 … +10
 *   a new account, fading over 21 days                       0 … +15
 *   searching near a point (`near`): +60 within the person's
 *     own radius (an empty, flexible radius counts as 25 km
 *     here), fading to 0 at 100 km beyond it, plus up to +15
 *     fading over 1,000 km (so nearer still helps further
 *     out); no location: 0                                     0 … +75
 *
 * Proximity outweighs everything else on purpose: someone searching Lisbon
 * wants Lisbon guides first, and moving the search point has to reorder the
 * deck.
 *
 * The score itself never leaves the server (so nobody tunes their profile to
 * a number); the client gets the facts behind it as badges:
 * `verified`, `top_rated` (≥ 5 reviews averaging ≥ 4.6), `level` and
 * `trips_completed`. A sanction lowers the level, and so the place in the
 * deck too.
 */
final class Ranking
{
    /** Reviews counted as if every person started with them (the Bayesian prior). */
    private const PRIOR_REVIEWS = 5;
    private const PRIOR_AVERAGE = 4.0;
    /** A new account's boost (points) and how many days it takes to fade out. */
    private const NEW_BOOST = 15;
    private const NEW_BOOST_DAYS = 21;
    /** Proximity: points within the radius, how far beyond it they fade out, and the radius assumed for a flexible one. */
    private const NEAR_BOOST = 60;
    private const NEAR_FADE_KM = 100;
    /** Also the radius `Users::searchByRole()`'s distance limit assumes for a flexible guide. */
    public const FLEXIBLE_RADIUS_KM = 25;
    private const FAR_BOOST = 15;
    private const FAR_FADE_KM = 1000;

    /**
     * Trips per user **in `$role`** (finished, as that side), joined as `ts`:
     * `trips_completed` counts every one — free trips too, so a volunteer
     * guide's record shows — while `level_points`/`paid_trips` count only
     * paid ones, the only trips that earn a level (`Levels::pointsSql()`).
     * `$userId` narrows it to one person (a profile page) instead of everyone.
     */
    public static function tripsJoin(?int $userId, string $role): string
    {
        $side = $role === 'guide' ? 'guide_id' : 'guest_id';

        return 'LEFT JOIN (
                SELECT b.`' . $side . '` AS `uid`,
                       COUNT(*) AS `trips_completed`,
                       SUM(' . Levels::PAID_TRIP . ') AS `paid_trips`,
                       SUM(IF(' . Levels::PAID_TRIP . ', ' . Levels::pointsSql($role) . ', 0)) AS `level_points`
                FROM `bookings` b
                WHERE ' . Levels::FINISHED_TRIP . ($userId ? ' AND b.`' . $side . '` = ' . $userId : '') . '
                GROUP BY b.`' . $side . '`
             ) ts ON ts.`uid` = u.`id`';
    }

    /**
     * Reviews as the ranking counts them: one per distinct author (their
     * reviews of this person averaged), so a second, third… trip with the
     * same person can't stack 5★ reviews. Joined as `rr`. The review count
     * and average *shown* on a profile still count every review (`Reviews`).
     */
    public static function reviewsJoin(?int $userId = null, ?string $role = null): string
    {
        // Ratings are per role (`reviews.subject_role`): a guide is ranked on
        // their reviews as a guide, a traveller on theirs as a traveller.
        $conditions = array_filter([
            $userId ? '`subject_id` = ' . $userId : null,
            $role ? '`subject_role` = "' . ($role === 'guide' ? 'guide' : 'guest') . '"' : null,
        ]);

        return 'LEFT JOIN (
                SELECT `subject_id`, COUNT(*) AS `authors`, SUM(`author_average`) AS `author_sum` FROM (
                    SELECT `subject_id`, `author_id`, AVG(`rating`) AS `author_average` FROM `reviews`
                    ' . ($conditions ? 'WHERE ' . implode(' AND ', $conditions) : '') . '
                    GROUP BY `subject_id`, `author_id`
                ) a GROUP BY `subject_id`
             ) rr ON rr.`subject_id` = u.`id`';
    }

    /**
     * Columns for the query's SELECT: the badges, and `rank_score` for
     * ORDER BY (dropped before the row is returned — see `castRow()`).
     * Needs `u` (users), `p` (user_profiles), `urx` (their `user_role` row
     * for the role listed — headline/bio), `rr` (`reviewsJoin()`), `ts`
     * (`tripsJoin()`) and `sx` (`Levels::sanctionsJoin()`).
     */
    public static function columns(string $role, ?string $distanceSql = null, ?string $radiusSql = null): string
    {
        return 'u.`verified_at` IS NOT NULL AS `verified`,
                COALESCE(ts.`trips_completed`, 0) AS `trips_completed`,
                ' . ($role === 'guide'
                    ? '(COALESCE(ts.`trips_completed`, 0) > 0 AND COALESCE(ts.`paid_trips`, 0) = 0)'
                    : '0') . ' AS `volunteer`,
                ' . Levels::effectiveLevelSql() . ' AS `level`,
                (COALESCE(rr.`authors`, 0) >= 5 AND rr.`author_sum` / rr.`authors` >= 4.6) AS `top_rated`,
                ' . self::scoreSql($distanceSql, $radiusSql) . ' AS `rank_score`';
    }

    public const ORDER_BY = 'ORDER BY `rank_score` DESC, u.`id` DESC';

    /**
     * `$distanceSql`: km from the search point to the person (NULL when they
     * have no location); `$radiusSql`: their travel radius (NULL = flexible).
     * Both null: no search point, no proximity term.
     */
    private static function scoreSql(?string $distanceSql = null, ?string $radiusSql = null): string
    {
        $prior = self::PRIOR_REVIEWS . ' * ' . self::PRIOR_AVERAGE;
        $beyond = 'GREATEST(0, ' . $distanceSql . ' - '
            . ($radiusSql === null ? self::FLEXIBLE_RADIUS_KM : 'COALESCE(' . $radiusSql . ', ' . self::FLEXIBLE_RADIUS_KM . ')') . ')';
        $proximity = $distanceSql === null ? '' : '
            + COALESCE(' . self::NEAR_BOOST . ' * GREATEST(0, 1 - ' . $beyond . ' / ' . self::NEAR_FADE_KM . ')
                + ' . self::FAR_BOOST . ' * GREATEST(0, 1 - ' . $beyond . ' / ' . self::FAR_FADE_KM . '), 0)';

        return '(
            IF(u.`verified_at` IS NULL, 0, 30)
            + ((' . $prior . ' + COALESCE(rr.`author_sum`, 0)) / (' . self::PRIOR_REVIEWS . ' + COALESCE(rr.`authors`, 0)) - 3) * 15
            + 5 * ' . Levels::effectiveLevelSql() . '
            + IF(u.`image` IS NULL OR u.`image` = "", 0, 5)
            + IF(urx.`bio` IS NULL OR urx.`bio` = "", 0, 5)
            + GREATEST(0, ' . self::NEW_BOOST . ' * (1 - TIMESTAMPDIFF(HOUR, u.`created_at`, UTC_TIMESTAMP()) / (' . self::NEW_BOOST_DAYS . ' * 24)))' . $proximity . '
        )';
    }

    /** mysqli returns strings: cast the badges, and drop the score itself. */
    public static function castRow(array $row): array
    {
        $row['verified'] = (bool) (int) $row['verified'];
        $row['top_rated'] = (bool) (int) ($row['top_rated'] ?? 0);
        $row['trips_completed'] = (int) $row['trips_completed'];
        // A guide who has only guided for free: "Volunteer" instead of a level.
        $row['volunteer'] = (bool) (int) ($row['volunteer'] ?? 0);
        $row['level'] = (int) $row['level'];
        // Only when searching near a point; null for someone with no location.
        if (array_key_exists('distance_km', $row)) {
            $row['distance_km'] = $row['distance_km'] === null ? null : (int) $row['distance_km'];
        }
        unset($row['rank_score']);

        return $row;
    }
}
