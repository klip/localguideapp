<?php

// End-to-end check of the clock-driven booking steps and starting a trip
// (migration 092): the guide's answer reminder, the 15-minute warning, an
// unanswered request cancelled with the fee minus the platform fee refunded,
// trip reminders a day and two hours ahead, both sides confirming the start
// (within 20 m of each other when both send a position, or by button alone),
// and a started trip finishing after its length.
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

    // 3. At the meeting time it's cancelled: the fee minus the platform fee back.
    $db->db_Execute('UPDATE `bookings` SET `meeting_at` = "' . utc(-60) . '" WHERE `id` = ' . $id);
    $bookings->sweep();
    $expired = row($id);
    check('unanswered request declined', $expired['status'] === 'declined');
    check('refunded the fee minus the platform fee', $expired['payment_status'] === 'refunded' && (float) $expired['refund_amount'] === 42.5, (string) $expired['refund_amount']);
    check('both told it expired', heard($guide, 'booking_expired') === 1 && heard($guest, 'booking_expired') === 1);

    // 4. Trip reminders, a day and two hours ahead.
    $trip = booking(['status' => 'confirmed', 'meeting_at' => utc(20 * 3600), 'accepted_at' => utc(-3 * 86400), 'duration_hours' => '2.0']);
    $bookings->sweep();
    check('24 h reminder to both', heard($guide, 'trip_reminder') === 1 && heard($guest, 'trip_reminder') === 1);
    $db->db_Execute('UPDATE `bookings` SET `meeting_at` = "' . utc(90 * 60) . '" WHERE `id` = ' . $trip);
    $bookings->sweep();
    check('2 h reminder to both', heard($guide, 'trip_reminder') === 2 && heard($guest, 'trip_reminder') === 2);
    check('the clock no longer starts a trip', row($trip)['trip_status'] === 'pending');

    // 5. Starting: not before 30 minutes ahead; finishing needs a start.
    [$s, $b] = api('bookingAction', ['targetId' => $guide, 'op' => 'startTrip'], $guest, 'guest');
    check('too early to start', $s === 400 && str_contains((string) ($b['error'] ?? ''), 'from'), (string) ($b['error'] ?? ''));
    [$s, $b] = api('bookingAction', ['targetId' => $guide, 'op' => 'requestFinish'], $guest, 'guest');
    check('finishing an unstarted trip refused', $s === 400 && str_contains((string) ($b['error'] ?? ''), 'Start the trip first'));

    $db->db_Execute('UPDATE `bookings` SET `meeting_at` = "' . utc(10 * 60) . '" WHERE `id` = ' . $trip);
    [$s, $b] = api('bookingAction', ['targetId' => $guide, 'op' => 'startTrip', 'latitude' => 38.7100, 'longitude' => -9.1400], $guest, 'guest');
    check('the guest confirms with a position', $s === 200 && ($b['trip_status'] ?? '') === 'pending');
    check('no position in the response', !array_key_exists('guest_start_lat', $b));
    check('the guide is asked to confirm', heard($guide, 'trip_start_confirmed') === 1);
    [, $trips] = api('trips', [], $guest, 'guest');
    $mine = array_values(array_filter($trips['trips'] ?? [], fn ($t) => (int) $t['id'] === $trip))[0] ?? [];
    check('trips row says "me" for the guest', ($mine['start_confirmed_by'] ?? null) === 'me');

    // ~110 m away: refused.
    [$s, $b] = api('bookingAction', ['targetId' => $guest, 'op' => 'startTrip', 'latitude' => 38.7110, 'longitude' => -9.1400], $guide, 'guide');
    check('the guide 110 m away is refused', $s === 400 && str_contains((string) ($b['error'] ?? ''), 'meet up'), (string) ($b['error'] ?? ''));
    check('…and the trip not started', row($trip)['trip_status'] === 'pending' && row($trip)['guide_started_at'] === null);

    // ~11 m away: started.
    [$s, $b] = api('bookingAction', ['targetId' => $guest, 'op' => 'startTrip', 'latitude' => 38.7101, 'longitude' => -9.1400], $guide, 'guide');
    check('the guide 11 m away starts it', $s === 200 && ($b['trip_status'] ?? '') === 'started' && !empty($b['started_at']));
    check('both told it started', heard($guide, 'trip_started') === 1 && heard($guest, 'trip_started') === 1);
    [$s] = api('bookingAction', ['targetId' => $guest, 'op' => 'startTrip'], $guide, 'guide');
    check('starting twice refused', $s === 400);

    // 6. It finishes after its length since the start (2 h; started 3 h ago).
    $db->db_Execute('UPDATE `bookings` SET `payment_status` = "none", `started_at` = "' . utc(-3 * 3600) . '" WHERE `id` = ' . $trip);
    $bookings->sweep();
    check('the started trip finishes after its length', row($trip)['trip_status'] === 'finished');

    // 7. Location off on one side: the buttons alone start it.
    $plain = booking(['status' => 'confirmed', 'payment_status' => 'none', 'amount' => '0.00', 'meeting_at' => utc(-5 * 60), 'accepted_at' => utc(-86400)]);
    [$s1] = api('bookingAction', ['targetId' => $guide, 'op' => 'startTrip', 'latitude' => 38.7100, 'longitude' => -9.1400], $guest, 'guest');
    [$s2, $b] = api('bookingAction', ['targetId' => $guest, 'op' => 'startTrip'], $guide, 'guide');
    check('button-only confirmation starts it', $s1 === 200 && $s2 === 200 && ($b['trip_status'] ?? '') === 'started');
} finally {
    if ($created) {
        $in = implode(',', $created);
        $db->db_Execute('DELETE FROM `bookings` WHERE `id` IN (' . $in . ')');
    }
    $db->db_Execute(
        'DELETE FROM `notifications` WHERE `user_id` IN (' . $guest . ',' . $guide . ') AND `created_at` >= "' . $started . '"'
    );
    $db->db_Execute('DELETE FROM `email_outbox` WHERE `created_at` >= "' . $started . '" AND `status` = "queued"');
}

echo $failures ? "\n{$failures} check(s) failed.\n" : "\nAll checks passed.\n";
exit($failures ? 1 : 0);
