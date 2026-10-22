<?php

// The availability arithmetic (`api/classes/Availability.php`): windows from
// weekly hours and exceptions, free start times, and whether a booking fits —
// including daylight-saving days. No database.
//
//   docker exec lemp-php php /var/www/localguideapp/lib/tests/AvailabilityTest.php

require_once __DIR__ . '/../../api/classes/Availability.php';

$failures = 0;
function check(string $name, bool $ok, string $extra = ''): void
{
    global $failures;
    echo ($ok ? 'ok   ' : 'FAIL ') . $name . ($extra !== '' ? " — {$extra}" : '') . "\n";
    if (!$ok) {
        $failures++;
    }
}

const TZ = 'Europe/Lisbon';
/** A local time in Lisbon as a timestamp. */
function at(string $local): int
{
    return (new DateTimeImmutable($local, new DateTimeZone(TZ)))->getTimestamp();
}
/** Timestamps as "HH:MM" Lisbon time. */
function hhmm(array $timestamps): array
{
    return array_map(static fn(int $t): string => (new DateTimeImmutable('@' . $t))->setTimezone(new DateTimeZone(TZ))->format('H:i'), $timestamps);
}

$calendar = [
    'timeZone' => TZ,
    // Monday 09:00–13:00; Sunday 00:00–06:00 (for the DST days); Friday until midnight.
    'weekly' => [1 => [[540, 780]], 7 => [[0, 360]], 5 => [[1200, 1440]]],
    'exceptions' => [],
];
$monday = '2026-11-02';
$early = 0;
$late = PHP_INT_MAX;

// Windows.
$w = Availability::windowsOn($calendar, $monday);
check('a Monday has its window', $w === [[at("$monday 09:00"), at("$monday 13:00")]]);
check('a Tuesday has none', Availability::windowsOn($calendar, '2026-11-03') === []);
$w = Availability::windowsOn($calendar, '2026-11-06');
check('a window can run to midnight', $w === [[at('2026-11-06 20:00'), at('2026-11-07 00:00')]]);

$off = $calendar;
$off['exceptions'][$monday] = [['kind' => 'off', 'start' => null, 'end' => null]];
check('a day off clears it', Availability::windowsOn($off, $monday) === []);
$off['exceptions'][$monday][] = ['kind' => 'extra', 'start' => 900, 'end' => 960];
check('…but extra hours that day still count', Availability::windowsOn($off, $monday) === [[at("$monday 15:00"), at("$monday 16:00")]]);
$part = $calendar;
$part['exceptions'][$monday] = [['kind' => 'off', 'start' => 600, 'end' => 660]];
check('a window off splits the day', Availability::windowsOn($part, $monday) === [[at("$monday 09:00"), at("$monday 10:00")], [at("$monday 11:00"), at("$monday 13:00")]]);
$more = $calendar;
$more['exceptions'][$monday] = [['kind' => 'extra', 'start' => 720, 'end' => 840]];
check('extra hours join an overlapping window', Availability::windowsOn($more, $monday) === [[at("$monday 09:00"), at("$monday 14:00")]]);

// Daylight saving in Lisbon: 29 March 2026 the clocks go forward, 25 October back.
[[$from, $to]] = Availability::windowsOn($calendar, '2026-03-29');
check('the spring-forward Sunday’s 00:00–06:00 is 5 real hours', $to - $from === 5 * 3600, (string) (($to - $from) / 3600));
[[$from, $to]] = Availability::windowsOn($calendar, '2026-10-25');
check('the fall-back Sunday’s 00:00–06:00 is 7 real hours', $to - $from === 7 * 3600, (string) (($to - $from) / 3600));
$starts = hhmm(Availability::startsIn(Availability::windowsOn($calendar, '2026-03-29'), [], 3600, 3600, 0, $early, $late));
check('…and hourly starts skip the missing hour', $starts === ['00:00', '02:00', '03:00', '04:00', '05:00'], implode(',', $starts));

// Start times.
$w = Availability::windowsOn($calendar, $monday);
$starts = hhmm(Availability::startsIn($w, [], 2 * 3600, 1800, 1800, $early, $late));
check('2-hour trips start every half hour while they fit', $starts === ['09:00', '09:30', '10:00', '10:30', '11:00'], implode(',', $starts));
$busy = [[at("$monday 10:00"), at("$monday 12:00")]];
check('a booking with a 30-minute buffer leaves no room for 1 hour', Availability::startsIn($w, $busy, 3600, 1800, 1800, $early, $late) === []);
$starts = hhmm(Availability::startsIn($w, $busy, 1800, 1800, 1800, $early, $late));
check('…but half an hour fits either side', $starts === ['09:00', '12:30'], implode(',', $starts));
$starts = hhmm(Availability::startsIn($w, [], 3600, 1800, 0, at("$monday 10:10"), $late));
check('the minimum notice moves the first start to the next slot', $starts === ['10:30', '11:00', '11:30', '12:00'], implode(',', $starts));
$starts = hhmm(Availability::startsIn($w, [], 3600, 1800, 0, $early, at("$monday 10:00")));
check('nothing starts past the booking horizon', $starts === ['09:00', '09:30', '10:00'], implode(',', $starts));

// Fits.
check('an off-grid time inside the window fits', Availability::fits($w, [], at("$monday 09:10"), 3600, 0, $early, $late));
check('running past the window doesn’t', !Availability::fits($w, [], at("$monday 12:30"), 3600, 0, $early, $late));
check('outside every window doesn’t', !Availability::fits($w, [], at("$monday 14:00"), 1800, 0, $early, $late));
check('overlapping a booking doesn’t', !Availability::fits($w, $busy, at("$monday 09:30"), 3600, 0, $early, $late));
check('touching a booking without a buffer does, either side', Availability::fits($w, $busy, at("$monday 09:00"), 3600, 0, $early, $late)
    && Availability::fits($w, $busy, at("$monday 12:00"), 3600, 0, $early, $late));
check('…but not with a buffer', !Availability::fits($w, $busy, at("$monday 09:00"), 3600, 1800, $early, $late));
check('before the minimum notice doesn’t', !Availability::fits($w, [], at("$monday 09:00"), 3600, 0, at("$monday 09:30"), $late));

// Trip lengths.
check('half hours are accepted', Availability::hours('2.5') === 2.5);
foreach (['0', '0.25', '25', 'abc', '1.75'] as $bad) {
    $refused = false;
    try {
        Availability::hours($bad);
    } catch (RuntimeException $e) {
        $refused = true;
    }
    check("a trip length of {$bad} is refused", $refused);
}

echo $failures ? "{$failures} FAILED\n" : "All checks passed\n";
exit($failures ? 1 : 0);
