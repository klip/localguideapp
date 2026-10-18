<?php

/**
 * Data access for `selections`: one row per (guest, guide) pair. Each side
 * records its own decision ('interested'/'pass') independently; `active` is
 * kept in sync to 1 exactly when both sides are 'interested' — that's a
 * match.
 *
 * A guest's capacity to say 'interested' is gated by `Payments` (paid packs
 * of `Payments::PACK_SIZE`, mirroring the client's `useMatchesStore`).
 * Guides aren't gated — matching today's app, where only visitors pay.
 *
 * Contact details are never shared, at any stage: a matched pair talks
 * through `Messages` (chat, with location sharing) instead.
 */
class Selections
{
    /** @var db */
    private $db;
    /** @var Users */
    private $users;
    /** @var Payments */
    private $payments;
    /** @var Bookings */
    private $bookings;
    /** @var Levels|null */
    private $levels;

    public function __construct(db $db, Users $users, Payments $payments, Bookings $bookings, ?Levels $levels = null)
    {
        $this->db = $db;
        $this->users = $users;
        $this->payments = $payments;
        $this->bookings = $bookings;
        $this->levels = $levels;
    }

    /**
     * Records `$actingUserId`'s decision about `$targetUserId`. Throws if
     * the pair isn't one guest + one guide, or if a guest is out of paid
     * picks (re-affirming an already-'interested' pick never counts against
     * capacity — only a *new* one does).
     */
    public function decide(int $actingUserId, string $actingRole, int $targetUserId, string $decision): array
    {
        if (!in_array($decision, ['interested', 'pass'], true)) {
            throw new RuntimeException('Decision must be "interested" or "pass".');
        }

        [$guestId, $guideId, $actingIsGuest] = $this->resolvePair($actingUserId, $actingRole, $targetUserId);

        // A new pick uses the guest's paid pack — unless they're in a free
        // picks bonus (`Levels`), when it's marked free and costs nothing.
        $freePick = null;
        if ($actingIsGuest && $decision === 'interested') {
            $existing = $this->findPair($guestId, $guideId);
            if (!$existing || $existing['guest_decision'] !== 'interested') {
                $freePick = $this->levels && $this->levels->activeBonusUntil($guestId, 'free_picks') !== null;
                if (!$freePick) {
                    $this->assertGuestHasCapacity($guestId);
                }
            }
        }

        $row = $this->upsertDecision($guestId, $guideId, $actingIsGuest ? 'guest_decision' : 'guide_decision', $decision);
        if ($freePick !== null) {
            $this->db->db_Execute(
                'UPDATE `selections` SET `free_pick` = ' . ($freePick ? 1 : 0) . '
                 WHERE `guest_id` = ' . $guestId . ' AND `guide_id` = ' . $guideId
            );
        }

        return $row;
    }

    /**
     * Un-matches `$actingUserId` from `$targetUserId`: clears the acting
     * side's decision and re-syncs `active` (to 0). The row — and the other
     * side's decision, if any — is kept, so the acting user is free to be
     * matched elsewhere, and this pair can become an active match again
     * later if both sides pick 'interested' again.
     *
     * A pair with an open booking can't simply be unmatched by the guide —
     * that goes through `Bookings::cancelByGuide()`, which refunds the guest.
     * The guest can walk away from a free booking (the guide is told), but
     * not from one they paid for.
     */
    public function revoke(int $actingUserId, string $actingRole, int $targetUserId): array
    {
        [$guestId, $guideId, $actingIsGuest] = $this->resolvePair($actingUserId, $actingRole, $targetUserId);

        $row = $this->findPair($guestId, $guideId);
        if (!$row) {
            throw new RuntimeException('No selection to revoke.');
        }

        $booking = $this->bookings->openFor($guestId, $guideId);
        if ($booking) {
            if (!$actingIsGuest) {
                throw new RuntimeException($booking['status'] === 'pending'
                    ? 'This visitor is waiting on your answer — decline the booking instead.'
                    : 'This visitor has a booking with you — cancel the booking instead.');
            }
            if ($booking['status'] === 'pending') {
                throw new RuntimeException('Withdraw your payment first — you’ll be refunded in full.');
            }
            if ($booking['payment_status'] === 'paid') {
                throw new RuntimeException('You have paid for this booking — ask the guide to cancel it instead.');
            }
            $this->bookings->cancelFreeByGuest($guestId, $guideId);
        }

        $field = $actingIsGuest ? 'guest_decision' : 'guide_decision';
        $this->db->db_Execute(
            'UPDATE `selections` SET `' . $field . '` = NULL WHERE `id` = ' . (int) $row['id']
        );

        return $this->syncActive($guestId, $guideId);
    }

