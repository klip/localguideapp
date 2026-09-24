<?php

/**
 * Data access for `selections`: one row per (guest, guide) pair. Each side
 * records its own decision ('interested'/'pass') independently; `active` is
 * kept in sync to 1 exactly when both sides are 'interested' — that's a
 * match.
 *
 * A guest's capacity to say 'interested' is gated by `Payments` (paid packs
 * of `Payments::PACK_SIZE`, mirroring the client's `useShortlistStore`).
 * Guides aren't gated — matching today's app, where only visitors pay.
 *
 * Contact details are gated separately, by `Bookings`: a match alone no
 * longer reveals them to either side — a confirmed booking does (the guest
 * paid the guide's fee, or the guide is free).
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

    public function __construct(db $db, Users $users, Payments $payments, Bookings $bookings)
    {
        $this->db = $db;
        $this->users = $users;
        $this->payments = $payments;
        $this->bookings = $bookings;
    }

    /**
     * Records `$actingUserId`'s decision about `$targetUserId`. Throws if
     * the pair isn't one guest + one guide, or if a guest is out of paid
     * picks (re-affirming an already-'interested' pick never counts against
     * capacity — only a *new* one does).
     */
    public function decide(int $actingUserId, int $targetUserId, string $decision): array
    {
        if (!in_array($decision, ['interested', 'pass'], true)) {
            throw new RuntimeException('Decision must be "interested" or "pass".');
        }

        [$guestId, $guideId, $actingIsGuest] = $this->resolvePair($actingUserId, $targetUserId);

        if ($actingIsGuest && $decision === 'interested') {
            $existing = $this->findPair($guestId, $guideId);
            if (!$existing || $existing['guest_decision'] !== 'interested') {
                $this->assertGuestHasCapacity($guestId);
            }
        }

        return $this->upsertDecision($guestId, $guideId, $actingIsGuest ? 'guest_decision' : 'guide_decision', $decision);
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
    public function revoke(int $actingUserId, int $targetUserId): array
    {
        [$guestId, $guideId, $actingIsGuest] = $this->resolvePair($actingUserId, $targetUserId);

        $row = $this->findPair($guestId, $guideId);
        if (!$row) {
            throw new RuntimeException('No selection to revoke.');
        }

        $booking = $this->bookings->openFor($guestId, $guideId);
        if ($booking) {
            if (!$actingIsGuest) {
                throw new RuntimeException('This visitor has a booking with you — cancel the booking instead.');
            }
            if ($booking['payment_status'] === 'paid') {
                throw new RuntimeException('You have paid for this booking, so only the guide can cancel it (you would be refunded in full).');
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
     * Active (mutual) matches for a user, with the other side's basic info.
     * `email` is `null` until the pair has a confirmed booking — same rule
     * as `getOverview()`.
     */
    public function getActiveMatches(int $userId): array
    {
        $counterpartColumn = null;
        if ($this->users->getRoleName($userId) === 'guest') {
            $ownColumn = 'guest_id';
            $counterpartColumn = 'guide_id';
        } elseif ($this->users->getRoleName($userId) === 'guide') {
            $ownColumn = 'guide_id';
            $counterpartColumn = 'guest_id';
        } else {
            return [];
        }

        $this->bookings->ensureFreeBookingsFor($userId);

        $rows = $this->db->db_GetArray(
            'SELECT s.`id`, s.`' . $counterpartColumn . '` AS `counterpart_id`, u.`name`,
                    IF(' . self::CONTACT_UNLOCKED . ', u.`email`, NULL) AS `email`
             FROM `selections` s
             JOIN `users` u ON u.`id` = s.`' . $counterpartColumn . '`
             WHERE s.`' . $ownColumn . '` = ' . (int) $userId . ' AND s.`active` = 1'
        );

        foreach ($rows as &$row) {
            $row['id'] = (int) $row['id'];
            $row['counterpart_id'] = (int) $row['counterpart_id'];
        }
        unset($row);

        return $rows;
    }

    /**
     * Every pair `$userId` is part of that's worth showing on
     * `MatchesPage.vue`: anything they've decided on themselves, plus
     * anyone who's marked them 'interested' but they haven't answered yet.
     * Newest activity first.
     *
     * Two things are deliberately masked, so this can't leak more than the
     * match itself earns:
     *   - `their_decision` is only ever 'interested' or `null` — a 'pass'
     *     from the other side reads as "no answer yet", never a rejection,
     *     and a pair where they passed and this user never decided isn't
     *     returned at all.
     *   - `email`/`phone` are `null` unless the pair is an active match
     *     *with a confirmed booking* — the guest paid the guide's fee, or the
     *     guide is free (see `Bookings`). Hidden from both sides until then.
     *
     * Each row also carries the guide's current fee and the pair's latest
     * booking (`booking_*`), and for the guide, what cancelling it would
     * refund right now (`refund_if_cancelled`).
     */
    public function getOverview(int $userId): array
    {
        $role = $this->users->getRoleName($userId);
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
                    IF(' . self::CONTACT_UNLOCKED . ', u.`email`, NULL) AS `email`,
                    IF(' . self::CONTACT_UNLOCKED . ', u.`phone`, NULL) AS `phone`,
                    TIMESTAMPDIFF(YEAR, p.`date_of_birth`, CURDATE()) AS `age`,
                    p.`headline`, p.`price_label`, p.`party`, p.`duration_hours`,
                    COALESCE(gp.`price_amount`, 0) AS `fee_amount`,
                    b.`id` AS `booking_id`, b.`status` AS `booking_status`, b.`payment_status`,
                    b.`amount` AS `booking_amount`, b.`meeting_at`, b.`refund_amount`, b.`trip_status`
             FROM `selections` s
             JOIN `users` u ON u.`id` = s.`' . $their . '_id`
             LEFT JOIN `user_profiles` p ON p.`user_id` = u.`id`
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

            $open = $row['booking_status'] === 'confirmed' && $row['trip_status'] !== 'finished';
            $row['refund_if_cancelled'] = $own === 'guide' && $open && $row['payment_status'] === 'paid'
                ? $this->bookings->refundAmount(['amount' => $row['booking_amount'], 'meeting_at' => $row['meeting_at']], $row['matched_at'])
                : null;
        }
        unset($row);

        return $rows;
    }

    /**
     * A guest's own decisions as counterpart ids, split by which way they
     * went. `stores/shortlist.ts` is in-memory, so this is what the client
     * seeds it from after a reload — without it a refresh reports "0 picked"
     * for someone who has spent half their pack.
     *
     * Returns empty lists for a guide: the column is the *guest's* decision,
     * and guides don't spend picks.
     *
     * @return array{picked: int[], passed: int[]}
     */
    public function getDecisionIdsFor(int $guestId): array
    {
        $result = ['picked' => [], 'passed' => []];

        foreach ($this->db->db_GetArray(
            'SELECT `guide_id`, `guest_decision` FROM `selections`
             WHERE `guest_id` = ' . $guestId . ' AND `guest_decision` IS NOT NULL'
        ) as $row) {
            $key = $row['guest_decision'] === 'interested' ? 'picked' : 'passed';
            $result[$key][] = (int) $row['guide_id'];
        }

        return $result;
    }

    /** How many of a guest's paid picks are currently spent (an 'interested' decision on their side). */
    public function getPicksUsed(int $guestId): int
    {
        return (int) $this->db->db_GetFieldFromQuery(
            'SELECT COUNT(*) AS n FROM `selections` WHERE `guest_id` = ' . $guestId . ' AND `guest_decision` = "interested"',
            'n'
        );
    }

    /** SQL join condition: `b` is the pair's most recent booking, if any. */
    private const LATEST_BOOKING = 'b.`id` = (SELECT MAX(bb.`id`) FROM `bookings` bb WHERE bb.`selection_id` = s.`id`)';

    /**
     * SQL condition: contact details are visible for pair `s` — active, with
     * a confirmed booking (paid, or free). The same rule for both sides.
     */
    private const CONTACT_UNLOCKED = 's.`active` = 1 AND EXISTS (
        SELECT 1 FROM `bookings` cb WHERE cb.`selection_id` = s.`id` AND cb.`status` = "confirmed")';

    private function upsertDecision(int $guestId, int $guideId, string $decisionField, string $decision): array
    {
        $this->db->db_InsertUpdate('selections', [
            'guest_id' => $guestId,
            'guide_id' => $guideId,
            $decisionField => $decision,
        ], [$decisionField]);

        return $this->syncActive($guestId, $guideId);
    }

    /** Recomputes and persists `active` for a pair from its current decisions; returns the row. */
    private function syncActive(int $guestId, int $guideId): array
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
        if ($justMatched) {
            // A free guide's booking opens right here; a paid one waits for the guest to pay.
            $this->bookings->onMatched($row);
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
    private function resolvePair(int $actingUserId, int $targetUserId): array
    {
        if ($actingUserId === $targetUserId) {
            throw new RuntimeException('You cannot select yourself.');
        }

        $actingRole = $this->users->getRoleName($actingUserId);
        $targetRole = $this->users->getRoleName($targetUserId);

        if ($actingRole === 'guest' && $targetRole === 'guide') {
            return [$actingUserId, $targetUserId, true];
        }
        if ($actingRole === 'guide' && $targetRole === 'guest') {
            return [$targetUserId, $actingUserId, false];
        }

        throw new RuntimeException('Selections must be between a guest and a guide.');
    }
}
