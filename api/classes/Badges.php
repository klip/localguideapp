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
     * Counts for several users in two queries (the server asks for every
     * connected user at once).
     *
     * @param int[] $userIds
     * @return array<int, array{matches: int, trips: int, messages: int}>
     */
    public function countsFor(array $userIds): array
    {
        $ids = array_values(array_unique(array_map('intval', $userIds)));
        $out = [];
        foreach ($ids as $id) {
            $out[$id] = ['matches' => 0, 'trips' => 0, 'messages' => 0];
        }
        if (!$ids) {
            return $out;
        }
        $in = implode(',', $ids);

        $matchRows = $this->db->db_GetArray(
            'SELECT `uid`, COUNT(*) AS `n` FROM (
                SELECT s.`guest_id` AS `uid` FROM `selections` s
                WHERE s.`guest_id` IN (' . $in . ') AND s.`active` = 1
                  AND (s.`guest_seen_at` IS NULL OR s.`guest_seen_at` < s.`matched_at`)
                  AND NOT ' . self::IS_TRIP . '
                UNION ALL
                SELECT s.`guide_id` AS `uid` FROM `selections` s
                WHERE s.`guide_id` IN (' . $in . ') AND s.`active` = 1
                  AND (s.`guide_seen_at` IS NULL OR s.`guide_seen_at` < s.`matched_at`)
                  AND NOT ' . self::IS_TRIP . '
             ) x GROUP BY `uid`'
        );
        foreach ($matchRows ?: [] as $row) {
            $out[(int) $row['uid']]['matches'] = (int) $row['n'];
        }

        $tripRows = $this->db->db_GetArray(
            'SELECT `uid`, COUNT(*) AS `n` FROM (
                SELECT b.`guest_id` AS `uid` FROM `bookings` b
                WHERE b.`guest_id` IN (' . $in . ') AND b.`status` = "confirmed" AND b.`trip_status` <> "finished"
                  AND (b.`guest_seen_at` IS NULL OR b.`guest_seen_at` < COALESCE(b.`accepted_at`, b.`created_at`))
                UNION ALL
                SELECT b.`guide_id` AS `uid` FROM `bookings` b
                WHERE b.`guide_id` IN (' . $in . ') AND b.`status` = "confirmed" AND b.`trip_status` <> "finished"
                  AND (b.`guide_seen_at` IS NULL OR b.`guide_seen_at` < COALESCE(b.`accepted_at`, b.`created_at`))
             ) x GROUP BY `uid`'
        );
        foreach ($tripRows ?: [] as $row) {
            $out[(int) $row['uid']]['trips'] = (int) $row['n'];
        }

        // Unread chat messages — cleared by opening the conversation, not by `markSeen`.
        foreach (Messages::unreadCounts($this->db, $ids) as $id => $count) {
            $out[$id]['messages'] = $count;
        }

        return $out;
    }

    /** The user opened /matches or /trips: everything there now counts as seen. */
    public function markSeen(int $userId, string $section): void
    {
        if (!in_array($section, self::SECTIONS, true)) {
            throw new RuntimeException('Unknown section.');
        }

        $table = $section === 'matches' ? 'selections' : 'bookings';
        $scope = $section === 'matches' ? '`active` = 1' : '`status` = "confirmed"';
        foreach (['guest', 'guide'] as $side) {
            $this->db->db_Execute(
                'UPDATE `' . $table . '` SET `' . $side . '_seen_at` = UTC_TIMESTAMP()
                 WHERE `' . $side . '_id` = ' . $userId . ' AND ' . $scope
            );
        }
    }
}