    /**
     * Every pair `$userId` is part of that's worth showing on
     * `MatchesPage.vue`: anything they've decided on themselves, plus
     * anyone who's marked them 'interested' but they haven't answered yet.
     * Newest activity first.
     *
     * `their_decision` is deliberately masked, so this can't leak more than
     * the match itself earns: it's only ever 'interested' or `null` — a
     * 'pass' from the other side reads as "no answer yet", never a rejection,
     * and a pair where they passed and this user never decided isn't returned
     * at all. No contact details, ever: the pair chats through `Messages`.
     *
     * Each row also carries the guide's current fee and the pair's latest
     * booking (`booking_*`), and for the guide, what cancelling it would
     * refund right now (`refund_if_cancelled`). The booking's lifecycle fields
     * (acceptance, a guest's cancellation request, finishing the trip) come
     * along too; `finish_requested_by` is reduced to `'me' | 'them' | null`
     * and `auto_finish` says whether the trip ends on its own.
     */
    public function getOverview(int $userId, string $role): array
    {
        if ($role === 'guest') {
            [$own, $their] = ['guest', 'guide'];
        } elseif ($role === 'guide') {
            [$own, $their] = ['guide', 'guest'];
        } else {
            return [];
        }

        $this->bookings->ensureFreeBookingsFor($userId);

        $rows = $this->db->db_GetArray(
            'SELECT s.`id`, s.`' . $their . '_id` AS `counterpart_id`,
                    s.`' . $own . '_decision` AS `my_decision`,
                    IF(s.`' . $their . '_decision` = "interested", "interested", NULL) AS `their_decision`,
                    s.`active`, s.`matched_at`, s.`updated_at`,
                    u.`name`, u.`image`,
                    TIMESTAMPDIFF(YEAR, p.`date_of_birth`, CURDATE()) AS `age`,
                    cr.`headline`, p.`price_label`, p.`party`, p.`duration_hours`,
                    COALESCE(gp.`price_amount`, 0) AS `fee_amount`,
                    b.`id` AS `booking_id`, b.`status` AS `booking_status`, b.`payment_status`,
                    b.`amount` AS `booking_amount`, b.`meeting_at`, b.`refund_amount`, b.`trip_status`,
                    b.`duration_hours` AS `booking_duration_hours`, b.`location`, b.`accepted_at`,
                    b.`location_lat`, b.`location_lng`,
                    b.`cancel_requested_at`, b.`cancel_request_reason`,
                    b.`finish_requested_by`, b.`finished_at`,
                    ' . ($own === 'guide'
                        ? 'b.`commission_rate`, b.`guide_payout`'
                        : 'NULL AS `commission_rate`, NULL AS `guide_payout`') . '
             FROM `selections` s
             JOIN `users` u ON u.`id` = s.`' . $their . '_id`
             LEFT JOIN `user_profiles` p ON p.`user_id` = u.`id`
             LEFT JOIN `user_role` cr ON cr.`user_id` = u.`id`
               AND cr.`role_id` = (SELECT `id` FROM `roles` WHERE `name` = "' . $their . '")
             LEFT JOIN `user_profiles` gp ON gp.`user_id` = s.`guide_id`
             LEFT JOIN `bookings` b ON ' . self::LATEST_BOOKING . '
             WHERE s.`' . $own . '_id` = ' . (int) $userId . '
               AND (s.`' . $own . '_decision` IS NOT NULL OR s.`' . $their . '_decision` = "interested")
             ORDER BY s.`updated_at` DESC, s.`id` DESC'
        );

