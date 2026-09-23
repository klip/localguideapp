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

    /** @return int the new payment id */
    public function recordPackPurchase(int $userId): int
    {
        $id = $this->db->db_Insert('payments', [
            'user_id' => $userId,
            'amount' => self::PACK_PRICE,
            'pack_size' => self::PACK_SIZE,
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

    public function getCapacity(int $userId): int
    {
        return $this->getPacksUnlocked($userId) * self::PACK_SIZE;
    }
}
