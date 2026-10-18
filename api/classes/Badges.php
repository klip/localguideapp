<?php

/**
 * The red counters on the Matches, My trips and Messages menu items: how many of a
 * user's matches and trips they haven't looked at yet (020_seen_markers.sql).
 *
 * - A **match** counts while it's on /matches' Matched tab — active, and its
 *   latest booking isn't confirmed (a confirmed one is a trip) — and the
 *   user's `*_seen_at` is older than `matched_at`.
 * - A **trip** counts while it's confirmed and unfinished and the user's
 *   `*_seen_at` is older than `accepted_at`. A match that turns into a trip
 *   therefore leaves the first counter and enters the second.
 *
 * Opening the page (`markSeen`) clears that counter. The WebSocket server
 * (`Realtime`) pushes the counts whenever they change.
 *
 * Counts are kept **per role** (`byRole.guest` / `byRole.guide` — the side
 * the user is on in each pair), since an account can hold both: the menu
 * shows the acting role's, and a dot on the mode switch says the other one
 * has news. The top-level `matches`/`trips`/`messages` are the totals.
 */
class Badges
{
    public const SECTIONS = ['matches', 'trips'];

    /** The pair's latest booking is confirmed — it's a trip, not a match. */
    private const IS_TRIP = 'EXISTS (
        SELECT 1 FROM `bookings` b
        WHERE b.`selection_id` = s.`id` AND b.`status` = "confirmed"
          AND b.`id` = (SELECT MAX(b2.`id`) FROM `bookings` b2 WHERE b2.`selection_id` = s.`id`)
    )';

    /** @var db */
    private $db;

    public function __construct(db $db)
    {
        $this->db = $db;
    }


    /**
     * Counts for several users in a few queries (the server asks for every
     * connected user at once), per role and in total.
     *
     * @param int[] $userIds
     * @return array<int, array{matches: int, trips: int, messages: int, byRole: array<string, array{matches: int, trips: int, messages: int}>}>
     */
    public function countsFor(array $userIds): array
    {
        $ids = array_values(array_unique(array_map('intval', $userIds)));
        if (!$ids) {
            return [];
        }
        $zero = ['matches' => 0, 'trips' => 0, 'messages' => 0];
        $byRole = [];
        foreach ($ids as $id) {
            $byRole[$id] = ['guest' => $zero, 'guide' => $zero];
        }
        $in = implode(',', $ids);

        $matchRows = $this->db->db_GetArray(
            'SELECT `uid`, `role`, COUNT(*) AS `n` FROM (
                SELECT s.`guest_id` AS `uid`, "guest" AS `role` FROM `selections` s
                WHERE s.`guest_id` IN (' . $in . ') AND s.`active` = 1
                  AND (s.`guest_seen_at` IS NULL OR s.`guest_seen_at` < s.`matched_at`)
                  AND NOT ' . self::IS_TRIP . '
                UNION ALL
                SELECT s.`guide_id` AS `uid`, "guide" AS `role` FROM `selections` s
                WHERE s.`guide_id` IN (' . $in . ') AND s.`active` = 1
                  AND (s.`guide_seen_at` IS NULL OR s.`guide_seen_at` < s.`matched_at`)
                  AND NOT ' . self::IS_TRIP . '
             ) x GROUP BY `uid`, `role`'
        );
        foreach ($matchRows ?: [] as $row) {
            $byRole[(int) $row['uid']][$row['role']]['matches'] = (int) $row['n'];
        }

        $tripRows = $this->db->db_GetArray(
            'SELECT `uid`, `role`, COUNT(*) AS `n` FROM (
                SELECT b.`guest_id` AS `uid`, "guest" AS `role` FROM `bookings` b
                WHERE b.`guest_id` IN (' . $in . ') AND b.`status` = "confirmed" AND b.`trip_status` <> "finished"
                  AND (b.`guest_seen_at` IS NULL OR b.`guest_seen_at` < COALESCE(b.`accepted_at`, b.`created_at`))
                UNION ALL
                SELECT b.`guide_id` AS `uid`, "guide" AS `role` FROM `bookings` b
                WHERE b.`guide_id` IN (' . $in . ') AND b.`status` = "confirmed" AND b.`trip_status` <> "finished"
                  AND (b.`guide_seen_at` IS NULL OR b.`guide_seen_at` < COALESCE(b.`accepted_at`, b.`created_at`))
             ) x GROUP BY `uid`, `role`'
        );
        foreach ($tripRows ?: [] as $row) {
            $byRole[(int) $row['uid']][$row['role']]['trips'] = (int) $row['n'];
        }

        // Unread chat messages — cleared by opening the conversation, not by `markSeen`.
        foreach (Messages::unreadCountsByRole($this->db, $ids) as $id => $counts) {
            foreach ($counts as $role => $count) {
                $byRole[$id][$role]['messages'] = $count;
            }
        }

        $out = [];
        foreach ($byRole as $id => $roles) {
            $out[$id] = [
                'matches' => $roles['guest']['matches'] + $roles['guide']['matches'],
                'trips' => $roles['guest']['trips'] + $roles['guide']['trips'],
                'messages' => $roles['guest']['messages'] + $roles['guide']['messages'],
                'byRole' => $roles,
            ];
        }

        return $out;
    }

    /**
     * The user opened /matches or /trips as `$role`: everything there on that
     * side now counts as seen (the other role's news stays news).
     */
    public function markSeen(int $userId, string $section, string $role): void
    {
        if (!in_array($section, self::SECTIONS, true)) {
            throw new RuntimeException('Unknown section.');
        }

        $table = $section === 'matches' ? 'selections' : 'bookings';
        $scope = $section === 'matches' ? '`active` = 1' : '`status` = "confirmed"';
        $side = $role === 'guide' ? 'guide' : 'guest';
        $this->db->db_Execute(
            'UPDATE `' . $table . '` SET `' . $side . '_seen_at` = UTC_TIMESTAMP()
             WHERE `' . $side . '_id` = ' . $userId . ' AND ' . $scope
        );
    }
}
