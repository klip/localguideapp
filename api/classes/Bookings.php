<?php

/**
 * Data access and rules for `bookings` (see `012_bookings.sql`): what a match
 * becomes once contact details are unlocked.
 *
 *   - A guide with a fee (`user_profiles.price_amount` > 0): the guest pays it
 *     through `pay()`, picking the meeting time. Nothing is actually charged —
 *     like `Payments`, the row is simply recorded as paid.
 *   - A free guide (price 0 or unset): `createFree()` opens the booking the
 *     moment the match forms — no payment step, no meeting time.
 *
 * Contact details for a pair are shown to *either* side only once a
 * confirmed booking exists (see `Selections::getOverview()`).
 *
 * The guide can cancel at any time (`cancelByGuide()`): the guest gets the fee
 * back — plus 5% when the cancellation is late — and their pick back.
 */
class Bookings
{
    /** Added to the refund when the guide cancels late — see `refundAmount()`. */
    public const LATE_CANCEL_RATE = 0.05;
    /** Cancelling closer than this to the meeting counts as late... */
    private const LATE_WINDOW_SECONDS = 24 * 3600;
    /** ...or closer than this, when the match itself is less than a day old. */
    private const LATE_WINDOW_FRESH_MATCH_SECONDS = 2 * 3600;
    private const FRESH_MATCH_SECONDS = 24 * 3600;
    private const MAX_BOOKING_AHEAD_SECONDS = 366 * 24 * 3600;

    /** @var db */
    private $db;
    /** @var Notifications */
    private $notifications;

    public function __construct(db $db, Notifications $notifications)
    {
        $this->db = $db;
        $this->notifications = $notifications;
    }

    /** The guide's current fee; unset counts as 0 (free). */
    public function guideFee(int $guideId): float
    {
        $fee = $this->db->db_GetFieldFromQuery(
            'SELECT `price_amount` FROM `user_profiles` WHERE `user_id` = ' . $guideId,
            'price_amount'
        );

        return max(0.0, (float) $fee);
    }

    /** The pair's confirmed booking whose trip hasn't finished, if any. */
    public function openFor(int $guestId, int $guideId): ?array
    {
        $row = $this->db->db_GetRow(
            'SELECT * FROM `bookings`
             WHERE `guest_id` = ' . $guestId . ' AND `guide_id` = ' . $guideId . '
               AND `status` = "confirmed" AND `trip_status` <> "finished"'
        );

        return $row ? self::cast($row) : null;
    }

    /**
     * Called by `Selections` when a pair has just become an active match.
     * Only a free guide gets a booking here; a paid one waits for `pay()`.
     */
    public function onMatched(array $selection): void
    {
        if ($this->guideFee((int) $selection['guide_id']) > 0) {
            return;
        }

        if ($this->createFree($selection)) {
            $guest = $this->user((int) $selection['guest_id']);
            $guide = $this->user((int) $selection['guide_id']);
            $guestName = self::nameOf($guest, 'A visitor');

            $this->notifications->notify(
                (int) $guide['id'],
                'booking_free',
                "You matched with {$guestName}. Their contact details are on your Matches page.",
                "New match: {$guestName}",
                "Hi " . self::nameOf($guide, 'there') . ",\n\n"
                . "You and {$guestName} are a match. You don't charge a fee, so your contact details have been shared with each other.\n\n"
                . self::contactBlock($guest)
                . "\nGet in touch to arrange the tour.\n\nRockGuide"
            );
        }
    }

    /**
     * Opens free bookings for any of this user's active matches with a free
     * guide that don't have one yet — matches formed before bookings existed,
     * or a guide who dropped their fee to 0 after matching. Silent: no
     * notifications for these.
     */
    public function ensureFreeBookingsFor(int $userId): void
    {
        $pending = $this->db->db_GetArray(
            'SELECT s.`id`, s.`guest_id`, s.`guide_id`
             FROM `selections` s
             LEFT JOIN `user_profiles` gp ON gp.`user_id` = s.`guide_id`
             WHERE (s.`guest_id` = ' . $userId . ' OR s.`guide_id` = ' . $userId . ')
               AND s.`active` = 1
               AND COALESCE(gp.`price_amount`, 0) <= 0
               AND NOT EXISTS (
                 SELECT 1 FROM `bookings` b WHERE b.`selection_id` = s.`id` AND b.`status` = "confirmed"
               )'
        );

        foreach ($pending as $selection) {
            $this->createFree($selection);
        }
    }

