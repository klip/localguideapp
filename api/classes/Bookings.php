<?php

/**
 * Data access and rules for `bookings` (see `012_bookings.sql` and
 * `014_booking_lifecycle.sql`): what a match becomes once the guest wants to
 * meet the guide.
 *
 *   - A guide with a fee (`user_profiles.price_amount` > 0): the guest pays it
 *     through `pay()`, picking the meeting time and how long the trip lasts.
 *     The booking is `pending` until the guide `accept()`s it — that's the
 *     guide committing to the date, so they can't simply walk away at the
 *     last minute — or `decline()`s it (full refund). Nothing is actually
 *     charged: like `Payments`, the row is simply recorded as paid.
 *   - A free guide (price 0 or unset): `createFree()` opens a `confirmed`
 *     booking the moment the match forms — no payment, no acceptance step.
 *
 * Contact details are never shared, booked or not: the pair plans the trip
 * in `Messages` (chat with location sharing).
 *
 * Getting out of a confirmed booking:
 *   - the guide cancels (`cancelByGuide()`): full refund, plus 5% when late;
 *   - the guest asks (`requestCancellation()`, with a reason) and the guide
 *     approves: refund of the fee minus 5%. The guide can refuse instead.
 *
 * Finishing a trip: automatic once `meeting_at + duration_hours` has passed
 * (`sweep()`); a booking missing either needs one side to ask and the other to
 * confirm (`requestFinish()`/`confirmFinish()`).
 *
 * A booking that was ever confirmed is a *trip* — `tripsFor()` lists them
 * for `TripsPage.vue`, split into scheduled, current and past (finished or
 * cancelled). Cancelling a confirmed trip, by either side, dissolves the
 * match on both sides, so each reappears in the other's discovery deck.
 *
 * All date-times are UTC.
 */
class Bookings
{
    /** Added to the refund when the guide cancels late — see `refundAmount()`. */
    public const LATE_CANCEL_RATE = 0.05;
    /** Kept back from the refund when the guest asks to cancel. */
    public const GUEST_CANCEL_FEE_RATE = 0.05;
    /** Cancelling closer than this to the meeting counts as late... */
    private const LATE_WINDOW_SECONDS = 24 * 3600;
    /** ...or closer than this, when the match itself is less than a day old. */
    private const LATE_WINDOW_FRESH_MATCH_SECONDS = 2 * 3600;
    private const FRESH_MATCH_SECONDS = 24 * 3600;
    private const MAX_BOOKING_AHEAD_SECONDS = 366 * 24 * 3600;
    private const MIN_DURATION_HOURS = 0.5;
    private const MAX_DURATION_HOURS = 24;
    private const MAX_LOCATION_LENGTH = 160;
    /** An unanswered request is cancelled at its meeting time; both sides are warned this long before. */
    private const EXPIRY_WARNING_SECONDS = 15 * 60;
    /** The guide is reminded of an unanswered request this long before it would expire... */
    private const ANSWER_REMINDER_SECONDS = 24 * 3600;
    /** ...once it has waited at least this long (a fresh request needs no reminder). */
    private const ANSWER_REMINDER_MIN_WAIT_SECONDS = 3 * 3600;
    /** Trip reminders go to both sides this long before the meeting... */
    private const TRIP_REMINDER_EARLY_SECONDS = 24 * 3600;
    /** ...and again this long before. */
    private const TRIP_REMINDER_LATE_SECONDS = 2 * 3600;
    /** "Start the trip" opens this long before the meeting time. */
    public const START_OPENS_SECONDS = 30 * 60;
    /** With location on, the two must be this close to start the trip. */
    public const START_RADIUS_M = 20;

    /** @var db */
    private $db;
    /** @var Notifications */
    private $notifications;
    /** @var Levels|null */
    private $levels;

