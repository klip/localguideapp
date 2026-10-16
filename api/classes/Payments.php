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
}