    /**
     * The guest pays the guide's fee for an active match. `$meetingAt` is an
     * ISO 8601 date-time (the client sends UTC); it must be in the future.
     * Returns the new booking.
     */
    public function pay(int $guestId, int $guideId, string $meetingAt): array
    {
        $selection = $this->activeSelection($guestId, $guideId);

        $fee = $this->guideFee($guideId);
        if ($fee <= 0) {
            throw new RuntimeException('This guide doesn’t charge a fee — no payment is needed.');
        }
        if ($this->openFor($guestId, $guideId)) {
            throw new RuntimeException('You have already booked this guide.');
        }

        $meeting = self::parseMeeting($meetingAt);

        $id = $this->db->db_Insert('bookings', [
            'selection_id' => (int) $selection['id'],
            'guest_id' => $guestId,
            'guide_id' => $guideId,
            'amount' => number_format($fee, 2, '.', ''),
            'meeting_at' => gmdate('Y-m-d H:i:s', $meeting),
            'status' => 'confirmed',
            'payment_status' => 'paid',
        ]);
        if (!$id) {
            // The unique `open_pair` index is what trips on a double submit.
            throw new RuntimeException('Could not record the payment. Please try again.');
        }

        $booking = $this->find((int) $id);
        $guest = $this->user($guestId);
        $guide = $this->user($guideId);
        $guestName = self::nameOf($guest, 'A visitor');
        $amount = self::money($fee);
        $when = self::formatMeeting($booking['meeting_at']);

        $this->notifications->notify(
            $guideId,
            'booking_paid',
            "{$guestName} paid your {$amount} fee for {$when}. Their contact details are on your Matches page.",
            "New booking: {$guestName} paid your fee",
            "Hi " . self::nameOf($guide, 'there') . ",\n\n"
            . "{$guestName} has paid your fee of {$amount} for a tour on {$when}.\n\n"
            . self::contactBlock($guest)
            . "\nGet in touch to arrange the details.\n\n"
            . "Need to cancel? You can do it from your Matches page at any time, and the visitor is refunded in full. "
            . "Cancelling within 24 hours of the meeting (2 hours if you matched less than a day ago) adds "
            . (int) round(self::LATE_CANCEL_RATE * 100) . "% to the refund.\n\nRockGuide"
        );

        return $booking;
    }

    /**
     * The guide cancels the pair's open booking: the fee is refunded (plus the
     * late-cancellation extra where it applies), the match is dissolved on
     * both sides — which also hands the guest their pick back — and the guest
     * is notified. Returns the cancelled booking.
     */
    public function cancelByGuide(int $guideId, int $guestId, string $reason = ''): array
    {
        $booking = $this->openFor($guestId, $guideId);
        if (!$booking) {
            throw new RuntimeException('There is no booking to cancel.');
        }

        $reason = mb_substr(trim($reason), 0, 256);
        $selection = $this->db->db_GetRowById($booking['selection_id'], 'selections');
        $refund = $booking['payment_status'] === 'paid'
            ? $this->refundAmount($booking, $selection ? $selection['matched_at'] : null)
            : null;

        $this->close($booking, $refund, $reason);

        // Both decisions go, not only the guide's: clearing the guest's
        // 'interested' is what returns their pick (`Selections::getPicksUsed()`).
        $this->db->db_Execute(
            'UPDATE `selections` SET `guest_decision` = NULL, `guide_decision` = NULL, `active` = 0
             WHERE `id` = ' . (int) $booking['selection_id']
        );

        $guest = $this->user($guestId);
        $guideName = self::nameOf($this->user($guideId), 'Your guide');
        $refundLine = $refund !== null
            ? "Your payment has been refunded: " . self::money($refund)
                . ($refund > $booking['amount'] ? " (your " . self::money($booking['amount']) . " fee plus "
                . (int) round(self::LATE_CANCEL_RATE * 100) . "% for the late cancellation)" : '') . ".\n"
            : '';

        $this->notifications->notify(
            $guestId,
            'booking_cancelled',
            "{$guideName} cancelled your booking."
                . ($refund !== null ? ' You have been refunded ' . self::money($refund) . '.' : '')
                . ' Your pick is back.',
            "{$guideName} cancelled your booking",
            "Hi " . self::nameOf($guest, 'there') . ",\n\n"
            . "{$guideName} has cancelled your booking"
            . ($booking['meeting_at'] ? ' for ' . self::formatMeeting($booking['meeting_at']) : '') . ".\n"
            . ($reason !== '' ? "Their reason: {$reason}\n" : '')
            . "\n" . $refundLine
            . "The selection you used on them is back in your pack, so you can pick another guide.\n\nRockGuide"
        );

        return $this->find($booking['id']);
    }

    /**
     * The guest walks away from a *free* booking (they unmatched). A paid one
     * can't be dropped this way — see `Selections::revoke()`. The guide is told.
     */
    public function cancelFreeByGuest(int $guestId, int $guideId): void
    {
        $booking = $this->openFor($guestId, $guideId);
        if (!$booking || $booking['payment_status'] === 'paid') {
            return;
        }

        $this->close($booking, null, 'Cancelled by the visitor');

        $guestName = self::nameOf($this->user($guestId), 'A visitor');
        $this->notifications->notify(
            $guideId,
            'booking_cancelled',
            "{$guestName} unmatched and cancelled your booking.",
            "{$guestName} cancelled",
            "Hi " . self::nameOf($this->user($guideId), 'there') . ",\n\n"
            . "{$guestName} has unmatched, so your booking with them is cancelled.\n\nRockGuide"
        );
    }