    public function __construct(db $db, Notifications $notifications, ?Levels $levels = null)
    {
        $this->db = $db;
        $this->notifications = $notifications;
        $this->levels = $levels;
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

    /** The pair's open booking — pending, or confirmed with the trip not finished — if any. */
    public function openFor(int $guestId, int $guideId): ?array
    {
        $row = $this->db->db_GetRow(
            'SELECT * FROM `bookings`
             WHERE `guest_id` = ' . $guestId . ' AND `guide_id` = ' . $guideId . '
               AND `status` IN ("pending", "confirmed") AND `trip_status` <> "finished"'
        );

        return $row ? self::cast($row) : null;
    }

    // ---------------------------------------------------------------- free

    /**
     * Called by `Selections` when a pair has just become an active match.
     * A free guide's booking opens here (and the guide hears about it, as
     * before); a paid one waits for `pay()`. The side that *didn't* make the
     * completing decision is told about the match — the one who did is
     * looking at it already.
     */
    public function onMatched(array $selection, bool $guestActed): void
    {
        $guest = $this->user((int) $selection['guest_id']);
        $guide = $this->user((int) $selection['guide_id']);
        $guestName = self::nameOf($guest, 'A visitor');
        $guideName = self::nameOf($guide, 'Your guide');
        $fee = $this->guideFee((int) $selection['guide_id']);

        if ($fee > 0) {
            if ($guestActed) {
                $this->notifications->notify(
                    (int) $guide['id'],
                    'match',
                    "You matched with {$guestName}. Say hello in Messages — once they pay your " . self::money($fee) . ' fee you can confirm a date on Matches.',
                    "New match: {$guestName}",
                    self::greeting($guide)
                    . "{$guestName} is interested too, so you're a match. You can chat in Messages on BeLikeLocal straight away. "
                    . 'They can pay your ' . self::money($fee) . " fee to request a date; you'll hear from us when they do.\n\nBeLikeLocal",
                    (int) $guest['id'],
                    role: 'guide'
                );
            } else {
                $this->notifications->notify(
                    (int) $guest['id'],
                    'match',
                    "{$guideName} liked you back — it's a match! Say hello in Messages, or pay their " . self::money($fee) . ' fee on Matches to request a date.',
                    "It's a match: {$guideName}",
                    self::greeting($guest)
                    . "{$guideName} is interested too, so you're a match. You can chat in Messages on BeLikeLocal straight away, "
                    . 'and pay their ' . self::money($fee) . " fee on the Matches page to request a date.\n\nBeLikeLocal",
                    (int) $guide['id'],
                    role: 'guest'
                );
            }
            return;
        }

        if (!$this->createFree($selection)) {
            return;
        }

        $this->notifications->notify(
            (int) $guide['id'],
            'booking_free',
            "You matched with {$guestName}. Your trip is on My trips — plan it together in Messages.",
            "New match: {$guestName}",
            self::greeting($guide)
            . "You and {$guestName} are a match. You don't charge a fee, so the trip is already on your My trips page.\n\n"
            . "Plan it together in Messages on BeLikeLocal — you can share your location there too. When it's over, mark the trip finished on My trips.\n\nBeLikeLocal",
            role: 'guide'
        );

        if (!$guestActed) {
            $this->notifications->notify(
                (int) $guest['id'],
                'booking_free',
                "{$guideName} liked you back — it's a match! Your trip is on My trips — plan it together in Messages.",
                "It's a match: {$guideName}",
                self::greeting($guest)
                . "You and {$guideName} are a match. They don't charge a fee, so the trip is already on your My trips page.\n\n"
                . "Plan it together in Messages on BeLikeLocal — you can share your location there too. When it's over, mark the trip finished on My trips.\n\nBeLikeLocal",
                role: 'guest'
            );
        }
    }

    /**
     * In-app only: tells everyone `$userId` is actively matched with that
     * they changed their profile, so a guide sees the visitor's new party
     * size without having to go looking.
     * Repeated saves collapse into one unread line (`notifyInApp()`).
     */
    public function onProfileUpdated(int $userId): void
    {
        $user = $this->user($userId);
        $name = self::nameOf($user, 'One of your matches');
        $rows = $this->db->db_GetArray(
            'SELECT IF(`guest_id` = ' . $userId . ', `guide_id`, `guest_id`) AS `other`,
                    IF(`guest_id` = ' . $userId . ', "guide", "guest") AS `other_role`
             FROM `selections`
             WHERE (`guest_id` = ' . $userId . ' OR `guide_id` = ' . $userId . ') AND `active` = 1'
        );
        foreach ($rows ?: [] as $row) {
            $this->notifications->notifyInApp((int) $row['other'], 'profile_updated', "{$name} updated their details.", $userId, $row['other_role']);
        }
    }

    /**
     * Opens free bookings for any of this user's active matches with a free
     * guide that don't have one yet — matches formed before bookings existed,
     * or a guide who dropped their fee to 0 after matching. Silent: no
     * notifications for these. A pair that has already had a trip together
     * doesn't get a new one this way.
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
                 SELECT 1 FROM `bookings` b WHERE b.`selection_id` = s.`id` AND b.`status` IN ("pending", "confirmed")
               )'
        );

        foreach ($pending as $selection) {
            $this->createFree($selection);
        }
    }

    // ---------------------------------------------------------------- paying

    /**
     * The guest pays the guide's fee for an active match. `$meetingAt` is an
     * ISO 8601 date-time (the client sends UTC) in the future; `$durationHours`
     * is optional — with it, the trip finishes on its own; so are `$location`,
     * a short description of where to meet, and `$latitude`/`$longitude`, a
     * map pin shared from the guest's device. The booking waits for the guide
     * to accept it. Returns the new booking.
     */
    public function pay(int $guestId, int $guideId, string $meetingAt, $durationHours = null, string $location = '', $latitude = null, $longitude = null): array
    {
        $selection = $this->activeSelection($guestId, $guideId);
        if (Freezes::activeFor($this->db, $guideId) !== null) {
            throw new RuntimeException('This guide isn’t available right now.');
        }

        $fee = $this->guideFee($guideId);
        if ($fee <= 0) {
            throw new RuntimeException('This guide doesn’t charge a fee — no payment is needed.');
        }
        if ($this->openFor($guestId, $guideId)) {
            throw new RuntimeException('You already have a booking with this guide.');
        }

        $meeting = self::parseMeeting($meetingAt);
        $duration = self::parseDuration($durationHours);
        $location = self::parseLocation($location);
        $pin = self::parsePin($latitude, $longitude);

        // A guide with a calendar (`Availability`) is booked only at a free
        // time — checked again here under the guide's lock, so two guests
        // paying for the same slot at once can't both get it. The lock is
        // held until the booking is written (it then holds the slot).
        $availability = new Availability($this->db);
        $calendar = $availability->calendarFor($guideId);
        $lock = null;
        if ($calendar) {
            $duration = $duration ?? $calendar['defaultTripHours'];
            $lock = 'guide_calendar_' . $guideId;
            if ((int) $this->db->db_GetFieldFromQuery('SELECT GET_LOCK("' . $lock . '", 5) AS `l`', 'l') !== 1) {
                throw new RuntimeException('That guide’s calendar is busy — try again in a moment.');
            }
            if (!$availability->isFree($guideId, $meeting, (float) $duration)) {
                $this->db->db_Execute('DO RELEASE_LOCK("' . $lock . '")');
                throw new RuntimeException('That time isn’t free any more — pick another.');
            }
        }
        try {
            return $this->recordPayment($selection, $guestId, $guideId, $fee, $meeting, $duration, $location, $pin);
        } finally {
            if ($lock !== null) {
                $this->db->db_Execute('DO RELEASE_LOCK("' . $lock . '")');
            }
        }
    }

    /** The payment row and the guide's notification — `pay()` after its checks. */
    private function recordPayment(array $selection, int $guestId, int $guideId, float $fee, int $meeting, ?float $duration, ?string $location, ?array $pin): array
    {
        $fields = [
            'selection_id' => (int) $selection['id'],
            'guest_id' => $guestId,
            'guide_id' => $guideId,
            'amount' => self::decimal($fee),
            'meeting_at' => gmdate('Y-m-d H:i:s', $meeting),
            'status' => 'pending',
            'payment_status' => 'paid',
        ];
        if ($duration !== null) {
            $fields['duration_hours'] = (string) $duration;
        }
        if ($location !== null) {
            $fields['location'] = $location;
        }
        if ($pin !== null) {
            [$fields['location_lat'], $fields['location_lng']] = $pin;
        }
        // The platform fee, fixed now: by the guide's level, or none during a
        // no-fee bonus (`Levels`). What the guide receives is the rest.
        if ($this->levels) {
            $rate = $this->levels->guideCommissionRate($guideId);
            $commission = round($fee * $rate, 2);
            $fields['commission_rate'] = number_format($rate, 4, '.', '');
            $fields['commission_amount'] = self::decimal($commission);
            $fields['guide_payout'] = self::decimal($fee - $commission);
        }

        $id = $this->db->db_Insert('bookings', $fields);
        if (!$id) {
            // The unique `open_pair` index is what trips on a double submit.
            throw new RuntimeException('Could not record the payment. Please try again.');
        }

        $booking = $this->find((int) $id);
        $guestName = self::nameOf($this->user($guestId), 'A visitor');
        $amount = self::money($fee);
        $when = self::formatMeeting($booking['meeting_at']);

        $this->notifications->notify(
            $guideId,
            'booking_requested',
            "{$guestName} paid your {$amount} fee for {$when}. Accept or decline it on your Matches page.",
            "{$guestName} wants to book you for {$when}",
            self::greeting($this->user($guideId))
            . "{$guestName} has paid your fee of {$amount} for a tour on {$when}"
            . ($duration !== null ? " ({$duration} hours)" : '') . ".\n"
            . (isset($fields['guide_payout']) ? 'You receive ' . self::money((float) $fields['guide_payout']) . ' after the BeLikeLocal fee of '
                . self::percent((float) $fields['commission_rate']) . ".\n" : '')
            . self::placeLines($location, $pin) . "\n"
            . "Accept it on your Matches page to confirm the date, and plan the details together in Messages. "
            . "If you can't make it, decline and they're refunded in full. If you don't answer before the meeting time, it's declined automatically.\n\nBeLikeLocal",
            role: 'guide'
        );

        return $booking;
    }

    /** The guide confirms a pending booking — it becomes a trip. */
    public function accept(int $guideId, int $guestId): array
    {
        $booking = $this->requireStatus($guestId, $guideId, 'pending', 'There is no booking waiting for you to accept.');

        $this->db->db_Update('bookings', [
            'status' => 'confirmed',
            'accepted_at' => gmdate('Y-m-d H:i:s'),
        ], ['`id` = ' . $booking['id']]);

        $guide = $this->user($guideId);
        $guideName = self::nameOf($guide, 'Your guide');
        $when = self::formatMeeting($booking['meeting_at']);

        $this->notifications->notify(
            $guestId,
            'booking_accepted',
            "{$guideName} confirmed your booking for {$when}. It's on My trips — plan the details together in Messages.",
            "{$guideName} confirmed your booking",
            self::greeting($this->user($guestId))
            . "{$guideName} has confirmed your tour on {$when}. It's on your My trips page.\n\n"
            . "Plan the details together in Messages on BeLikeLocal — you can share your location there too.\n\nBeLikeLocal",
            role: 'guest'
        );

        return $this->find($booking['id']);
    }

    /** The guide turns down a pending booking — the guest is refunded in full; the match stays. */
    public function decline(int $guideId, int $guestId, string $reason = ''): array
    {
        $booking = $this->requireStatus($guestId, $guideId, 'pending', 'There is no booking waiting for your answer.');
        $reason = self::reason($reason);

        $this->close($booking, 'declined', $booking['amount'], $reason);

        $guideName = self::nameOf($this->user($guideId), 'Your guide');
        $refund = self::money($booking['amount']);
        $this->notifications->notify(
            $guestId,
            'booking_declined',
            "{$guideName} can’t make " . self::formatMeeting($booking['meeting_at']) . ". You have been refunded {$refund}. You can suggest another time.",
            "{$guideName} can't make that date",
            self::greeting($this->user($guestId))
            . "{$guideName} can't do your tour on " . self::formatMeeting($booking['meeting_at']) . ".\n"
            . ($reason !== '' ? "Their reason: {$reason}\n" : '')
            . "\nYour payment of {$refund} has been refunded in full. You're still matched, so you can book another time from your Matches page.\n\nBeLikeLocal",
            role: 'guest'
        );

        return $this->find($booking['id']);
    }

    /** The guest takes back a payment the guide hasn't accepted yet — full refund. */
    public function withdraw(int $guestId, int $guideId): array
    {
        $booking = $this->requireStatus($guestId, $guideId, 'pending', 'There is no unconfirmed booking to withdraw.');

        $this->close($booking, 'withdrawn', $booking['amount'], 'Withdrawn by the visitor');

        $guestName = self::nameOf($this->user($guestId), 'The visitor');
        $this->notifications->notify(
            $guideId,
            'booking_withdrawn',
            "{$guestName} withdrew their booking request for " . self::formatMeeting($booking['meeting_at']) . '.',
            "{$guestName} withdrew their booking request",
            self::greeting($this->user($guideId))
            . "{$guestName} withdrew their booking request for " . self::formatMeeting($booking['meeting_at']) . ". Nothing more to do.\n\nBeLikeLocal",
            role: 'guide'
        );

        return $this->find($booking['id']);
    }

    /**
     * Either side sets where an open booking's trip meets — a description,
     * a map pin from their device (`$latitude`/`$longitude`), or both — or
     * clears it (neither). Each call replaces both. The other side is told.
     */
    public function setLocation(int $actingUserId, int $guestId, int $guideId, string $location, $latitude = null, $longitude = null): array
    {
        $booking = $this->openFor($guestId, $guideId);
        if (!$booking) {
            throw new RuntimeException('There is no upcoming trip to set a meeting place for.');
        }
        $location = self::parseLocation($location);
        $pin = self::parsePin($latitude, $longitude);

        $this->db->db_Execute(
            'UPDATE `bookings` SET `location` = '
            . ($location === null ? 'NULL' : '"' . $this->db->db_Escape($location) . '"')
            . ', `location_lat` = ' . ($pin === null ? 'NULL' : $pin[0])
            . ', `location_lng` = ' . ($pin === null ? 'NULL' : $pin[1])
            . ' WHERE `id` = ' . $booking['id']
        );

        $otherId = $actingUserId === $guestId ? $guideId : $guestId;
        $actorName = self::nameOf($this->user($actingUserId), 'Your match');
        $when = self::formatMeeting($booking['meeting_at']);
        if ($location === null && $pin === null) {
            $message = "{$actorName} removed the meeting place for your trip on {$when}.";
        } elseif ($location === null) {
            $message = "{$actorName} pinned the meeting place for your trip on {$when} on the map.";
        } else {
            $message = "{$actorName} set the meeting place for your trip on {$when}: {$location}" . ($pin !== null ? ' (pinned on the map)' : '');
        }
        // A request still waiting on the guide lives on Matches, a confirmed
        // trip on My trips — the kind tells the toast where to link.
        $this->notifications->notify(
            $otherId,
            $booking['status'] === 'pending' ? 'request_location' : 'trip_location',
            $message,
            "{$actorName} updated your trip's meeting place",
            self::greeting($this->user($otherId)) . $message . "\n"
            . ($pin !== null ? 'Map: ' . self::pinUrl($pin) . "\n" : '') . "\nBeLikeLocal",
            $actingUserId,
            role: ($otherId === $guestId ? 'guest' : 'guide')
        );

        return $this->find($booking['id']);
    }

    // ---------------------------------------------------------------- cancelling

    /**
     * The guide cancels the pair's confirmed booking: the fee is refunded
     * (plus the late-cancellation extra where it applies), the match is
     * dissolved on both sides — which also hands the guest their pick back —
     * and the guest is notified. A pending booking is declined instead.
     */
    public function cancelByGuide(int $guideId, int $guestId, string $reason = ''): array
    {
        $booking = $this->openFor($guestId, $guideId);
        if (!$booking) {
            throw new RuntimeException('There is no booking to cancel.');
        }
        if ($booking['status'] === 'pending') {
            return $this->decline($guideId, $guestId, $reason);
        }

        $reason = self::reason($reason);
        $selection = $this->db->db_GetRowById($booking['selection_id'], 'selections');
        $refund = $booking['payment_status'] === 'paid'
            ? $this->refundAmount($booking, $selection ? $selection['matched_at'] : null)
            : null;

        $this->close($booking, 'cancelled', $refund, $reason);
        $this->dissolveMatch($booking);

        $guideName = self::nameOf($this->user($guideId), 'Your guide');
        $refundLine = $refund !== null
            ? 'Your payment has been refunded: ' . self::money($refund)
                . ($refund > $booking['amount'] ? ' (your ' . self::money($booking['amount']) . ' fee plus '
                . self::percent(self::LATE_CANCEL_RATE) . ' for the late cancellation)' : '') . ".\n"
            : '';

        $this->notifications->notify(
            $guestId,
            'booking_cancelled',
            "{$guideName} cancelled your booking."
                . ($refund !== null ? ' You have been refunded ' . self::money($refund) . '.' : '')
                . ' Your pick is back.',
            "{$guideName} cancelled your booking",
            self::greeting($this->user($guestId))
            . "{$guideName} has cancelled your booking"
            . ($booking['meeting_at'] ? ' for ' . self::formatMeeting($booking['meeting_at']) : '') . ".\n"
            . ($reason !== '' ? "Their reason: {$reason}\n" : '')
            . "\n" . $refundLine
            . "The selection you used on them is back in your pack, so you can pick another guide.\n\nBeLikeLocal",
            role: 'guest'
        );

        return $this->find($booking['id']);
    }

    /**
     * Staff cancel an open booking (pending, or confirmed and unfinished) —
     * when freezing one side of it (the CMS, `UserFreezes`). A paid booking
     * is refunded `$refund` (null = the whole fee; at most the fee). The
     * match dissolves, as with every cancellation, and both sides are told.
     */
    public function cancelByStaff(int $bookingId, ?float $refund, string $note = ''): array
    {
        $booking = $this->db->db_GetRowById($bookingId, 'bookings');
        if (!$booking || !($booking['status'] === 'pending'
            || ($booking['status'] === 'confirmed' && $booking['trip_status'] !== 'finished'))) {
            throw new RuntimeException('That booking isn’t open any more.');
        }
        $paid = $booking['payment_status'] === 'paid';
        $amount = (float) $booking['amount'];
        if ($refund !== null && ($refund < 0 || $refund > $amount + 0.001)) {
            throw new RuntimeException('A refund from 0 to the fee, ' . self::money($amount) . '.');
        }
        $refund = $paid ? ($refund ?? $amount) : null;
        $note = self::reason($note);

        $this->close($booking, 'cancelled', $refund, $note !== '' ? $note : 'Cancelled by BeLikeLocal');
        $this->dissolveMatch($booking);

        $guestId = (int) $booking['guest_id'];
        $guideId = (int) $booking['guide_id'];
        $when = $booking['meeting_at'] ? ' for ' . self::formatMeeting($booking['meeting_at']) : '';
        foreach ([[$guestId, $guideId, 'guest'], [$guideId, $guestId, 'guide']] as [$to, $other, $role]) {
            $otherName = self::nameOf($this->user($other), $role === 'guest' ? 'your guide' : 'your visitor');
            $money = $refund !== null
                ? ($role === 'guest' ? ' You have been refunded ' . self::money($refund) . '.' : ' The fee has been refunded to them.')
                : '';
            $this->notifications->notify(
                $to,
                'booking_cancelled',
                "BeLikeLocal cancelled your booking with {$otherName}.{$money}",
                "Your booking with {$otherName} was cancelled",
                self::greeting($this->user($to))
                . "BeLikeLocal has cancelled your booking with {$otherName}{$when}.
"
                . ($note !== '' ? "Note from our team: {$note}
" : '')
                . ($money !== '' ? "
" . trim($money) . "
" : '')
                . ($role === 'guest' ? "The selection you used is back in your pack.
" : '')
                . "
If you have questions, reply to this email.

BeLikeLocal",
                role: $role,
                aboutUserId: $other
            );
        }

        return $this->find($bookingId);
    }

    /**
     * The guest asks to cancel a confirmed, paid booking before the trip
     * starts. A reason is required; the guide decides.
     */
    public function requestCancellation(int $guestId, int $guideId, string $reason): array
    {
        $booking = $this->requireStatus($guestId, $guideId, 'confirmed', 'There is no confirmed booking to cancel.');
        if ($booking['payment_status'] !== 'paid') {
            throw new RuntimeException('This booking is free — just unmatch to cancel it.');
        }
        if ($booking['trip_status'] !== 'pending') {
            throw new RuntimeException('The trip has already started.');
        }
        if ($booking['meeting_at'] !== null && strtotime($booking['meeting_at'] . ' UTC') <= time()) {
            throw new RuntimeException('The meeting time has passed.');
        }
        if ($booking['cancel_requested_at']) {
            throw new RuntimeException('You have already asked to cancel this booking.');
        }
        $reason = self::reason($reason);
        if ($reason === '') {
            throw new RuntimeException('Tell the guide why you need to cancel.');
        }

        $this->db->db_Update('bookings', [
            'cancel_requested_at' => gmdate('Y-m-d H:i:s'),
            'cancel_request_reason' => $reason,
        ], ['`id` = ' . $booking['id']]);

        $guestName = self::nameOf($this->user($guestId), 'The visitor');
        $refund = self::money($this->guestCancelRefund($booking));
        $this->notifications->notify(
            $guideId,
            'cancel_requested',
            "{$guestName} asked to cancel " . self::formatMeeting($booking['meeting_at']) . ". Approve or refuse it on your My trips page.",
            "{$guestName} asked to cancel their booking",
            self::greeting($this->user($guideId))
            . "{$guestName} has asked to cancel their tour on " . self::formatMeeting($booking['meeting_at']) . ".\n"
            . "Their reason: {$reason}\n\n"
            . "If you approve, they're refunded {$refund} (the fee minus " . self::percent(self::GUEST_CANCEL_FEE_RATE) . "). "
            . "If you refuse, the booking stands. Answer on your My trips page.\n\nBeLikeLocal",
            role: 'guide'
        );

        return $this->find($booking['id']);
    }

    /** The guest takes back their own cancellation request. */
    public function withdrawCancellationRequest(int $guestId, int $guideId): array
    {
        $booking = $this->requireCancelRequest($guestId, $guideId);
        $this->clearCancelRequest($booking);

        $guestName = self::nameOf($this->user($guestId), 'The visitor');
        $this->notifications->notify(
            $guideId,
            'cancel_request_withdrawn',
            "{$guestName} no longer wants to cancel — the booking for " . self::formatMeeting($booking['meeting_at']) . ' stands.',
            "{$guestName} is keeping their booking",
            self::greeting($this->user($guideId))
            . "{$guestName} withdrew their cancellation request. Your tour on " . self::formatMeeting($booking['meeting_at']) . " goes ahead.\n\nBeLikeLocal",
            role: 'guide'
        );

        return $this->find($booking['id']);
    }

    /**
     * The guide agrees to the guest's cancellation request: the booking is
     * cancelled, the guest refunded the fee minus 5%, and the match dissolved
     * (which returns the guest's pick).
     */
    public function approveCancellation(int $guideId, int $guestId): array
    {
        $booking = $this->requireCancelRequest($guestId, $guideId);
        $refund = $this->guestCancelRefund($booking);

        $this->close($booking, 'cancelled', $refund, 'Cancelled at the visitor’s request: ' . $booking['cancel_request_reason']);
        $this->dissolveMatch($booking);

        $guideName = self::nameOf($this->user($guideId), 'Your guide');
        $this->notifications->notify(
            $guestId,
            'cancel_approved',
            "{$guideName} approved your cancellation. You have been refunded " . self::money($refund) . '. Your pick is back.',
            "{$guideName} approved your cancellation",
            self::greeting($this->user($guestId))
            . "{$guideName} has approved your request to cancel the tour on " . self::formatMeeting($booking['meeting_at']) . ".\n\n"
            . 'You have been refunded ' . self::money($refund) . ' — your ' . self::money($booking['amount'])
            . ' fee minus ' . self::percent(self::GUEST_CANCEL_FEE_RATE) . ".\n"
            . "The selection you used on them is back in your pack.\n\nBeLikeLocal",
            role: 'guest'
        );

        return $this->find($booking['id']);
    }

    /** The guide refuses the guest's cancellation request — the booking stands. */
    public function refuseCancellation(int $guideId, int $guestId, string $reason = ''): array
    {
        $booking = $this->requireCancelRequest($guestId, $guideId);
        $reason = self::reason($reason);
        $this->clearCancelRequest($booking);

        $guideName = self::nameOf($this->user($guideId), 'Your guide');
        $this->notifications->notify(
            $guestId,
            'cancel_refused',
            "{$guideName} didn’t agree to cancel — your booking for " . self::formatMeeting($booking['meeting_at']) . ' stands.',
            "{$guideName} didn't agree to cancel",
            self::greeting($this->user($guestId))
            . "{$guideName} didn't agree to cancel your tour on " . self::formatMeeting($booking['meeting_at']) . ", so the booking stands.\n"
            . ($reason !== '' ? "Their note: {$reason}\n" : '')
            . "\nBeLikeLocal",
            role: 'guest'
        );

        return $this->find($booking['id']);
    }

    /**
     * The guest walks away from a *free* booking (they unmatched). A paid one
     * can't be dropped this way — see `Selections::revoke()`. Like any other
     * cancelled trip, the match is dissolved on both sides; the guide is told.
     */
    public function cancelFreeByGuest(int $guestId, int $guideId): void
    {
        $booking = $this->openFor($guestId, $guideId);
        if (!$booking || $booking['payment_status'] === 'paid') {
            return;
        }

        $this->close($booking, 'cancelled', null, 'Cancelled by the visitor');
        $this->dissolveMatch($booking);

        $guestName = self::nameOf($this->user($guestId), 'A visitor');
        $this->notifications->notify(
            $guideId,
            'booking_cancelled',
            "{$guestName} unmatched and cancelled your booking.",
            "{$guestName} cancelled",
            self::greeting($this->user($guideId))
            . "{$guestName} has unmatched, so your booking with them is cancelled.\n\nBeLikeLocal",
            role: 'guide'
        );
    }

    // ---------------------------------------------------------------- finishing

    /**
     * Either side asks to mark the trip finished — only for a booking that
     * can't finish on its own (no meeting time or no duration). If the other
     * side had already asked, this confirms it instead.
     */
    public function requestFinish(int $actingUserId, int $guestId, int $guideId): array
    {
        $booking = $this->requireStatus($guestId, $guideId, 'confirmed', 'There is no confirmed trip to finish.');
        if ($booking['trip_status'] !== 'started') {
            throw new RuntimeException('Start the trip first — you both confirm the start when you meet.');
        }
        if (self::finishesAutomatically($booking)) {
            throw new RuntimeException('This trip finishes on its own at ' . self::formatMeeting(self::endOf($booking)) . '.');
        }

        $requester = $booking['finish_requested_by'];
        if ($requester !== null && $requester !== $actingUserId) {
            return $this->confirmFinish($actingUserId, $guestId, $guideId);
        }
        if ($requester === $actingUserId) {
            throw new RuntimeException('You have already asked — waiting for the other side to confirm.');
        }

        $this->db->db_Update('bookings', [
            'finish_requested_by' => $actingUserId,
            'finish_requested_at' => gmdate('Y-m-d H:i:s'),
        ], ['`id` = ' . $booking['id']]);

        $otherId = $actingUserId === $guestId ? $guideId : $guestId;
        $actorName = self::nameOf($this->user($actingUserId), 'Your match');
        $this->notifications->notify(
            $otherId,
            'finish_requested',
            "{$actorName} marked your trip as finished. Confirm it on your My trips page.",
            "{$actorName} marked your trip as finished",
            self::greeting($this->user($otherId))
            . "{$actorName} says your trip together is over. Confirm it on your My trips page — then you can both leave a review.\n\nBeLikeLocal",
            role: ($otherId === $guestId ? 'guest' : 'guide')
        );

        return $this->find($booking['id']);
    }

    /** The other side confirms the finish request — the trip is finished; reviews open up. */
    public function confirmFinish(int $actingUserId, int $guestId, int $guideId): array
    {
        $booking = $this->requireStatus($guestId, $guideId, 'confirmed', 'There is no confirmed trip to finish.');
        if ($booking['finish_requested_by'] === null || $booking['finish_requested_by'] === $actingUserId) {
            throw new RuntimeException('There is no finish request from the other side to confirm.');
        }

        $this->markFinished($booking);
        $this->notifyFinished($booking);

        return $this->find($booking['id']);
    }

    /**
     * Clears a finish request: the requester changing their mind, or the other
     * side saying "not yet". The requester is told in the second case.
     */
    public function dismissFinish(int $actingUserId, int $guestId, int $guideId): array
    {
        $booking = $this->requireStatus($guestId, $guideId, 'confirmed', 'There is no confirmed trip.');
        $requester = $booking['finish_requested_by'];
        if ($requester === null) {
            throw new RuntimeException('Nobody has asked to finish this trip.');
        }

        $this->db->db_Execute(
            'UPDATE `bookings` SET `finish_requested_by` = NULL, `finish_requested_at` = NULL WHERE `id` = ' . $booking['id']
        );

        if ($requester !== $actingUserId) {
            $actorName = self::nameOf($this->user($actingUserId), 'Your match');
            $this->notifications->notify(
                $requester,
                'finish_refused',
                "{$actorName} says your trip isn’t over yet.",
                "{$actorName} says your trip isn't over yet",
                self::greeting($this->user($requester))
                . "{$actorName} didn't confirm that your trip is finished. Ask again from your My trips page once it is.\n\nBeLikeLocal",
                role: ((int) $requester === $guestId ? 'guest' : 'guide')
            );
        }

        return $this->find($booking['id']);
    }

    /**
     * Moves bookings along with the clock. Run every 30 s by the scheduler
     * (`bin/scheduler.php`, one pass at a time across replicas), not by
     * requests:
     *
     *   - a guide is reminded of a paid request still unanswered a day before
     *     it would expire (once it has waited a few hours);
     *   - 15 minutes before the meeting time, both sides are warned that an
     *     unanswered request is about to be cancelled;
     *   - at the meeting time it is declined, and the guest refunded the fee
     *     minus the platform fee;
     *   - both sides of a confirmed trip are reminded a day and two hours
     *     before it;
     *   - a started trip with a length is `finished` once that length has
     *     passed since it started.
     *
     * A trip doesn't start by the clock any more: both people confirm the
     * start when they meet (`confirmStart()`).
     */
    public function sweep(): void
    {
        $now = time();
        $this->remindUnanswered($now);
        $this->warnExpiring($now);
        $this->expireUnanswered($now);
        $this->remindTrips($now);

        foreach ($this->db->db_GetArray(
            'SELECT * FROM `bookings`
             WHERE `status` = "confirmed" AND `trip_status` = "started"
               AND `started_at` IS NOT NULL AND `duration_hours` IS NOT NULL
               AND `started_at` + INTERVAL ROUND(`duration_hours` * 60) MINUTE <= "' . gmdate('Y-m-d H:i:s', $now) . '"
             LIMIT 50'
        ) as $row) {
            $booking = self::cast($row);
            $this->markFinished($booking);
            $this->notifyFinished($booking);
        }
    }

    /** Pending bookings whose meeting time falls in (`$from`, `$to`], with `$stamp` not yet set. */
    private function pendingBetween(int $from, int $to, string $stamp, string $extra = ''): array
    {
        return $this->db->db_GetArray(
            'SELECT * FROM `bookings`
             WHERE `status` = "pending" AND `' . $stamp . '` IS NULL
               AND `meeting_at` > "' . gmdate('Y-m-d H:i:s', $from) . '"
               AND `meeting_at` <= "' . gmdate('Y-m-d H:i:s', $to) . '"' . $extra . '
             LIMIT 50'
        );
    }

    /** Sets a reminder stamp; false if another pass already did. */
    private function stamp(int $bookingId, string $column, int $now): bool
    {
        $this->db->db_Execute(
            'UPDATE `bookings` SET `' . $column . '` = "' . gmdate('Y-m-d H:i:s', $now) . '"
             WHERE `id` = ' . $bookingId . ' AND `' . $column . '` IS NULL'
        );

        return $this->db->db_GetAffectedRows() > 0;
    }

    private function remindUnanswered(int $now): void
    {
        $waited = gmdate('Y-m-d H:i:s', $now - self::ANSWER_REMINDER_MIN_WAIT_SECONDS);
        foreach ($this->pendingBetween(
            $now + self::EXPIRY_WARNING_SECONDS,
            $now + self::ANSWER_REMINDER_SECONDS,
            'answer_reminded_at',
            ' AND `created_at` <= "' . $waited . '"'
        ) as $row) {
            $booking = self::cast($row);
            if (!$this->stamp($booking['id'], 'answer_reminded_at', $now)) {
                continue;
            }
            $guestName = self::nameOf($this->user($booking['guest_id']), 'A visitor');
            $when = self::formatMeeting($booking['meeting_at']);
            $this->notifications->notify(
                $booking['guide_id'],
                'request_reminder',
                "{$guestName} is still waiting for your answer about {$when}. Accept or decline it on Matches — an unanswered request is cancelled at the meeting time.",
                "{$guestName} is waiting for your answer",
                self::greeting($this->user($booking['guide_id']))
                . "{$guestName} has paid for a tour on {$when} and is still waiting for your answer.\n\n"
                . "Accept or decline it on your Matches page. If nobody answers, the request is cancelled at the meeting time.\n\nBeLikeLocal",
                $booking['guest_id'],
                role: 'guide'
            );
        }
    }

    private function warnExpiring(int $now): void
    {
        foreach ($this->pendingBetween($now, $now + self::EXPIRY_WARNING_SECONDS, 'expiry_warned_at') as $row) {
            $booking = self::cast($row);
            if (!$this->stamp($booking['id'], 'expiry_warned_at', $now)) {
                continue;
            }
            $minutes = max(1, (int) ceil((strtotime($booking['meeting_at'] . ' UTC') - $now) / 60));
            $in = $minutes === 1 ? '1 minute' : "{$minutes} minutes";
            $when = self::formatMeeting($booking['meeting_at']);
            $guestName = self::nameOf($this->user($booking['guest_id']), 'The visitor');
            $guideName = self::nameOf($this->user($booking['guide_id']), 'Your guide');
            $refund = self::money($this->expiryRefund($row));

            $this->notifications->notify(
                $booking['guide_id'],
                'request_expiring',
                "{$guestName}’s booking for {$when} will be cancelled in {$in} unless you accept it.",
                "Accept {$guestName}'s booking within {$in}",
                self::greeting($this->user($booking['guide_id']))
                . "You haven't answered {$guestName}'s booking request for {$when}. "
                . "Unless you accept it in the next {$in}, it will be cancelled and the visitor refunded.\n\nBeLikeLocal",
                $booking['guest_id'],
                role: 'guide'
            );
            $this->notifications->notify(
                $booking['guest_id'],
                'request_expiring',
                "{$guideName} hasn’t confirmed your booking for {$when} yet. Unless they do within {$in}, it’s cancelled and you’re refunded {$refund}.",
                "{$guideName} hasn't confirmed your booking yet",
                self::greeting($this->user($booking['guest_id']))
                . "{$guideName} hasn't answered your booking request for {$when} yet. "
                . "If they don't accept it within {$in}, it will be cancelled and you'll be refunded {$refund} "
                . "(the fee minus the platform fee).\n\nBeLikeLocal",
                $booking['guide_id'],
                role: 'guest'
            );
        }
    }

    /** What the guest gets back when a request expires: the fee minus the booking's platform fee. */
    private function expiryRefund(array $row): float
    {
        return max(0.0, round((float) $row['amount'] - (float) ($row['commission_amount'] ?? 0), 2));
    }

    private function expireUnanswered(int $now): void
    {
        foreach ($this->db->db_GetArray(
            'SELECT * FROM `bookings` WHERE `status` = "pending" AND `meeting_at` <= "' . gmdate('Y-m-d H:i:s', $now) . '" LIMIT 50'
        ) as $row) {
            $booking = self::cast($row);
            $refundAmount = $this->expiryRefund($row);
            $kept = round($booking['amount'] - $refundAmount, 2);
            $this->close($booking, 'declined', $refundAmount, 'The guide did not confirm in time');

            $guideName = self::nameOf($this->user($booking['guide_id']), 'Your guide');
            $refund = self::money($refundAmount);
            $refundLine = $kept > 0
                ? "you've been refunded {$refund} (the fee minus the " . self::money($kept) . ' platform fee)'
                : "you've been refunded {$refund} in full";
            $this->notifications->notify(
                $booking['guest_id'],
                'booking_expired',
                "{$guideName} didn’t confirm your booking in time, so it’s cancelled. You’ve been refunded {$refund}.",
                "{$guideName} didn't confirm in time",
                self::greeting($this->user($booking['guest_id']))
                . "{$guideName} didn't confirm your tour for " . self::formatMeeting($booking['meeting_at'])
                . " before it was due, so it's been cancelled and {$refundLine}.\n\nBeLikeLocal",
                role: 'guest'
            );
            $this->notifications->notify(
                $booking['guide_id'],
                'booking_expired',
                'A booking request expired before you answered it; it was cancelled and the visitor refunded.',
                'A booking request expired',
                self::greeting($this->user($booking['guide_id']))
                . 'You didn\'t answer a booking request for ' . self::formatMeeting($booking['meeting_at'])
                . " in time, so it was cancelled and the visitor refunded.\n\nBeLikeLocal",
                role: 'guide'
            );
        }
    }

    /** A day and two hours before a confirmed trip, both sides hear about it — unless it was confirmed inside that window. */
    private function remindTrips(int $now): void
    {
        $windows = [
            ['reminded_24h_at', self::TRIP_REMINDER_EARLY_SECONDS, self::TRIP_REMINDER_LATE_SECONDS],
            ['reminded_2h_at', self::TRIP_REMINDER_LATE_SECONDS, 0],
        ];
        foreach ($windows as [$column, $before, $until]) {
            foreach ($this->db->db_GetArray(
                'SELECT * FROM `bookings`
                 WHERE `status` = "confirmed" AND `trip_status` = "pending" AND `' . $column . '` IS NULL
                   AND `meeting_at` > "' . gmdate('Y-m-d H:i:s', $now + $until) . '"
                   AND `meeting_at` <= "' . gmdate('Y-m-d H:i:s', $now + $before) . '"
                   AND `accepted_at` <= `meeting_at` - INTERVAL ' . $before . ' SECOND
                 LIMIT 50'
            ) as $row) {
                $booking = self::cast($row);
                if (!$this->stamp($booking['id'], $column, $now)) {
                    continue;
                }
                $soon = $column === 'reminded_2h_at';
                $when = self::formatMeeting($booking['meeting_at']);
                $pin = $booking['location_pin']
                    ? [(string) $booking['location_pin']['latitude'], (string) $booking['location_pin']['longitude']]
                    : null;
                $place = self::placeLines($booking['location'], $pin);
                foreach ([['guest', 'guide'], ['guide', 'guest']] as [$side, $otherSide]) {
                    $to = $booking[$side . '_id'];
                    $otherName = self::nameOf($this->user($booking[$otherSide . '_id']), 'your match');
                    $next = $soon
                        ? 'When you meet, you both press “Start the trip” on My trips.'
                        : 'Plan the details in Messages.';
                    $this->notifications->notify(
                        $to,
                        'trip_reminder',
                        ($soon ? "Your trip with {$otherName} starts soon — {$when}. " : "Reminder: your trip with {$otherName} is on {$when}. ") . $next,
                        $soon ? "Your trip with {$otherName} starts soon" : "Your trip with {$otherName} is coming up",
                        self::greeting($this->user($to))
                        . "Your trip with {$otherName} is on {$when}.\n"
                        . $place . "\n"
                        . ($soon
                            ? "When you meet, you both press \"Start the trip\" on your My trips page — with location on, the app checks you're together.\n\nBeLikeLocal"
                            : "Anything left to agree? Message {$otherName} in BeLikeLocal.\n\nBeLikeLocal"),
                        $booking[$otherSide . '_id'],
                        role: $side
                    );
                }
            }
        }
    }

    // ---------------------------------------------------------------- starting

    /**
     * One side confirms the trip has started — they've met. Open from
     * `START_OPENS_SECONDS` before the meeting time (any time for a trip with
     * no time). When both have confirmed, the trip is `started`.
     *
     * With a position (the device's location was readable) and the other side
     * having confirmed with one too, the two must be within `START_RADIUS_M`
     * of each other, or this confirmation is refused. Without one (location
     * off or unavailable) the button alone counts. Confirming again just
     * refreshes the position. Positions are never shown to anyone.
     */
    public function confirmStart(int $actingUserId, int $guestId, int $guideId, $latitude = null, $longitude = null): array
    {
        $open = $this->requireStatus($guestId, $guideId, 'confirmed', 'There is no confirmed trip to start.');
        $pin = self::parsePin($latitude, $longitude);

        $lock = 'booking_start_' . $open['id'];
        if ((int) $this->db->db_GetFieldFromQuery('SELECT GET_LOCK("' . $lock . '", 5) AS `l`', 'l') !== 1) {
            throw new RuntimeException('Try again in a moment.');
        }
        try {
            $raw = $this->db->db_GetRowById($open['id'], 'bookings');
            if ($raw['trip_status'] === 'started') {
                throw new RuntimeException('The trip has already started.');
            }
            if ($raw['trip_status'] === 'finished') {
                throw new RuntimeException('This trip is already finished.');
            }
            if ($raw['meeting_at'] !== null) {
                $opens = strtotime($raw['meeting_at'] . ' UTC') - self::START_OPENS_SECONDS;
                if (time() < $opens) {
                    throw new RuntimeException('You can start the trip from ' . self::formatMeeting(gmdate('Y-m-d H:i:s', $opens)) . '.');
                }
            }

            [$side, $otherSide] = $actingUserId === $guestId ? ['guest', 'guide'] : ['guide', 'guest'];
            $otherId = $otherSide === 'guest' ? $guestId : $guideId;
            $otherName = self::nameOf($this->user($otherId), 'your match');
            if ($pin !== null && $raw[$otherSide . '_start_lat'] !== null) {
                $apart = self::distanceM(
                    (float) $pin[0], (float) $pin[1],
                    (float) $raw[$otherSide . '_start_lat'], (float) $raw[$otherSide . '_start_lng']
                );
                if ($apart > self::START_RADIUS_M) {
                    throw new RuntimeException('You’re about ' . self::roughMetres($apart) . " from {$otherName} — meet up, then try again.");
                }
            }

            $already = $raw[$side . '_started_at'] !== null;
            $now = gmdate('Y-m-d H:i:s');
            $this->db->db_Execute(
                'UPDATE `bookings` SET `' . $side . '_started_at` = "' . $now . '",
                    `' . $side . '_start_lat` = ' . ($pin ? $pin[0] : 'NULL') . ',
                    `' . $side . '_start_lng` = ' . ($pin ? $pin[1] : 'NULL') . '
                 WHERE `id` = ' . (int) $raw['id']
            );

            if ($raw[$otherSide . '_started_at'] !== null) {
                $this->db->db_Execute(
                    'UPDATE `bookings` SET `trip_status` = "started", `started_at` = "' . $now . '" WHERE `id` = ' . (int) $raw['id']
                );
                foreach ([[$guestId, $guideId, 'guest'], [$guideId, $guestId, 'guide']] as [$to, $about, $role]) {
                    $name = self::nameOf($this->user($about), 'your match');
                    $this->notifications->notifyInApp($to, 'trip_started', "Your trip with {$name} has started — enjoy it!", $about, $role);
                }
            } elseif (!$already) {
                $actorName = self::nameOf($this->user($actingUserId), 'Your match');
                $this->notifications->notify(
                    $otherId,
                    'trip_start_confirmed',
                    "{$actorName} has confirmed the start of your trip. Confirm it on My trips when you’re together.",
                    "{$actorName} is ready to start your trip",
                    self::greeting($this->user($otherId))
                    . "{$actorName} has confirmed the start of your trip together. "
                    . "When you've met, press \"Start the trip\" on your My trips page too.\n\nBeLikeLocal",
                    $actingUserId,
                    role: $otherSide
                );
            }
        } finally {
            $this->db->db_Execute('DO RELEASE_LOCK("' . $lock . '")');
        }

        return $this->find((int) $open['id']);
    }

    /** Great-circle distance in metres. */
    public static function distanceM(float $lat1, float $lng1, float $lat2, float $lng2): float
    {
        $r = 6371000.0;
        $dLat = deg2rad($lat2 - $lat1);
        $dLng = deg2rad($lng2 - $lng1);
        $a = sin($dLat / 2) ** 2 + cos(deg2rad($lat1)) * cos(deg2rad($lat2)) * sin($dLng / 2) ** 2;

        return 2 * $r * asin(min(1.0, sqrt($a)));
    }

    private static function roughMetres(float $metres): string
    {
        return $metres >= 1000
            ? number_format($metres / 1000, 1) . ' km'
            : ((int) (round($metres / 5) * 5)) . ' m';
    }

    // ---------------------------------------------------------------- trips

    /**
     * Every trip `$userId` (a guest or guide, per `$role`) has had: bookings
     * that were confirmed at some point — `confirmed` ones, and `cancelled`
     * ones that had been accepted. Pending/declined/withdrawn requests aren't
     * trips; they stay on the Matches page.
     *
     * Each row is tagged with a `phase`:
     *   scheduled — confirmed, not started yet (a free trip with no meeting
     *               time stays here until it's marked finished)
     *   current   — confirmed, started
     *   past      — finished, or cancelled
     *
     * Rows use the same field names as `Selections::getOverview()` for the
     * booking (`booking_status`, `booking_amount`, …) so `BookingPanel.vue`
     * can drive either. No contact details — the pair chats in `Messages`.
     * `is_latest` marks the pair's most
     * recent booking — the only one that may offer "Book again".
     */
    public function tripsFor(int $userId, ?string $role): array
    {
        if ($role === 'guest') {
            [$own, $their] = ['guest', 'guide'];
        } elseif ($role === 'guide') {
            [$own, $their] = ['guide', 'guest'];
        } else {
            return [];
        }

        $this->ensureFreeBookingsFor($userId);

        $rows = $this->db->db_GetArray(
            'SELECT b.`id`, b.`selection_id`, b.`' . $their . '_id` AS `counterpart_id`,
                    b.`status` AS `booking_status`, b.`payment_status`, b.`amount` AS `booking_amount`,
                    b.`meeting_at`, b.`duration_hours` AS `booking_duration_hours`, b.`location`,
                    b.`location_lat`, b.`location_lng`,
                    b.`refund_amount`, b.`trip_status`, b.`accepted_at`,
                    b.`cancel_reason`, b.`cancelled_at`, b.`cancel_requested_at`, b.`cancel_request_reason`,
                    b.`finish_requested_by`, b.`finished_at`, b.`started_at`,
                    b.`guest_started_at`, b.`guide_started_at`,
                    s.`active`, s.`matched_at`,
                    u.`name`, u.`image`,
                    COALESCE(gp.`price_amount`, 0) AS `fee_amount`,
                    gb.`label` AS `guide_place`,
                    b.`id` = (SELECT MAX(bb.`id`) FROM `bookings` bb WHERE bb.`selection_id` = b.`selection_id`) AS `is_latest`,
                    ' . ($own === 'guide'
                        ? 'b.`commission_rate`, b.`guide_payout`'
                        : 'NULL AS `commission_rate`, NULL AS `guide_payout`') . '
             FROM `bookings` b
             JOIN `selections` s ON s.`id` = b.`selection_id`
             JOIN `users` u ON u.`id` = b.`' . $their . '_id`
             LEFT JOIN `user_profiles` gp ON gp.`user_id` = b.`guide_id`
             LEFT JOIN `guide_bases` gb ON gb.`user_id` = b.`guide_id`
             WHERE b.`' . $own . '_id` = ' . $userId . '
               AND b.`accepted_at` IS NOT NULL AND b.`status` IN ("confirmed", "cancelled")
             ORDER BY COALESCE(b.`meeting_at`, b.`accepted_at`) DESC, b.`id` DESC'
        );

        foreach ($rows as &$row) {
            foreach (['id', 'selection_id', 'counterpart_id', 'active'] as $key) {
                $row[$key] = (int) $row[$key];
            }
            $row['is_latest'] = (bool) $row['is_latest'];
            $row['fee_amount'] = (float) $row['fee_amount'];
            $row['booking_amount'] = (float) $row['booking_amount'];
            $row['refund_amount'] = $row['refund_amount'] === null ? null : (float) $row['refund_amount'];
            // The guide's platform fee and payout (null for the guest, and for bookings made before 041).
            $row['commission_rate'] = $row['commission_rate'] === null ? null : (float) $row['commission_rate'];
            $row['guide_payout'] = $row['guide_payout'] === null ? null : (float) $row['guide_payout'];
            $row['booking_duration_hours'] = $row['booking_duration_hours'] === null ? null : (float) $row['booking_duration_hours'];
            $row['finish_requested_by'] = $row['finish_requested_by'] === null
                ? null
                : ((int) $row['finish_requested_by'] === $userId ? 'me' : 'them');
            $row['auto_finish'] = self::finishesAutomatically(['trip_status' => $row['trip_status'], 'started_at' => $row['started_at'], 'duration_hours' => $row['booking_duration_hours']]);
            $row['start_confirmed_by'] = self::startConfirmedBy($row, $own);
            unset($row['guest_started_at'], $row['guide_started_at']);

            $open = $row['booking_status'] === 'confirmed' && $row['trip_status'] !== 'finished';
            $row['phase'] = !$open ? 'past' : ($row['trip_status'] === 'started' ? 'current' : 'scheduled');
            $row = self::withPin($row);

            $paidAndOpen = $open && $row['payment_status'] === 'paid';
            $row['refund_if_cancelled'] = $own === 'guide' && $paidAndOpen
                ? $this->refundAmount(['amount' => $row['booking_amount'], 'meeting_at' => $row['meeting_at']], $row['matched_at'])
                : null;
            $row['refund_if_guest_cancels'] = $paidAndOpen ? $this->guestCancelRefund(['amount' => $row['booking_amount']]) : null;
        }
        unset($row);

        return $rows;
    }

    // ---------------------------------------------------------------- money

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

    /** What the guest gets back when the guide approves their cancellation request. */
    public function guestCancelRefund(array $booking): float
    {
        return round((float) $booking['amount'] * (1 - self::GUEST_CANCEL_FEE_RATE), 2);
    }

    /** Whether the trip will finish on its own: it has started and its length is known. */
    public static function finishesAutomatically(array $booking): bool
    {
        return ($booking['trip_status'] ?? null) === 'started' && !empty($booking['started_at'])
            && $booking['duration_hours'] !== null && $booking['duration_hours'] !== '';
    }

    /**
     * Who has confirmed the start of a not-yet-started trip, for the viewer
     * on side `$own`: 'me', 'them', or null (the positions stay private).
     */
    public static function startConfirmedBy(array $row, string $own): ?string
    {
        $their = $own === 'guest' ? 'guide' : 'guest';
        if (($row['trip_status'] ?? null) !== 'pending') {
            return null;
        }
        if (!empty($row[$own . '_started_at'])) {
            return 'me';
        }

        return !empty($row[$their . '_started_at']) ? 'them' : null;
    }

    // ---------------------------------------------------------------- internals

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
            'accepted_at' => gmdate('Y-m-d H:i:s'),
        ]);
    }

