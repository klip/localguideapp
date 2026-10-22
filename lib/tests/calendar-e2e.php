<?php

// End-to-end check of guides' availability calendars (docs/availability-plan.md,
// C1/C2) through the running API over internal HTTP, as the gateway forwards
// requests: seed guide #128 (a £25 fee) sets a calendar; travellers matched
// with them see only free days and times, a taken or off-hours time is
// refused, and two travellers paying for the same slot at the same moment
// can't both get it.
//
//   docker stop lemp-rg-mailer
//   docker exec lemp-rg-cms php /var/www/localguideapp/lib/tests/calendar-e2e.php
//   docker start lemp-rg-mailer
//
// The mailer is stopped so the booking emails aren't sent; the calendar,
// bookings, notifications and queued mail it creates are removed at the end.

require_once __DIR__ . '/../../cms/bootstrap.php';

const GUIDE = 128;
const GUESTS = [195, 184, 183, 202]; // all matched with GUIDE, no open booking
const UNMATCHED_GUIDE = 127;         // matched with 187 but no calendar
const TZ = 'Europe/Lisbon';

$failures = 0;
function check(string $name, bool $ok, string $extra = ''): void
{
    global $failures;
    echo ($ok ? 'ok   ' : 'FAIL ') . $name . ($extra !== '' ? " — {$extra}" : '') . "\n";
    if (!$ok) {
        $failures++;
    }
}

function request(string $action, array $payload, int $userId, string $role)
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

    return $ch;
}

/** One API action as user `$userId` acting as `$role`: [status, body]. */
function api(string $action, array $payload, int $userId, string $role): array
{
    $ch = request($action, $payload, $userId, $role);
    $raw = (string) curl_exec($ch);
    $status = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    return [$status, json_decode($raw, true) ?: []];
}

/** A Lisbon local time on `$day` as ISO UTC. */
function at(string $day, string $time): string
{
    return (new DateTimeImmutable("{$day} {$time}", new DateTimeZone(TZ)))->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d\TH:i:s\Z');
}

$db = new db();
$started = gmdate('Y-m-d H:i:s');
$day = (new DateTimeImmutable('+3 days', new DateTimeZone(TZ)))->format('Y-m-d');
$nextDay = (new DateTimeImmutable($day . ' +1 day', new DateTimeZone(TZ)))->format('Y-m-d');
$month = substr($day, 0, 7);
$hadCalendar = (bool) $db->db_GetRow('SELECT 1 FROM `guide_calendars` WHERE `user_id` = ' . GUIDE);