        foreach ($rows as &$row) {
            $row['id'] = (int) $row['id'];
            $row['counterpart_id'] = (int) $row['counterpart_id'];
            $row['active'] = (int) $row['active'];
            $row['age'] = $row['age'] === null ? null : (int) $row['age'];
            $row['duration_hours'] = $row['duration_hours'] === null ? null : (int) $row['duration_hours'];
            $row['fee_amount'] = (float) $row['fee_amount'];
            $row['booking_id'] = $row['booking_id'] === null ? null : (int) $row['booking_id'];
            $row['booking_amount'] = $row['booking_amount'] === null ? null : (float) $row['booking_amount'];
            $row['refund_amount'] = $row['refund_amount'] === null ? null : (float) $row['refund_amount'];
            // The guide's platform fee and payout (null for the guest, and for bookings made before 041).
            $row['commission_rate'] = $row['commission_rate'] === null ? null : (float) $row['commission_rate'];
            $row['guide_payout'] = $row['guide_payout'] === null ? null : (float) $row['guide_payout'];

            $row['booking_duration_hours'] = $row['booking_duration_hours'] === null ? null : (float) $row['booking_duration_hours'];
            $row['finish_requested_by'] = $row['finish_requested_by'] === null
                ? null
                : ((int) $row['finish_requested_by'] === $userId ? 'me' : 'them');
            $row['auto_finish'] = Bookings::finishesAutomatically(['meeting_at' => $row['meeting_at'], 'duration_hours' => $row['booking_duration_hours']]);
            $row = Bookings::withPin($row);

            $open = $row['booking_status'] === 'confirmed' && $row['trip_status'] !== 'finished';
            $paidAndOpen = $open && $row['payment_status'] === 'paid';
            $row['refund_if_cancelled'] = $own === 'guide' && $paidAndOpen
                ? $this->bookings->refundAmount(['amount' => $row['booking_amount'], 'meeting_at' => $row['meeting_at']], $row['matched_at'])
                : null;
            // What the guest gets back if the guide approves their cancellation request.
            $row['refund_if_guest_cancels'] = $paidAndOpen
                ? $this->bookings->guestCancelRefund(['amount' => $row['booking_amount']])
                : null;
        }
        unset($row);

