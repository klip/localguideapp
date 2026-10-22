<?php

// End-to-end check of the clock-driven booking steps and starting a trip
// (migrations 092, 093): the guide's answer reminder, the 15-minute warning,
// an unanswered request cancelled and refunded in full, trip reminders a day
// and two hours ahead, the guide starting the trip (within 50 m of the guest
// when both phones report where they are, or after both confirm the meetup
// when GPS is vague), a started trip's cancellation approved or disputed and
// settled by staff, a no-show refunded in full, and finishing after the length.
//
// It uses the first active pair with a paid guide and no open booking, writes
// its own bookings directly, runs `Bookings::sweep()` in-process and calls the
// API over internal HTTP as the gateway does.
//
//   docker stop lemp-rg-mailer
//   docker exec lemp-rg-api php /var/www/localguideapp/lib/tests/trip-start-e2e.php
//   docker start lemp-rg-mailer
//
// The mailer is stopped so the emails aren't sent; the bookings, notifications
// and queued mail it creates are removed at the end.

require_once __DIR__ . '/../../api/bootstrap.php';

$failures = 0;
function check(string $name, bool $ok, string $extra = ''): void
{
    global $failures;
    echo ($ok ? 'ok   ' : 'FAIL ') . $name . ($extra !== '' ? " — {$extra}" : '') . "\n";
    if (!$ok) {
        $failures++;
    }
}

/** One API action as user `$userId` acting as `$role`: [status, body]. */
function api(string $action, array $payload, int $userId, string $role): array
{
    $ch = curl_init('http://nginx:8081/');
    curl_setopt_array($ch, [
        CURLOPT_POST => true,
        CURLOPT_POSTFIELDS => json_encode(['action' => $action] + $payload),
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_HTTPHEADER => [
            'Content-Type: application/json',
            'X-Internal-Token: ' . getenv('INTERNAL_TOKEN'),
            'X-User-Id: ' . $userId,
            'X-Acting-Role: ' . $role,
        ],
    ]);
    $raw = (string) curl_exec($ch);
    $status = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    return [$status, json_decode($raw, true) ?: []];
}

function utc(int $offsetSeconds): string
{
    return gmdate('Y-m-d H:i:s', time() + $offsetSeconds);
}

$db = new db();
$redis = new RedisClient();
$notifications = new Notifications($db, new MailQueue($db, $redis), new Push($redis, new Badges($db)));
$bookings = new Bookings($db, $notifications, new Levels($db, $notifications));

$pair = $db->db_GetRow(
    'SELECT s.`id`, s.`guest_id`, s.`guide_id` FROM `selections` s
     JOIN `user_profiles` p ON p.`user_id` = s.`guide_id` AND p.`price_amount` > 0
     WHERE s.`active` = 1
       AND NOT EXISTS (SELECT 1 FROM `bookings` b WHERE b.`selection_id` = s.`id` AND b.`open_pair` IS NOT NULL)
       AND NOT EXISTS (SELECT 1 FROM `user_freezes` f WHERE f.`user_id` IN (s.`guest_id`, s.`guide_id`) AND f.`lifted_at` IS NULL)
     ORDER BY s.`id` DESC LIMIT 1'
);
if (!$pair) {
    echo "No active pair with a paid guide and no open booking to test with.\n";
    exit(1);
}
[$selectionId, $guest, $guide] = [(int) $pair['id'], (int) $pair['guest_id'], (int) $pair['guide_id']];
echo "Pair: guest #{$guest}, guide #{$guide}\n";

$started = gmdate('Y-m-d H:i:s');
$created = [];
// Cancelling dissolves the match; put it back as it was.
$match = $db->db_GetRowById($selectionId, 'selections', '`guest_decision`, `guide_decision`, `active`, `matched_at`');
function restoreMatch(): void
{
    global $db, $selectionId, $match;
    $db->db_Execute(
        'UPDATE `selections` SET `guest_decision` = ' . ($match['guest_decision'] === null ? 'NULL' : '"' . $match['guest_decision'] . '"')
        . ', `guide_decision` = ' . ($match['guide_decision'] === null ? 'NULL' : '"' . $match['guide_decision'] . '"')
        . ', `active` = ' . (int) $match['active']
        . ', `matched_at` = ' . ($match['matched_at'] === null ? 'NULL' : '"' . $match['matched_at'] . '"')
        . ' WHERE `id` = ' . $selectionId
    );
}

/** An internal-only API action, as the CMS sends it: [status, body]. */
function internal(array $payload): array
{
    return ServiceHttp::call('http://nginx:8081', $payload);
}

