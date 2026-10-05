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
 * Contact details for a pair are shown to *either* side only once a
 * `confirmed` booking exists (see `Selections::getOverview()`).
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
                "You matched with {$guestName}. Your trip and their contact details are on your My trips page.",
                "New match: {$guestName}",
                self::greeting($guide)
                . "You and {$guestName} are a match. You don't charge a fee, so your contact details have been shared with each other.\n\n"
                . self::contactBlock($guest)
                . "\nGet in touch to arrange the tour. When it's over, mark the trip finished on your My trips page.\n\nRockGuide"
            );
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
     * is optional — with it, the trip finishes on its own; so is `$location`,
     * a short description of where to meet. The booking waits for the guide
     * to accept it. Returns the new booking.
     */
    public function pay(int $guestId, int $guideId, string $meetingAt, $durationHours = null, string $location = ''): array
    {
        $selection = $this->activeSelection($guestId, $guideId);

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
            . ($location !== null ? "Meeting place: {$location}\n" : '') . "\n"
            . "Accept it on your Matches page to confirm the date — you'll both see each other's contact details then. "
            . "If you can't make it, decline and they're refunded in full. If you don't answer before the meeting time, it's declined automatically.\n\nRockGuide"
        );

        return $booking;
    }

    /** The guide confirms a pending booking — contact details unlock for both. */
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
            "{$guideName} confirmed your booking for {$when}. Their contact details are on your My trips page.",
            "{$guideName} confirmed your booking",
            self::greeting($this->user($guestId))
            . "{$guideName} has confirmed your tour on {$when}.\n\n"
            . self::contactBlock($guide)
            . "\nGet in touch to arrange the details.\n\nRockGuide"
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
            . "\nYour payment of {$refund} has been refunded in full. You're still matched, so you can book another time from your Matches page.\n\nRockGuide"
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
            . "{$guestName} withdrew their booking request for " . self::formatMeeting($booking['meeting_at']) . ". Nothing more to do.\n\nRockGuide"
        );

        return $this->find($booking['id']);
    }

    /**
     * Either side sets (or clears, with an empty string) where an open
     * booking's trip meets. The other side is told.
     */
    public function setLocation(int $actingUserId, int $guestId, int $guideId, string $location): array
    {
        $booking = $this->openFor($guestId, $guideId);
        if (!$booking) {
            throw new RuntimeException('There is no upcoming trip to set a meeting place for.');
        }
        $location = self::parseLocation($location);

        $this->db->db_Execute(
            'UPDATE `bookings` SET `location` = '
            . ($location === null ? 'NULL' : '"' . $this->db->db_Escape($location) . '"')
            . ' WHERE `id` = ' . $booking['id']
        );

        $otherId = $actingUserId === $guestId ? $guideId : $guestId;
        $actorName = self::nameOf($this->user($actingUserId), 'Your match');
        $message = $location === null
            ? "{$actorName} removed the meeting place for your trip on " . self::formatMeeting($booking['meeting_at']) . '.'
            : "{$actorName} set the meeting place for your trip on " . self::formatMeeting($booking['meeting_at']) . ": {$location}";
        $this->notifications->notify(
            $otherId,
            'trip_location',
            $message,
            "{$actorName} updated your trip's meeting place",
            self::greeting($this->user($otherId)) . $message . "\n\nRockGuide"
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
            . "The selection you used on them is back in your pack, so you can pick another guide.\n\nRockGuide"
        );

        return $this->find($booking['id']);
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
            . "If you refuse, the booking stands. Answer on your My trips page.\n\nRockGuide"
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
            . "{$guestName} withdrew their cancellation request. Your tour on " . self::formatMeeting($booking['meeting_at']) . " goes ahead.\n\nRockGuide"
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
            . "The selection you used on them is back in your pack.\n\nRockGuide"
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
            . "\nRockGuide"
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
            . "{$guestName} has unmatched, so your booking with them is cancelled.\n\nRockGuide"
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
            . "{$actorName} says your trip together is over. Confirm it on your My trips page — then you can both leave a review.\n\nRockGuide"
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
                . "{$actorName} didn't confirm that your trip is finished. Ask again from your My trips page once it is.\n\nRockGuide"
            );
        }

        return $this->find($booking['id']);
    }

    /**
     * Moves bookings along with the clock. There's no scheduler in this app,
     * so this runs at the start of every API request (see `RequestProcessor`):
     *
     *   - a pending booking whose meeting time has come without the guide
     *     answering is declined and refunded in full;
     *   - a confirmed trip whose meeting time has come is `started` (a guide
     *     on a started trip is hidden from the visitor deck);
     *   - a confirmed trip with a duration whose end has passed is `finished`.
     */
    public function sweep(): void
    {
        $now = gmdate('Y-m-d H:i:s');

        foreach ($this->db->db_GetArray(
            'SELECT * FROM `bookings` WHERE `status` = "pending" AND `meeting_at` <= "' . $now . '" LIMIT 50'
        ) as $row) {
            $booking = self::cast($row);
            $this->close($booking, 'declined', $booking['amount'], 'The guide did not confirm in time');

            $guideName = self::nameOf($this->user($booking['guide_id']), 'Your guide');
            $refund = self::money($booking['amount']);
            $this->notifications->notify(
                $booking['guest_id'],
                'booking_expired',
                "{$guideName} didn’t confirm your booking in time. You have been refunded {$refund}.",
                "{$guideName} didn't confirm in time",
                self::greeting($this->user($booking['guest_id']))
                . "{$guideName} didn't confirm your tour for " . self::formatMeeting($booking['meeting_at'])
                . " before it was due, so it's been called off and your {$refund} refunded in full.\n\nRockGuide"
            );
            $this->notifications->notify(
                $booking['guide_id'],
                'booking_expired',
                'A booking request expired before you answered it; the visitor was refunded.',
                'A booking request expired',
                self::greeting($this->user($booking['guide_id']))
                . 'You didn\'t answer a booking request for ' . self::formatMeeting($booking['meeting_at'])
                . " in time, so it was declined and the visitor refunded.\n\nRockGuide"
            );
        }

        $this->db->db_Execute(
            'UPDATE `bookings` SET `trip_status` = "started"
             WHERE `status` = "confirmed" AND `trip_status` = "pending"
               AND `meeting_at` IS NOT NULL AND `meeting_at` <= "' . $now . '"'
        );

        foreach ($this->db->db_GetArray(
            'SELECT * FROM `bookings`
             WHERE `status` = "confirmed" AND `trip_status` <> "finished"
               AND `meeting_at` IS NOT NULL AND `duration_hours` IS NOT NULL
               AND `meeting_at` + INTERVAL ROUND(`duration_hours` * 60) MINUTE <= "' . $now . '"
             LIMIT 50'
        ) as $row) {
            $booking = self::cast($row);
            $this->markFinished($booking);
            $this->notifyFinished($booking);
        }
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
     * can drive either. Contact details are only included while the trip is
     * confirmed and the pair still matched. `is_latest` marks the pair's most
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
                    b.`refund_amount`, b.`trip_status`, b.`accepted_at`,
                    b.`cancel_reason`, b.`cancelled_at`, b.`cancel_requested_at`, b.`cancel_request_reason`,
                    b.`finish_requested_by`, b.`finished_at`,
                    s.`active`, s.`matched_at`,
                    u.`name`, u.`image`,
                    IF(b.`status` = "confirmed" AND s.`active` = 1, u.`email`, NULL) AS `email`,
                    IF(b.`status` = "confirmed" AND s.`active` = 1, u.`phone`, NULL) AS `phone`,
                    COALESCE(gp.`price_amount`, 0) AS `fee_amount`,
                    b.`id` = (SELECT MAX(bb.`id`) FROM `bookings` bb WHERE bb.`selection_id` = b.`selection_id`) AS `is_latest`
             FROM `bookings` b
             JOIN `selections` s ON s.`id` = b.`selection_id`
             JOIN `users` u ON u.`id` = b.`' . $their . '_id`
             LEFT JOIN `user_profiles` gp ON gp.`user_id` = b.`guide_id`
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
            $row['booking_duration_hours'] = $row['booking_duration_hours'] === null ? null : (float) $row['booking_duration_hours'];
            $row['finish_requested_by'] = $row['finish_requested_by'] === null
                ? null
                : ((int) $row['finish_requested_by'] === $userId ? 'me' : 'them');
            $row['auto_finish'] = self::finishesAutomatically(['meeting_at' => $row['meeting_at'], 'duration_hours' => $row['booking_duration_hours']]);

            $open = $row['booking_status'] === 'confirmed' && $row['trip_status'] !== 'finished';
            $row['phase'] = !$open ? 'past' : ($row['trip_status'] === 'started' ? 'current' : 'scheduled');

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

    /** Whether the trip will finish on its own (both a meeting time and a duration are known). */
    public static function finishesAutomatically(array $booking): bool
    {
        return !empty($booking['meeting_at']) && $booking['duration_hours'] !== null && $booking['duration_hours'] !== '';
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
                . "Your trip with {$otherName} is marked as finished. Leave them a review from their profile page — it helps everyone choose.\n\nRockGuide"
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

        return $value;
    }

    private static function reason(string $reason): string
    {
        return mb_substr(trim($reason), 0, 256);
    }

    private static function endOf(array $booking): string
    {
        $end = strtotime($booking['meeting_at'] . ' UTC') + (int) round((float) $booking['duration_hours'] * 3600);

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
        unset($row['open_pair']);

        return $row;
    }

    private static function nameOf(array $user, string $fallback): string
    {
        return trim((string) ($user['name'] ?? '')) ?: $fallback;
    }

    private static function greeting(array $user): string
    {
        return 'Hi ' . self::nameOf($user, 'there') . ",\n\n";
    }

    private static function contactBlock(array $user): string
    {
        return 'Email: ' . ($user['email'] ?? '—') . "\n"
            . (!empty($user['phone']) ? 'Phone: ' . $user['phone'] . "\n" : '');
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
