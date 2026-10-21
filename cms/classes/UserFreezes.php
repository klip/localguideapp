<?php

/**
 * Freezing (suspending) an app account from the CMS — `user_freezes` (080),
 * docs/auth-cms-plan.md phase 8.
 *
 * - A freeze lasts `days` or is permanent, with a required reason. It ends
 *   every sign-in at once (`Revocations::endAllSignIns()` + `revokeUser()`, so
 *   gateways drop the access tokens and sockets within seconds); auth then
 *   refuses sign-in and refresh, and the API hides the account from decks,
 *   the planner and public profiles and makes chats with it read-only (all
 *   through `Freezes`).
 * - **Open bookings aren't changed by themselves**: the freeze names a
 *   decision per booking — leave it, or cancel it with a full or partial
 *   refund. Cancelling goes through the API (`staffCancelBooking`), which
 *   owns bookings and tells both sides. Bookings left open stay on the user
 *   page as "needs a decision" (`decideBooking()`).
 * - Lifting ends a freeze early; the user signs in again as usual.
 */
final class UserFreezes
{
    /** @var db */
    private $db;
    /** @var RedisClient */
    private $redis;
    /** @var string */
    private $apiUrl;

    public function __construct(db $db, RedisClient $redis, ?string $apiUrl = null)
    {
        $this->db = $db;
        $this->redis = $redis;
        $this->apiUrl = $apiUrl ?? (getenv('API_URL') ?: 'http://nginx:8081');
    }

    /**
     * `$input`: `days` (1–3650) or `permanent: true`; `reason` (required);
     * `bookings: [{ id, decision: leave | cancel, refund?: number }]` — a
     * cancel without `refund` refunds the whole fee. Returns `[$before,
     * $after]` for the audit log; `$after['bookings']` says what happened to
     * each (a failed cancel doesn't undo the freeze — it stays to decide).
     */
    public function freeze(int $userId, array $input, array $staff): array
    {
        if (!$this->db->db_GetRow('SELECT `id` FROM `users` WHERE `id` = ' . $userId)) {
            throw new RuntimeException('No such user.');
        }
        if (Freezes::activeFor($this->db, $userId) !== null) {
            throw new RuntimeException('This account is frozen already — lift that freeze first to set a different one.');
        }
        $permanent = !empty($input['permanent']);
        $days = $input['days'] ?? null;
        if (!$permanent && (!is_numeric($days) || (int) $days < 1 || (int) $days > 3650)) {
            throw self::fieldError('days', 'From 1 to 3,650 days — or tick “permanently”.');
        }
        $reason = trim((string) ($input['reason'] ?? ''));
        if ($reason === '' || mb_strlen($reason) > 500) {
            throw self::fieldError('reason', 'Say why (up to 500 characters) — it’s kept with the freeze.');
        }

        // Every open booking needs a decision, and only those.
        $open = [];
        foreach ((new UserAdmin($this->db))->openBookings($userId) as $booking) {
            $open[$booking['id']] = $booking;
        }
        $decisions = [];
        foreach ((array) ($input['bookings'] ?? []) as $item) {
            $id = (int) ($item['id'] ?? 0);
            if (!isset($open[$id])) {
                throw self::fieldError('bookings', 'Booking #' . $id . ' isn’t one of their open bookings any more — reload.');
            }
            $decision = (string) ($item['decision'] ?? 'leave');
            if (!in_array($decision, ['leave', 'cancel'], true)) {
                throw self::fieldError('bookings', 'Leave or cancel, for each booking.');
            }
            $refund = $item['refund'] ?? null;
            if ($decision === 'cancel' && $refund !== null && $refund !== ''
                && (!is_numeric($refund) || (float) $refund < 0 || (float) $refund > (float) $open[$id]['amount'] + 0.001)) {
                throw self::fieldError('bookings', 'Booking #' . $id . ': a refund from 0 to the fee.');
            }
            $decisions[$id] = ['decision' => $decision, 'refund' => $refund === '' ? null : $refund];
        }
        if ($missing = array_diff(array_keys($open), array_keys($decisions))) {
            throw self::fieldError('bookings', 'Decide about every open booking (#' . implode(', #', $missing) . ').');
        }

        $this->exec(
            'INSERT INTO `user_freezes` (`user_id`, `starts_at`, `ends_at`, `reason`, `staff_id`, `staff_email`) VALUES ('
            . $userId . ', UTC_TIMESTAMP(), ' . ($permanent ? 'NULL' : 'UTC_TIMESTAMP() + INTERVAL ' . (int) $days . ' DAY') . ', '
            . $this->quote($reason) . ', ' . (int) $staff['id'] . ', ' . $this->quote((string) $staff['email']) . ')'
        );
        $freezeId = (int) $this->db->db_GetLastId();

        // Signed out everywhere at once: refresh tokens revoked, and every
        // access token issued so far refused by the gateways.
        Revocations::endAllSignIns($this->db, $this->redis, $userId, 'frozen');
        try {
            Revocations::revokeUser($this->redis, $userId);
        } catch (Throwable $e) {
            Log::warning('Could not announce a frozen account', ['error' => $e->getMessage()]);
        }

        $results = [];
        foreach ($decisions as $bookingId => $choice) {
            $results[$bookingId] = $choice['decision'] === 'cancel'
                ? $this->cancelBooking($bookingId, $choice['refund'], 'The other side’s account was suspended.')
                : 'left open';
        }

        return [null, [
            'freeze' => $freezeId,
            'until' => $permanent ? 'permanently' : ((int) $days . ' days'),
            'reason' => $reason,
            'bookings' => $results,
        ]];
    }

