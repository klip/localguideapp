<?php

/**
 * Data access for `payments` — pack purchases. There's no real payment
 * gateway wired up yet (see client/src/pages/UnlockPage.vue's mock `pay()`
 * handler): calling `recordPackPurchase()` *is* the payment, recorded as
 * `status = 'paid'` immediately.
 */
class Payments
{
    public const PACK_SIZE = 5;
    public const PACK_PRICE = 5.00;

    /** @var db */
    private $db;

    public function __construct(db $db)
    {
        $this->db = $db;
    }

    /**
     * Records one £5 pack of `$packSize` picks — more for a higher level
     * (`Levels::picksPerPack()`), fixed at purchase.
     *
     * @return int the new payment id
     */
    public function recordPackPurchase(int $userId, int $packSize = self::PACK_SIZE): int
    {
        $id = $this->db->db_Insert('payments', [
            'user_id' => $userId,
            'amount' => self::PACK_PRICE,
            'pack_size' => max(1, $packSize),
            'status' => 'paid',
        ]);

        if (!$id) {
            throw new RuntimeException('Could not record payment.');
        }

        return (int) $id;
    }

    public function getPacksUnlocked(int $userId): int
    {
        return (int) $this->db->db_GetFieldFromQuery(
            'SELECT COUNT(*) AS n FROM `payments` WHERE `user_id` = ' . $userId . ' AND `status` = "paid"',
            'n'
        );
    }

    /** Picks bought in total — each pack's own size, as recorded when it was bought. */
    public function getCapacity(int $userId): int
    {
        return (int) $this->db->db_GetFieldFromQuery(
            'SELECT COALESCE(SUM(`pack_size`), 0) AS n FROM `payments` WHERE `user_id` = ' . $userId . ' AND `status` = "paid"',
            'n'
        );
    }

    /**
     * The Payments page (`paymentHistory`): every money movement for
     * `$userId` on the acting side, newest first, and every bonus of that
     * side's kind, past and present.
     *
     * Traveller: pack purchases and the guide fees they paid (with any
     * refund). Guide: the fees paid to them, with the platform fee and
     * payout fixed at payment (`bookings.commission_*`), and any refund.
     * Bonuses: free picks for a traveller, fee-free days for a guide, each
     * `available | queued | active | used | revoked`.
     */
    public function historyFor(int $userId, string $role): array
    {
        $entries = [];
        if ($role === 'guest') {
            foreach ($this->db->db_GetArray(
                'SELECT `id`, `amount`, `pack_size`, `status`, UNIX_TIMESTAMP(`created_at`) AS `at`
                 FROM `payments` WHERE `user_id` = ' . $userId . ' ORDER BY `id` DESC'
            ) ?: [] as $p) {
                $entries[] = [
                    'type' => 'pack',
                    'id' => (int) $p['id'],
                    'at' => self::iso($p['at']),
                    'amount' => (float) $p['amount'],
                    'packSize' => (int) $p['pack_size'],
                    'status' => (string) $p['status'],
                ];
            }
        }

        $their = $role === 'guest' ? 'guide' : 'guest';
        foreach ($this->db->db_GetArray(
            'SELECT b.`id`, b.`amount`, b.`status`, b.`payment_status`, b.`refund_amount`,
                    b.`commission_rate`, b.`commission_amount`, b.`guide_payout`, b.`trip_status`,
                    UNIX_TIMESTAMP(b.`created_at`) AS `at`, UNIX_TIMESTAMP(b.`meeting_at`) AS `meeting`,
                    UNIX_TIMESTAMP(b.`cancelled_at`) AS `refunded`,
                    u.`id` AS `counterpart_id`, u.`name` AS `counterpart_name`
             FROM `bookings` b
             LEFT JOIN `users` u ON u.`id` = b.`' . $their . '_id`
             WHERE b.`' . ($role === 'guest' ? 'guest' : 'guide') . '_id` = ' . $userId . '
               AND b.`payment_status` IN ("paid", "refunded")
             ORDER BY b.`id` DESC'
        ) ?: [] as $b) {
            $refunded = $b['payment_status'] === 'refunded';
            $entries[] = [
                'type' => 'booking',
                'id' => (int) $b['id'],
                'at' => self::iso($b['at']),
                'amount' => (float) $b['amount'],
                'status' => (string) $b['status'],
                'tripStatus' => (string) $b['trip_status'],
                'meetingAt' => self::iso($b['meeting']),
                'counterpart' => $b['counterpart_id'] !== null
                    ? ['id' => (int) $b['counterpart_id'], 'name' => (string) ($b['counterpart_name'] ?? '')]
                    : null,
                'refundAmount' => $refunded && $b['refund_amount'] !== null ? (float) $b['refund_amount'] : null,
                'refundedAt' => $refunded ? self::iso($b['refunded']) : null,
                // The platform fee is the guide's business only.
                'commissionAmount' => $role === 'guide' && $b['commission_amount'] !== null ? (float) $b['commission_amount'] : null,
                'commissionRate' => $role === 'guide' && $b['commission_rate'] !== null ? (float) $b['commission_rate'] : null,
                'payout' => $role === 'guide' && $b['guide_payout'] !== null ? (float) $b['guide_payout'] : null,
            ];
        }
        usort($entries, static fn(array $a, array $b): int => strcmp((string) $b['at'], (string) $a['at']));

        $kind = $role === 'guest' ? 'free_picks' : 'no_fee';
        $now = time();
        $bonuses = array_map(static function (array $g) use ($now): array {
            $starts = $g['starts_ts'] === null ? null : (int) $g['starts_ts'];
            $ends = $g['ends_ts'] === null ? null : (int) $g['ends_ts'];
            $state = $g['revoked_ts'] !== null ? 'revoked'
                : ($starts === null ? 'available' : ($starts > $now ? 'queued' : ($ends > $now ? 'active' : 'used')));

            return [
                'id' => (int) $g['id'],
                'kind' => (string) $g['kind'],
                'days' => (int) $g['days'],
                'reason' => (string) $g['reason'],
                'state' => $state,
                'grantedAt' => self::iso($g['granted_ts']),
                'startsAt' => self::iso($starts),
                'endsAt' => self::iso($ends),
                'revokedAt' => self::iso($g['revoked_ts']),
            ];
        }, $this->db->db_GetArray(
            'SELECT `id`, `kind`, `days`, `reason`, UNIX_TIMESTAMP(`granted_at`) AS `granted_ts`,
                    UNIX_TIMESTAMP(`starts_at`) AS `starts_ts`, UNIX_TIMESTAMP(`ends_at`) AS `ends_ts`,
                    UNIX_TIMESTAMP(`revoked_at`) AS `revoked_ts`
             FROM `bonus_grants` WHERE `user_id` = ' . $userId . ' AND `kind` = "' . $kind . '"
             ORDER BY `id` DESC'
        ) ?: []);

        return ['entries' => $entries, 'bonuses' => $bonuses];
    }

    /** A UNIX time (or null) as ISO 8601 UTC. */
    private static function iso($ts): ?string
    {
        return $ts === null ? null : gmdate('Y-m-d\TH:i:s\Z', (int) $ts);
    }
}