    private function close(array $booking, string $status, ?float $refund, string $reason): void
    {
        $fields = [
            'status' => $status,
            'cancel_reason' => mb_substr($reason, 0, 256),
            'cancelled_at' => gmdate('Y-m-d H:i:s'),
        ];
        if ($refund !== null && $booking['payment_status'] === 'paid') {
            $fields['payment_status'] = 'refunded';
            $fields['refund_amount'] = self::decimal($refund);
        }

        $this->db->db_Update('bookings', $fields, ['`id` = ' . (int) $booking['id']]);
    }

    /**
     * Ends the match on both sides. Clearing the guest's 'interested' is what
     * returns their pick (`Selections::getPicksUsed()`).
     */
    private function dissolveMatch(array $booking): void
    {
        $this->db->db_Execute(
            'UPDATE `selections` SET `guest_decision` = NULL, `guide_decision` = NULL, `active` = 0
             WHERE `id` = ' . (int) $booking['selection_id']
        );
    }

    private function clearCancelRequest(array $booking): void
    {
        $this->db->db_Execute(
            'UPDATE `bookings` SET `cancel_requested_at` = NULL, `cancel_request_reason` = NULL WHERE `id` = ' . (int) $booking['id']
        );
    }

    private function markFinished(array $booking): void
    {
        $this->db->db_Update('bookings', [
            'trip_status' => 'finished',
            'finished_at' => gmdate('Y-m-d H:i:s'),
        ], ['`id` = ' . (int) $booking['id']]);

        // A successful trip counts towards both people's levels (`Levels`).
        if ($this->levels) {
            $this->levels->sync((int) $booking['guest_id']);
            $this->levels->sync((int) $booking['guide_id']);
        }
    }