try {
    check('the guide has no calendar yet (test data)', !$hadCalendar);

    // C1: the guide sets one up.
    [$s, $b] = api('saveCalendar', [
        'timeZone' => TZ, 'slotMinutes' => 30, 'bufferMinutes' => 30, 'defaultTripHours' => 2,
        'minNoticeHours' => 12, 'maxDaysAhead' => 60,
        'weekly' => array_map(static fn(int $d): array => ['weekday' => $d, 'start' => 540, 'end' => 1080], range(1, 7)),
    ], GUIDE, 'guide');
    check('a guide saves weekly hours', $s === 200 && ($b['calendar']['timeZone'] ?? '') === TZ, (string) $s);
    [$s, $b] = api('saveCalendar', ['timeZone' => 'Mars/Olympus', 'weekly' => []], GUIDE, 'guide');
    check('an unknown time zone is refused, pinned', $s === 400 && ($b['field'] ?? '') === 'timeZone');
    [$s, $b] = api('saveCalendar', ['timeZone' => TZ, 'weekly' => [['weekday' => 1, 'start' => 540, 'end' => 720], ['weekday' => 1, 'start' => 600, 'end' => 800]]], GUIDE, 'guide');
    check('overlapping windows are refused', $s === 400 && ($b['field'] ?? '') === 'weekly');
    [$s] = api('saveCalendar', ['timeZone' => TZ, 'weekly' => []], GUESTS[0], 'guest');
    check('only guide mode manages a calendar', $s === 400);

    // C2: a matched traveller sees the free days and times.
    [$s, $b] = api('guideCalendar', ['guideId' => GUIDE, 'month' => $month], GUESTS[0], 'guest');
    check('a matched traveller sees the free days', $s === 200 && $b['hasCalendar'] === true && in_array($day, $b['days'] ?? [], true), (string) $s);
    check('…for the guide’s usual trip length', ($b['durationHours'] ?? 0) == 2);
    [$s, $b] = api('guideStartTimes', ['guideId' => GUIDE, 'day' => $day, 'durationHours' => 2], GUESTS[0], 'guest');
    check('…and the free start times that day', $s === 200 && in_array(at($day, '10:00'), $b['starts'] ?? [], true)
        && ($b['starts'][0] ?? '') === at($day, '09:00') && end($b['starts']) === at($day, '16:00'), count($b['starts'] ?? []) . ' starts');

    [$s, $b] = api('payGuideFee', ['targetId' => GUIDE, 'meetingAt' => at($day, '10:00'), 'durationHours' => 2], GUESTS[0], 'guest');
    check('paying for a free time works', $s === 200 && ($b['status'] ?? '') === 'pending', $s . ' ' . ($b['error'] ?? ''));

    // The slot (10:00–12:00, plus 30 minutes either side) is now held.
    [, $b] = api('guideStartTimes', ['guideId' => GUIDE, 'day' => $day, 'durationHours' => 2], GUESTS[1], 'guest');
    $starts = $b['starts'] ?? [];
    check('the held slot is gone for the next traveller', !in_array(at($day, '10:00'), $starts, true) && !in_array(at($day, '08:30'), $starts, true)
        && !in_array(at($day, '12:00'), $starts, true) && in_array(at($day, '12:30'), $starts, true));
    [$s, $b] = api('payGuideFee', ['targetId' => GUIDE, 'meetingAt' => at($day, '11:00'), 'durationHours' => 2], GUESTS[1], 'guest');
    check('paying for a taken time is refused', $s === 400 && str_contains((string) ($b['error'] ?? ''), 'isn’t free'), $s . ' ' . ($b['error'] ?? ''));
    [$s] = api('payGuideFee', ['targetId' => GUIDE, 'meetingAt' => at($day, '03:00'), 'durationHours' => 2], GUESTS[1], 'guest');
    check('…and a time outside their hours', $s === 400);
    [$s] = api('payGuideFee', ['targetId' => GUIDE, 'meetingAt' => at($day, '17:00'), 'durationHours' => 2], GUESTS[1], 'guest');
    check('…and a trip running past their hours', $s === 400);
    [$s, $b] = api('payGuideFee', ['targetId' => GUIDE, 'meetingAt' => at($day, '12:30'), 'durationHours' => 2], GUESTS[1], 'guest');
    check('the next free time works', $s === 200, $s . ' ' . ($b['error'] ?? ''));

    // Two travellers paying for the same slot at the same moment.
    $multi = curl_multi_init();
    $handles = [];
    foreach ([GUESTS[2], GUESTS[3]] as $guest) {
        $handles[$guest] = request('payGuideFee', ['targetId' => GUIDE, 'meetingAt' => at($day, '15:00'), 'durationHours' => 2], $guest, 'guest');
        curl_multi_add_handle($multi, $handles[$guest]);
    }
    do {
        curl_multi_exec($multi, $running);
        curl_multi_select($multi);
    } while ($running > 0);
    $codes = array_map(static fn($h): int => (int) curl_getinfo($h, CURLINFO_HTTP_CODE), $handles);
    sort($codes);
    check('two payments for one slot at once: exactly one gets it', $codes === [200, 400], implode(',', $codes));

    // A day off.
    [$s] = api('addDayOff', ['day' => $nextDay, 'kind' => 'off'], GUIDE, 'guide');
    [, $b] = api('guideCalendar', ['guideId' => GUIDE, 'month' => substr($nextDay, 0, 7)], GUESTS[1], 'guest');
    check('a day off disappears from the free days', $s === 200 && !in_array($nextDay, $b['days'] ?? [], true));
    [, $b] = api('myCalendar', [], GUIDE, 'guide');
    check('the guide sees their day off and their bookings', count($b['calendar']['daysOff'] ?? []) === 1 && count($b['bookings'] ?? []) >= 3);

    // Who may look; guides without a calendar.
    [$s] = api('guideCalendar', ['guideId' => GUIDE], 187, 'guest');
    check('a traveller who isn’t matched can’t see it', $s === 400);
    [$s, $b] = api('guideCalendar', ['guideId' => UNMATCHED_GUIDE], 187, 'guest');
    check('a guide without a calendar says so (free-form times)', $s === 200 && $b === ['hasCalendar' => false]);
} finally {
    $db->db_Execute('DELETE FROM `bookings` WHERE `guide_id` = ' . GUIDE . ' AND `created_at` >= "' . $started . '"');
    if (!$hadCalendar) {
        foreach (['guide_days_off', 'guide_weekly_hours', 'guide_calendars'] as $table) {
            $db->db_Execute('DELETE FROM `' . $table . '` WHERE `user_id` = ' . GUIDE);
        }
    }
    $db->db_Execute('DELETE FROM `notifications` WHERE `user_id` = ' . GUIDE . ' AND `created_at` >= "' . $started . '"');
    $db->db_Execute('DELETE FROM `email_outbox` WHERE `status` = "queued" AND `recipient` LIKE "%@example.test"');
}

echo $failures ? "{$failures} FAILED\n" : "ALL PASSED\n";
exit($failures ? 1 : 0);