        return $rows;
    }

    /**
     * A guest's own decisions as counterpart ids, split by which way they
     * went. `stores/matches.ts` is in-memory, so this is what the client
     * seeds it from after a reload — without it a refresh reports "0 picked"
     * for someone who has spent half their pack.
     *
     * Returns empty lists for a guide: the column is the *guest's* decision,
     * and guides don't spend picks.
     *
     * `free` — the picks made during free picks, which don't use the pack.
     *
     * @return array{picked: int[], passed: int[], free: int[]}
     */
    public function getDecisionIdsFor(int $guestId): array
    {
        $result = ['picked' => [], 'passed' => [], 'free' => []];

        foreach ($this->db->db_GetArray(
            'SELECT `guide_id`, `guest_decision`, `free_pick` FROM `selections`
             WHERE `guest_id` = ' . $guestId . ' AND `guest_decision` IS NOT NULL'
        ) as $row) {
            $key = $row['guest_decision'] === 'interested' ? 'picked' : 'passed';
            $result[$key][] = (int) $row['guide_id'];
            if ($key === 'picked' && (int) $row['free_pick'] === 1) {
                $result['free'][] = (int) $row['guide_id'];
            }
        }

        return $result;
    }

    /** How many of a guest's paid picks are currently spent (an 'interested' on their side that wasn't a free pick). */
    public function getPicksUsed(int $guestId): int
    {
        return (int) $this->db->db_GetFieldFromQuery(
            'SELECT COUNT(*) AS n FROM `selections` WHERE `guest_id` = ' . $guestId . ' AND `guest_decision` = "interested" AND `free_pick` = 0',
            'n'
        );
    }

    /** SQL join condition: `b` is the pair's most recent booking, if any. */
    private const LATEST_BOOKING = 'b.`id` = (SELECT MAX(bb.`id`) FROM `bookings` bb WHERE bb.`selection_id` = s.`id`)';

    private function upsertDecision(int $guestId, int $guideId, string $decisionField, string $decision): array
    {
        $this->db->db_InsertUpdate('selections', [
            'guest_id' => $guestId,
            'guide_id' => $guideId,
            $decisionField => $decision,
        ], [$decisionField]);

        return $this->syncActive($guestId, $guideId, $decisionField === 'guest_decision');
    }

    /**
     * Recomputes and persists `active` for a pair from its current decisions;
     * returns the row. `$guestActed` says whose decision this was, so a new
     * match is announced to the other side (`Bookings::onMatched()`); null
     * when nobody's decision could have completed one (a revoke).
     */
    private function syncActive(int $guestId, int $guideId, ?bool $guestActed = null): array
    {
        $row = $this->findPair($guestId, $guideId);
        if (!$row) {
            throw new RuntimeException('Selection not found.');
        }

        $active = ($row['guest_decision'] === 'interested' && $row['guide_decision'] === 'interested') ? 1 : 0;
        $justMatched = $active === 1 && (int) $row['active'] !== 1;
        if ((int) $row['active'] !== $active) {
            $this->db->db_Execute(
                'UPDATE `selections` SET `active` = ' . $active
                . ($justMatched ? ', `matched_at` = UTC_TIMESTAMP()' : '')
                . ' WHERE `id` = ' . (int) $row['id']
            );
        }
        if ($justMatched && $guestActed !== null) {
            // A free guide's booking opens right here; a paid one waits for the guest to pay.
            $this->bookings->onMatched($row, $guestActed);
        }

        // mysqli hands back every column as a string; cast the ints so the
        // client's `SelectionRow` (`active: 0 | 1`) holds whether or not this
        // call flipped anything.
        $row['id'] = (int) $row['id'];
        $row['guest_id'] = (int) $row['guest_id'];
        $row['guide_id'] = (int) $row['guide_id'];
        $row['active'] = $active;

        return $row;
    }

    private function findPair(int $guestId, int $guideId)
    {
        return $this->db->db_GetRow(
            'SELECT * FROM `selections` WHERE `guest_id` = ' . $guestId . ' AND `guide_id` = ' . $guideId
        );
    }

    private function assertGuestHasCapacity(int $guestId): void
    {
        if ($this->getPicksUsed($guestId) >= $this->payments->getCapacity($guestId)) {
            throw new RuntimeException('No selections left — unlock more to keep picking.');
        }
    }

    /** @return array{0: int, 1: int, 2: bool} [guestId, guideId, actingUserIsTheGuest] */
    /**
     * The (guest, guide) pair for an action, from the role the actor is
     * acting as — an account can hold both, so two people can be paired both
     * ways and only the acting role says which pair is meant.
     *
     * @return array{0:int, 1:int, 2:bool} guest id, guide id, whether the actor is the guest
     */
    private function resolvePair(int $actingUserId, string $actingRole, int $targetUserId): array
    {
        if ($actingUserId === $targetUserId) {
            throw new RuntimeException('You cannot select yourself.');
        }
        if (!$this->users->hasRole($targetUserId, Roles::opposite($actingRole))) {
            throw new RuntimeException('Selections must be between a guest and a guide.');
        }

        return $actingRole === 'guest'
            ? [$actingUserId, $targetUserId, true]
            : [$targetUserId, $actingUserId, false];
    }
}