    /**
     * What the guest gets back if the guide cancels `$booking` now: the fee,
     * plus `LATE_CANCEL_RATE` of it when the meeting is less than 24 hours
     * away — or less than 2 hours away, for a match under a day old (a
     * same-day booking would otherwise always count as late).
     */
    public function refundAmount(array $booking, ?string $matchedAt, ?int $now = null): float
    {
        $now = $now ?? time();
        $amount = (float) $booking['amount'];

        $meeting = $booking['meeting_at'] ? strtotime($booking['meeting_at'] . ' UTC') : false;
        if ($meeting === false) {
            return round($amount, 2);
        }

        $matched = $matchedAt ? strtotime($matchedAt . ' UTC') : false;
        $freshMatch = $matched !== false && $now - $matched < self::FRESH_MATCH_SECONDS;
        $window = $freshMatch ? self::LATE_WINDOW_FRESH_MATCH_SECONDS : self::LATE_WINDOW_SECONDS;

        $late = $meeting - $now < $window;

        return round($late ? $amount * (1 + self::LATE_CANCEL_RATE) : $amount, 2);
    }

    /** @return bool whether a booking was created */
    private function createFree(array $selection): bool
    {
        if ($this->openFor((int) $selection['guest_id'], (int) $selection['guide_id'])) {
            return false;
        }

        return (bool) $this->db->db_Insert('bookings', [
            'selection_id' => (int) $selection['id'],
            'guest_id' => (int) $selection['guest_id'],
            'guide_id' => (int) $selection['guide_id'],
            'amount' => '0.00',
            'status' => 'confirmed',
            'payment_status' => 'none',
        ]);
    }

    private function close(array $booking, ?float $refund, string $reason): void
    {
        $fields = [
            'status' => 'cancelled',
            'cancel_reason' => $reason,
            'cancelled_at' => gmdate('Y-m-d H:i:s'),
        ];
        if ($refund !== null) {
            $fields['payment_status'] = 'refunded';
            $fields['refund_amount'] = number_format($refund, 2, '.', '');
        }

        $this->db->db_Update('bookings', $fields, ['`id` = ' . (int) $booking['id']]);
    }

    private function activeSelection(int $guestId, int $guideId): array
    {
        $row = $this->db->db_GetRow(
            'SELECT * FROM `selections` WHERE `guest_id` = ' . $guestId . ' AND `guide_id` = ' . $guideId . ' AND `active` = 1'
        );
        if (!$row) {
            throw new RuntimeException('You can only book a guide you have matched with.');
        }

        return $row;
    }

    private function find(int $id): array
    {
        return self::cast($this->db->db_GetRowById($id, 'bookings'));
    }

    private function user(int $id): array
    {
        return $this->db->db_GetRowById($id, 'users', '`id`, `name`, `email`, `phone`') ?: ['id' => $id];
    }

    private static function parseMeeting(string $value): int
    {
        $time = $value !== '' ? strtotime($value) : false;
        if ($time === false) {
            throw new RuntimeException('Choose a date and time for the meeting.');
        }
        if ($time <= time()) {
            throw new RuntimeException('The meeting has to be in the future.');
        }
        if ($time - time() > self::MAX_BOOKING_AHEAD_SECONDS) {
            throw new RuntimeException('Bookings can be made up to a year ahead.');
        }

        return $time;
    }

    private static function cast(array $row): array
    {
        foreach (['id', 'selection_id', 'guest_id', 'guide_id'] as $key) {
            $row[$key] = (int) $row[$key];
        }
        $row['amount'] = (float) $row['amount'];
        $row['refund_amount'] = $row['refund_amount'] === null ? null : (float) $row['refund_amount'];
        unset($row['open_pair']);

        return $row;
    }

    private static function nameOf(array $user, string $fallback): string
    {
        return trim((string) ($user['name'] ?? '')) ?: $fallback;
    }

    private static function contactBlock(array $user): string
    {
        return "Email: " . ($user['email'] ?? '—') . "\n"
            . (!empty($user['phone']) ? "Phone: " . $user['phone'] . "\n" : '');
    }

    private static function money(float $amount): string
    {
        return '£' . number_format($amount, 2);
    }

    private static function formatMeeting(?string $utc): string
    {
        return $utc ? gmdate('D j M Y, H:i', strtotime($utc . ' UTC')) . ' UTC' : 'a date to be arranged';
    }
}