    /** Ends freeze `$freezeId` early. */
    public function lift(int $userId, int $freezeId, string $reason, array $staff): array
    {
        $freeze = $this->db->db_GetRow('SELECT * FROM `user_freezes` WHERE `id` = ' . $freezeId . ' AND `user_id` = ' . $userId);
        if (!$freeze || $freeze['lifted_at'] !== null) {
            throw new RuntimeException('No such freeze, or it’s lifted already.');
        }
        $reason = trim($reason);
        if ($reason === '' || mb_strlen($reason) > 500) {
            throw self::fieldError('liftReason', 'Say why it’s lifted (up to 500 characters).');
        }
        $this->exec(
            'UPDATE `user_freezes` SET `lifted_at` = UTC_TIMESTAMP(), `lifted_by` = ' . $this->quote((string) $staff['email'])
            . ', `lift_reason` = ' . $this->quote($reason) . ' WHERE `id` = ' . $freezeId
        );

        return [['freeze' => $freezeId, 'reason' => $freeze['reason']], ['lifted' => $freezeId, 'reason' => $reason]];
    }

    /** A booking left open at a freeze: cancel it now (refund null = the whole fee). */
    public function decideBooking(int $userId, int $bookingId, $refund, string $note): array
    {
        $open = array_column((new UserAdmin($this->db))->openBookings($userId), null, 'id');
        if (!isset($open[$bookingId])) {
            throw new RuntimeException('That isn’t one of their open bookings any more.');
        }
        $result = $this->cancelBooking($bookingId, $refund === '' ? null : $refund, trim($note) ?: 'Cancelled by BeLikeLocal.');
        if ($result !== 'cancelled') {
            throw new RuntimeException($result);
        }

        return [['booking' => $bookingId, 'status' => $open[$bookingId]['status']], ['booking' => $bookingId, 'status' => 'cancelled', 'refund' => $refund]];
    }

    /** Asks the API to cancel (it owns bookings and their notifications): "cancelled" or why not. */
    private function cancelBooking(int $bookingId, $refund, string $note): string
    {
        try {
            [$status, $body] = ServiceHttp::call($this->apiUrl, [
                'action' => 'staffCancelBooking',
                'bookingId' => $bookingId,
                'refund' => $refund === null ? null : (float) $refund,
                'note' => $note,
            ]);
        } catch (RuntimeException $e) {
            Log::warning('Staff booking cancel failed', ['booking_id' => $bookingId, 'error' => $e->getMessage()]);

            return $e->getMessage();
        }

        return $status === 200 ? 'cancelled' : (string) ($body['error'] ?? 'The app refused (' . $status . ').');
    }

    private function exec(string $sql): void
    {
        if (!$this->db->db_Execute($sql)) {
            throw new RuntimeException('Could not save that.');
        }
    }

    /** @param mixed $value */
    private function quote($value): string
    {
        return $value === null ? 'NULL' : '"' . $this->db->db_Escape((string) $value) . '"';
    }

    private static function fieldError(string $field, string $error): RuntimeException
    {
        return new RuntimeException((string) json_encode(['field' => $field, 'error' => $error], JSON_UNESCAPED_UNICODE));
    }
}