    private function notifyFinished(array $booking): void
    {
        foreach ([[$booking['guest_id'], $booking['guide_id']], [$booking['guide_id'], $booking['guest_id']]] as [$to, $other]) {
            $otherName = self::nameOf($this->user($other), 'your match');
            $this->notifications->notify(
                $to,
                'trip_finished',
                "Your trip with {$otherName} is finished — you can leave them a review now.",
                "How was your trip with {$otherName}?",
                self::greeting($this->user($to))
                . "Your trip with {$otherName} is marked as finished. Leave them a review from their profile page — it helps everyone choose.\n\nBeLikeLocal",
                role: ($to == $booking['guest_id'] ? 'guest' : 'guide')
            );
        }
    }

    private function requireStatus(int $guestId, int $guideId, string $status, string $message): array
    {
        $booking = $this->openFor($guestId, $guideId);
        if (!$booking || $booking['status'] !== $status) {
            throw new RuntimeException($message);
        }

        return $booking;
    }

    private function requireCancelRequest(int $guestId, int $guideId): array
    {
        $booking = $this->requireStatus($guestId, $guideId, 'confirmed', 'There is no confirmed booking.');
        if (!$booking['cancel_requested_at']) {
            throw new RuntimeException('There is no cancellation request.');
        }

        return $booking;
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
        return $this->db->db_GetRowById($id, 'users', '`id`, `name`') ?: ['id' => $id];
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

    /** Hours, rounded to the half hour; `null` when not given. */
    private static function parseDuration($value): ?float
    {
        if ($value === null || $value === '') {
            return null;
        }
        if (!is_numeric($value)) {
            throw new RuntimeException('Trip length must be a number of hours.');
        }

        $hours = round((float) $value * 2) / 2;
        if ($hours < self::MIN_DURATION_HOURS || $hours > self::MAX_DURATION_HOURS) {
            throw new RuntimeException('Trip length must be between half an hour and 24 hours.');
        }

        return $hours;
    }

    /** Trimmed, single-spaced, at most `MAX_LOCATION_LENGTH` characters; `null` when empty. */
    private static function parseLocation(string $value): ?string
    {
        $value = trim((string) preg_replace('/\s+/u', ' ', $value));
        if ($value === '') {
            return null;
        }
        if (mb_strlen($value) > self::MAX_LOCATION_LENGTH) {
            throw new RuntimeException('Keep the meeting place short — ' . self::MAX_LOCATION_LENGTH . ' characters at most.');
        }

        // The other side reads it, and contact details are never shared: a
        // phone number here would get round that. A map link is fine. The
        // mask can be a character longer than a short handle it replaces,
        // hence the trim after it.
        return mb_substr(ContactFilter::mask($value, 'location'), 0, self::MAX_LOCATION_LENGTH);
    }

    /**
     * A map pin: both coordinates as JSON numbers, or neither (null). Stored
     * to 6 decimals (~10 cm), like a chat location (`Messages::coordinate()`).
     *
     * @return array{0: string, 1: string}|null
     */
    private static function parsePin($latitude, $longitude): ?array
    {
        if ($latitude === null && $longitude === null) {
            return null;
        }
        $lat = Messages::coordinate($latitude, 90);
        $lng = Messages::coordinate($longitude, 180);
        if ($lat === null || $lng === null) {
            throw new RuntimeException('That location isn’t valid.');
        }

        return [number_format($lat, 6, '.', ''), number_format($lng, 6, '.', '')];
    }

    /** A Google Maps link for a pin — what emails carry (they can't know the reader's device). */
    private static function pinUrl(array $pin): string
    {
        return 'https://www.google.com/maps/search/?api=1&query=' . $pin[0] . '%2C' . $pin[1];
    }

    /** The meeting-place lines of an email. */
    private static function placeLines(?string $location, ?array $pin): string
    {
        return ($location !== null ? "Meeting place: {$location}\n" : '')
            . ($pin !== null ? 'Meeting point on the map: ' . self::pinUrl($pin) . "\n" : '');
    }

    /**
     * Folds a row's `location_lat`/`location_lng` into `location_pin`
     * (`{latitude, longitude}` or null), the shape the client reads.
     */
    public static function withPin(array $row): array
    {
        $row['location_pin'] = isset($row['location_lat'], $row['location_lng'])
            ? ['latitude' => (float) $row['location_lat'], 'longitude' => (float) $row['location_lng']]
            : null;
        unset($row['location_lat'], $row['location_lng']);

        return $row;
    }

    /** A decline/cancellation note, shown to the other side — contact details masked (see `ContactFilter`). */
    private static function reason(string $reason): string
    {
        return mb_substr(ContactFilter::mask(trim($reason), 'reason'), 0, 256);
    }

    private static function endOf(array $booking): string
    {
        $start = $booking['started_at'] ?? $booking['meeting_at'];
        $end = strtotime($start . ' UTC') + (int) round((float) $booking['duration_hours'] * 3600);

        return gmdate('Y-m-d H:i:s', $end);
    }

    private static function cast(array $row): array
    {
        foreach (['id', 'selection_id', 'guest_id', 'guide_id'] as $key) {
            $row[$key] = (int) $row[$key];
        }
        $row['amount'] = (float) $row['amount'];
        $row['refund_amount'] = $row['refund_amount'] === null ? null : (float) $row['refund_amount'];
        $row['duration_hours'] = $row['duration_hours'] === null ? null : (float) $row['duration_hours'];
        $row['finish_requested_by'] = $row['finish_requested_by'] === null ? null : (int) $row['finish_requested_by'];
        // The platform fee is the guide's business: only their overview and
        // trips rows carry it (`commission_*`, `guide_payout`).
        unset($row['open_pair'], $row['commission_rate'], $row['commission_amount'], $row['guide_payout']);
        // Where each side stood when confirming the start is never shown.
        unset($row['guest_start_lat'], $row['guest_start_lng'], $row['guide_start_lat'], $row['guide_start_lng']);

        return self::withPin($row);
    }

    private static function nameOf(array $user, string $fallback): string
    {
        return trim((string) ($user['name'] ?? '')) ?: $fallback;
    }

    private static function greeting(array $user): string
    {
        return 'Hi ' . self::nameOf($user, 'there') . ",\n\n";
    }

    private static function money(float $amount): string
    {
        return '£' . number_format($amount, 2);
    }

    private static function decimal(float $amount): string
    {
        return number_format($amount, 2, '.', '');
    }

    private static function percent(float $rate): string
    {
        return (int) round($rate * 100) . '%';
    }

    private static function formatMeeting(?string $utc): string
    {
        return $utc ? gmdate('D j M Y, H:i', strtotime($utc . ' UTC')) . ' UTC' : 'a date to be arranged';
    }
}
