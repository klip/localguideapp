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
 */
class Selections
{
    /** @var db */
    private $db;
    /** @var Users */
    private $users;
    /** @var Payments */
    private $payments;

    public function __construct(db $db, Users $users, Payments $payments)
    {
        $this->db = $db;
        $this->users = $users;
        $this->payments = $payments;
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
     */
    public function revoke(int $actingUserId, int $targetUserId): array
    {
        [$guestId, $guideId, $actingIsGuest] = $this->resolvePair($actingUserId, $targetUserId);

        $row = $this->findPair($guestId, $guideId);
        if (!$row) {
            throw new RuntimeException('No selection to revoke.');
        }

        $field = $actingIsGuest ? 'guest_decision' : 'guide_decision';
        $this->db->db_Execute(
            'UPDATE `selections` SET `' . $field . '` = NULL WHERE `id` = ' . (int) $row['id']
        );

        return $this->syncActive($guestId, $guideId);
    }

    /** Active (mutual) matches for a user, with the other side's basic info. */
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

        return $this->db->db_GetArray(
            'SELECT s.`id`, s.`' . $counterpartColumn . '` AS `counterpart_id`, u.`name`, u.`email`
             FROM `selections` s
             JOIN `users` u ON u.`id` = s.`' . $counterpartColumn . '`
             WHERE s.`' . $ownColumn . '` = ' . (int) $userId . ' AND s.`active` = 1'
        );
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
     *   - `email`/`phone` are `null` unless the pair is an active match —
     *     contact details are exactly what mutual interest unlocks.
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

        $rows = $this->db->db_GetArray(
            'SELECT s.`id`, s.`' . $their . '_id` AS `counterpart_id`,
                    s.`' . $own . '_decision` AS `my_decision`,
                    IF(s.`' . $their . '_decision` = "interested", "interested", NULL) AS `their_decision`,
                    s.`active`, s.`updated_at`,
                    u.`name`, u.`image`,
                    IF(s.`active` = 1, u.`email`, NULL) AS `email`,
                    IF(s.`active` = 1, u.`phone`, NULL) AS `phone`,
                    TIMESTAMPDIFF(YEAR, p.`date_of_birth`, CURDATE()) AS `age`,
                    p.`headline`, p.`price_label`, p.`party`, p.`duration_hours`
             FROM `selections` s
             JOIN `users` u ON u.`id` = s.`' . $their . '_id`
             LEFT JOIN `user_profiles` p ON p.`user_id` = u.`id`
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
        }
        unset($row);

        return $rows;
    }

    /** How many of a guest's paid picks are currently spent (an 'interested' decision on their side). */
    public function getPicksUsed(int $guestId): int
    {
        return (int) $this->db->db_GetFieldFromQuery(
            'SELECT COUNT(*) AS n FROM `selections` WHERE `guest_id` = ' . $guestId . ' AND `guest_decision` = "interested"',
            'n'
        );
    }

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
        if ((int) $row['active'] !== $active) {
            $this->db->db_Execute('UPDATE `selections` SET `active` = ' . $active . ' WHERE `id` = ' . (int) $row['id']);
            $row['active'] = $active;
        }

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