/** A booking row for the pair, straight into the table. */
function booking(array $fields): int
{
    global $db, $selectionId, $guest, $guide, $created;
    $id = (int) $db->db_Insert('bookings', $fields + [
        'selection_id' => $selectionId,
        'guest_id' => $guest,
        'guide_id' => $guide,
        'amount' => '50.00',
        'commission_rate' => '0.1500',
        'commission_amount' => '7.50',
        'guide_payout' => '42.50',
        'payment_status' => 'paid',
    ]);
    $created[] = $id;

    return $id;
}

function row(int $id): array
{
    global $db;

    return $db->db_GetRowById($id, 'bookings');
}

/** Notifications of `$kind` written since the test began, for `$userId`. */
function heard(int $userId, string $kind): int
{
    global $db, $started;

    return (int) $db->db_GetFieldFromQuery(
        'SELECT COUNT(*) AS `n` FROM `notifications` WHERE `user_id` = ' . $userId
        . ' AND `kind` = "' . $kind . '" AND `created_at` >= "' . $started . '"',
        'n'
    );
}

try {
    // 1. The guide is reminded of a request that has waited, a day ahead.
    $id = booking(['status' => 'pending', 'meeting_at' => utc(20 * 3600), 'created_at' => utc(-5 * 3600)]);
    $bookings->sweep();
    check('answer reminder stamped', row($id)['answer_reminded_at'] !== null);
    check('the guide is reminded to answer', heard($guide, 'request_reminder') === 1);
    $bookings->sweep();
    check('…only once', heard($guide, 'request_reminder') === 1);

    // 2. Fifteen minutes before, both are warned.
    $db->db_Execute('UPDATE `bookings` SET `meeting_at` = "' . utc(10 * 60) . '" WHERE `id` = ' . $id);
    $bookings->sweep();
    check('expiry warning stamped', row($id)['expiry_warned_at'] !== null);
    check('both sides are warned', heard($guide, 'request_expiring') === 1 && heard($guest, 'request_expiring') === 1);

    // 3. At the meeting time it's cancelled and refunded in full.
    $db->db_Execute('UPDATE `bookings` SET `meeting_at` = "' . utc(-60) . '" WHERE `id` = ' . $id);
    $bookings->sweep();
    $expired = row($id);
    check('unanswered request declined', $expired['status'] === 'declined');
    check('refunded in full', $expired['payment_status'] === 'refunded' && (float) $expired['refund_amount'] === 50.0, (string) $expired['refund_amount']);
    check('both told it expired', heard($guide, 'booking_expired') === 1 && heard($guest, 'booking_expired') === 1);

    // 4. Trip reminders, a day and two hours ahead.
    $trip = booking(['status' => 'confirmed', 'meeting_at' => utc(20 * 3600), 'accepted_at' => utc(-3 * 86400), 'duration_hours' => '2.0']);
    $bookings->sweep();
    check('24 h reminder to both', heard($guide, 'trip_reminder') === 1 && heard($guest, 'trip_reminder') === 1);
    $db->db_Execute('UPDATE `bookings` SET `meeting_at` = "' . utc(90 * 60) . '" WHERE `id` = ' . $trip);
    $bookings->sweep();
    check('2 h reminder to both', heard($guide, 'trip_reminder') === 2 && heard($guest, 'trip_reminder') === 2);
    check('the clock doesn’t start a trip', row($trip)['trip_status'] === 'pending');

    // 5. Only the guide starts it, from 15 minutes before, within 50 m.
    [$s, $b] = api('bookingAction', ['targetId' => $guide, 'op' => 'startTrip'], $guest, 'guest');
    check('the guest can’t start it', $s === 400 && str_contains((string) ($b['error'] ?? ''), 'Only the guide'));
    [$s, $b] = api('bookingAction', ['targetId' => $guest, 'op' => 'startTrip'], $guide, 'guide');
    check('too early to start', $s === 400 && str_contains((string) ($b['error'] ?? ''), 'from'), (string) ($b['error'] ?? ''));
    [$s, $b] = api('bookingAction', ['targetId' => $guide, 'op' => 'requestFinish'], $guest, 'guest');
    check('finishing an unstarted trip refused', $s === 400);

    $db->db_Execute('UPDATE `bookings` SET `meeting_at` = "' . utc(10 * 60) . '" WHERE `id` = ' . $trip);
    [$s, $b] = api('tripPresence', ['targetId' => $guide, 'latitude' => 38.7100, 'longitude' => -9.1400, 'accuracy' => 8], $guest, 'guest');
    check('the guest’s position is noted', $s === 200 && $b['start_ready'] === null && row($trip)['guest_located_at'] !== null);
    [$s, $b] = api('tripPresence', ['targetId' => $guest, 'latitude' => 38.7110, 'longitude' => -9.1400, 'accuracy' => 8], $guide, 'guide');
    check('110 m apart: Start stays locked', $s === 200 && $b['start_ready'] === false && $b['poor_gps'] === false);
    [$s, $b] = api('bookingAction', ['targetId' => $guest, 'op' => 'startTrip', 'latitude' => 38.7110, 'longitude' => -9.1400, 'accuracy' => 8], $guide, 'guide');
    check('starting 110 m apart refused', $s === 400 && str_contains((string) ($b['error'] ?? ''), 'meet up'), (string) ($b['error'] ?? ''));
    [, $b] = api('tripPresence', ['targetId' => $guest, 'latitude' => 38.71036, 'longitude' => -9.1400, 'accuracy' => 8], $guide, 'guide');
    check('40 m apart: Start unlocks', $b['start_ready'] === true);
    [$s, $b] = api('bookingAction', ['targetId' => $guest, 'op' => 'startTrip', 'latitude' => 38.71036, 'longitude' => -9.1400, 'accuracy' => 8], $guide, 'guide');
    check('the guide starts it', $s === 200 && ($b['trip_status'] ?? '') === 'started' && !empty($b['started_at']));
    check('no positions in the response', !array_key_exists('guest_lat', $b) && !array_key_exists('guide_lat', $b));
    check('both told it started', heard($guide, 'trip_started') === 1 && heard($guest, 'trip_started') === 1);

    // 6. The guest cancels the started trip; the guide refuses: our team decides.
    [$s, $b] = api('bookingAction', ['targetId' => $guide, 'op' => 'requestCancellation', 'reason' => 'We never met'], $guest, 'guest');
    check('the guest cancels a started trip — no refund yet', $s === 200 && ($b['status'] ?? '') === 'confirmed' && ($b['payment_status'] ?? '') === 'paid');
    [$s] = api('bookingAction', ['targetId' => $guest, 'op' => 'refuseCancellation', 'reason' => 'We did meet'], $guide, 'guide');
    $disputed = row($trip);
    check('the refusal opens a dispute', $s === 200 && $disputed['disputed_at'] !== null && $disputed['dispute_complaint_id'] !== null);
    $complaint = $db->db_GetRowById((int) $disputed['dispute_complaint_id'], 'complaints');
    check('…as a complaint tied to the booking', $complaint && (int) $complaint['booking_id'] === $trip && (int) $complaint['reporter_id'] === $guest);
    check('both told our team decides', heard($guide, 'trip_disputed') === 1 && heard($guest, 'trip_disputed') === 1);
    $db->db_Execute('UPDATE `bookings` SET `started_at` = "' . utc(-3 * 3600) . '" WHERE `id` = ' . $trip);
    $bookings->sweep();
    check('a disputed trip doesn’t finish by itself', row($trip)['trip_status'] === 'started');
    [$s, $b] = internal(['action' => 'staffSettleDispute', 'bookingId' => $trip, 'refund' => 20, 'note' => 'Half the walk happened']);
    check('staff settle it with a partial refund', $s === 200 && row($trip)['status'] === 'cancelled' && (float) row($trip)['refund_amount'] === 20.0);
    restoreMatch();

    // 7. Poor GPS: both confirm the meetup, which unlocks Start.
    $vague = booking(['status' => 'confirmed', 'meeting_at' => utc(5 * 60), 'accepted_at' => utc(-86400), 'duration_hours' => '2.0']);
    api('tripPresence', ['targetId' => $guide, 'latitude' => 38.7100, 'longitude' => -9.1400, 'accuracy' => 120], $guest, 'guest');
    [, $b] = api('tripPresence', ['targetId' => $guest, 'latitude' => 38.7110, 'longitude' => -9.1400, 'accuracy' => 10], $guide, 'guide');
    check('vague GPS: both asked to confirm the meetup', $b['poor_gps'] === true && $b['start_ready'] === false);
    [, $b] = api('tripPresence', ['targetId' => $guest, 'meetup' => true], $guide, 'guide');
    check('the guide confirms', $b['meetup'] === 'me' && $b['start_ready'] === false);
    [, $b] = api('tripPresence', ['targetId' => $guide, 'meetup' => true], $guest, 'guest');
    check('the guest confirms', $b['meetup'] === 'both');
    [$s, $b] = api('bookingAction', ['targetId' => $guest, 'op' => 'startTrip', 'latitude' => 38.7110, 'longitude' => -9.1400, 'accuracy' => 10], $guide, 'guide');
    check('the meetup unlocks the start', $s === 200 && ($b['trip_status'] ?? '') === 'started', (string) ($b['error'] ?? ''));
    $db->db_Execute('UPDATE `bookings` SET `payment_status` = "none", `started_at` = "' . utc(-3 * 3600) . '" WHERE `id` = ' . $vague);
    $bookings->sweep();
    check('a started trip finishes after its length', row($vague)['trip_status'] === 'finished');

    // 8. Nobody started it: cancelled and refunded in full, into Past.
    $noShow = booking(['status' => 'confirmed', 'meeting_at' => utc(-4 * 3600), 'accepted_at' => utc(-86400), 'duration_hours' => '2.0']);
    $bookings->sweep();
    $gone = row($noShow);
    check('a no-show is cancelled and refunded in full', $gone['status'] === 'cancelled' && (float) $gone['refund_amount'] === 50.0);
    check('both told it wasn’t started', heard($guide, 'trip_not_started') === 1 && heard($guest, 'trip_not_started') === 1);
    [, $trips] = api('trips', [], $guest, 'guest');
    $past = array_values(array_filter($trips['trips'] ?? [], fn ($t) => (int) $t['id'] === $noShow))[0] ?? [];
    check('…and shows under Past', ($past['phase'] ?? '') === 'past');

    // 9. The guide agrees to cancel a started trip: full refund.
    $agreed = booking(['status' => 'confirmed', 'meeting_at' => utc(-10 * 60), 'accepted_at' => utc(-86400), 'trip_status' => 'started', 'started_at' => utc(-5 * 60)]);
    api('bookingAction', ['targetId' => $guide, 'op' => 'requestCancellation', 'reason' => 'Guide never came'], $guest, 'guest');
    [$s] = api('bookingAction', ['targetId' => $guest, 'op' => 'approveCancellation'], $guide, 'guide');
    check('the guide’s approval refunds in full', $s === 200 && (float) row($agreed)['refund_amount'] === 50.0);
    restoreMatch();

    // 10. Unanswered for a day: our team decides; 0 lets the trip stand.
    $late = booking(['status' => 'confirmed', 'meeting_at' => utc(-26 * 3600), 'accepted_at' => utc(-3 * 86400), 'trip_status' => 'started',
        'started_at' => utc(-26 * 3600), 'duration_hours' => '2.0', 'cancel_requested_at' => utc(-25 * 3600), 'cancel_request_reason' => 'Not as described']);
    $bookings->sweep();
    check('an unanswered cancellation goes to our team after a day', row($late)['disputed_at'] !== null);
    [$s] = internal(['action' => 'staffSettleDispute', 'bookingId' => $late, 'refund' => 0]);
    check('0 refund: the trip stands', $s === 200 && row($late)['disputed_at'] === null && row($late)['status'] === 'confirmed');
    $db->db_Execute('UPDATE `bookings` SET `payment_status` = "none" WHERE `id` = ' . $late);
    $bookings->sweep();
    check('…and finishes as usual', row($late)['trip_status'] === 'finished');

    // 11. Location off: the time alone unlocks Start.
    $plain = booking(['status' => 'confirmed', 'payment_status' => 'none', 'amount' => '0.00', 'meeting_at' => utc(-5 * 60), 'accepted_at' => utc(-86400)]);
    [$s, $b] = api('bookingAction', ['targetId' => $guest, 'op' => 'startTrip'], $guide, 'guide');
    check('without positions the guide just starts it', $s === 200 && ($b['trip_status'] ?? '') === 'started');
} finally {
    if ($created) {
        $in = implode(',', $created);
        $db->db_Execute('DELETE FROM `complaints` WHERE `booking_id` IN (' . $in . ')');
        $db->db_Execute('DELETE FROM `bookings` WHERE `id` IN (' . $in . ')');
    }
    restoreMatch();
    $db->db_Execute(
        'DELETE FROM `notifications` WHERE `user_id` IN (' . $guest . ',' . $guide . ') AND `created_at` >= "' . $started . '"'
    );
    $db->db_Execute('DELETE FROM `email_outbox` WHERE `created_at` >= "' . $started . '" AND `status` = "queued"');
}

echo $failures ? "\n{$failures} check(s) failed.\n" : "\nAll checks passed.\n";
exit($failures ? 1 : 0);
