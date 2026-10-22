<?php

/**
 * Guides' availability calendars (docs/availability-plan.md, C1/C2):
 * when a guide works, and so which times a visitor may book.
 *
 * - A calendar (`guide_calendars`, 086) is weekly working windows
 *   (`guide_weekly_hours`) plus dated exceptions (`guide_days_off`: a day or
 *   window off, or an extra window), all in the guide's own time zone.
 * - **Busy time is derived**: every booking that holds a slot — pending, or
 *   confirmed and not finished — occupies its meeting time for its length
 *   (or the calendar's default), with `buffer_minutes` kept free either side.
 * - A guide **without a calendar** books as before: any future time
 *   (`Bookings::pay()` checks nothing here).
 * - The arithmetic is in pure static functions (`windowsOn()`, `startsIn()`,
 *   `fits()`) so it's tested without a database
 *   (lib/tests/AvailabilityTest.php); local times go through
 *   `DateTimeImmutable` per date, so daylight-saving changes come out right.
 */
final class Availability
{
    public const SLOT_CHOICES = [15, 30, 60];
    public const MIN_TRIP_HOURS = 0.5;
    public const MAX_TRIP_HOURS = 24.0;
    private const MAX_WINDOWS_PER_DAY = 6;

    /** @var db */
    private $db;

    public function __construct(db $db)
    {
        $this->db = $db;
    }

    // ------------------------------------------------------------- pure

    /**
     * The working windows on local date `$date` (`YYYY-MM-DD`) as UTC
     * timestamps `[[start, end], …]`, sorted and merged. `$calendar`:
     * `timeZone`, `weekly` (ISO weekday → `[[startMinute, endMinute], …]`),
     * `exceptions` (date → `[{ kind: off|extra, start: ?int, end: ?int }]`).
     * An all-day `off` clears the weekly hours; partial `off`s are cut out;
     * `extra` windows are added last (so they win over an `off`).
     */
    public static function windowsOn(array $calendar, string $date): array
    {
        $zone = new DateTimeZone($calendar['timeZone']);
        $midnight = new DateTimeImmutable($date . ' 00:00:00', $zone);
        $spans = $calendar['weekly'][(int) $midnight->format('N')] ?? [];

        $extras = [];
        foreach ($calendar['exceptions'][$date] ?? [] as $exception) {
            if ($exception['kind'] === 'extra') {
                $extras[] = [(int) $exception['start'], (int) $exception['end']];
            } elseif ($exception['start'] === null) {
                $spans = [];
            } else {
                $spans = self::subtract($spans, [(int) $exception['start'], (int) $exception['end']]);
            }
        }
        $spans = self::merge(array_merge($spans, $extras));

        $at = static function (int $minute) use ($midnight): int {
            return $minute >= 1440
                ? $midnight->modify('+1 day')->getTimestamp()
                : $midnight->setTime(intdiv($minute, 60), $minute % 60)->getTimestamp();
        };
        $windows = [];
        foreach ($spans as [$start, $end]) {
            $from = $at($start);
            $to = $at($end);
            if ($to > $from) {
                $windows[] = [$from, $to];
            }
        }

        return $windows;
    }

    /**
     * Start times (UTC timestamps) in `$windows` on a `$slot`-second grid
     * from each window's start, where a trip of `$duration` seconds ends by
     * the window's end, starts between `$earliest` and `$latest`, and keeps
     * `$buffer` seconds clear of every busy `[start, end]`.
     */
    public static function startsIn(array $windows, array $busy, int $duration, int $slot, int $buffer, int $earliest, int $latest): array
    {
        $starts = [];
        foreach ($windows as [$from, $to]) {
            $first = $from + max(0, (int) ceil(($earliest - $from) / $slot)) * $slot;
            for ($start = $first; $start + $duration <= $to && $start <= $latest; $start += $slot) {
                if (self::clear($start, $duration, $buffer, $busy)) {
                    $starts[] = $start;
                }
            }
        }
        sort($starts);

        return array_values(array_unique($starts));
    }

    /**
     * Whether a trip at `$start` for `$duration` seconds fits: inside one of
     * `$windows`, between `$earliest` and `$latest`, clear of `$busy` (with
     * the buffer). Not tied to the slot grid — the grid is only what the
     * picker offers.
     */
    public static function fits(array $windows, array $busy, int $start, int $duration, int $buffer, int $earliest, int $latest): bool
    {
        if ($start < $earliest || $start > $latest) {
            return false;
        }
        foreach ($windows as [$from, $to]) {
            if ($start >= $from && $start + $duration <= $to) {
                return self::clear($start, $duration, $buffer, $busy);
            }
        }

        return false;
    }

    private static function clear(int $start, int $duration, int $buffer, array $busy): bool
    {
        foreach ($busy as [$from, $to]) {
            if ($start - $buffer < $to && $start + $duration + $buffer > $from) {
                return false;
            }
        }

        return true;
    }

    /** @param array<int, array{0: int, 1: int}> $spans */
    private static function merge(array $spans): array
    {
        usort($spans, static fn(array $a, array $b): int => $a[0] <=> $b[0]);
        $out = [];
        foreach ($spans as [$start, $end]) {
            if ($end <= $start) {
                continue;
            }
            $last = count($out) - 1;
            if ($last >= 0 && $start <= $out[$last][1]) {
                $out[$last][1] = max($out[$last][1], $end);
            } else {
                $out[] = [$start, $end];
            }
        }

        return $out;
    }

    /** `$spans` minus `[$cutStart, $cutEnd]`. */
    private static function subtract(array $spans, array $cut): array
    {
        $out = [];
        foreach ($spans as [$start, $end]) {
            if ($cut[1] <= $start || $cut[0] >= $end) {
                $out[] = [$start, $end];
                continue;
            }
            if ($cut[0] > $start) {
                $out[] = [$start, $cut[0]];
            }
            if ($cut[1] < $end) {
                $out[] = [$cut[1], $end];
            }
        }

        return $out;
    }

    // ------------------------------------------------------------- reading

    /**
     * `$guideId`'s calendar in the shape `windowsOn()` takes, plus its
     * settings — or null when they haven't set one up.
     */
    public function calendarFor(int $guideId): ?array
    {
        $row = $this->db->db_GetRow('SELECT * FROM `guide_calendars` WHERE `user_id` = ' . $guideId);
        if (!$row) {
            return null;
        }
        $weekly = [];
        foreach ((array) $this->db->db_GetArray(
            'SELECT `weekday`, `start_minute`, `end_minute` FROM `guide_weekly_hours` WHERE `user_id` = ' . $guideId . ' ORDER BY `weekday`, `start_minute`'
        ) as $w) {
            $weekly[(int) $w['weekday']][] = [(int) $w['start_minute'], (int) $w['end_minute']];
        }
        $exceptions = [];
        $list = [];
        foreach ((array) $this->db->db_GetArray(
            'SELECT * FROM `guide_days_off` WHERE `user_id` = ' . $guideId . ' AND `day` >= UTC_DATE() - INTERVAL 1 DAY ORDER BY `day`, `start_minute`'
        ) as $d) {
            $item = [
                'kind' => (string) $d['kind'],
                'start' => $d['start_minute'] !== null ? (int) $d['start_minute'] : null,
                'end' => $d['end_minute'] !== null ? (int) $d['end_minute'] : null,
            ];
            $exceptions[(string) $d['day']][] = $item;
            $list[] = ['id' => (int) $d['id'], 'day' => (string) $d['day'], 'note' => $d['note']] + $item;
        }

        return [
            'timeZone' => (string) $row['time_zone'],
            'slotMinutes' => (int) $row['slot_minutes'],
            'bufferMinutes' => (int) $row['buffer_minutes'],
            'defaultTripHours' => (float) $row['default_trip_hours'],
            'minNoticeHours' => (int) $row['min_notice_hours'],
            'maxDaysAhead' => (int) $row['max_days_ahead'],
            'weekly' => $weekly,
            'exceptions' => $exceptions,
            'daysOff' => $list,
        ];
    }

    /**
     * The guide's booked time between two timestamps, `[[start, end], …]`:
     * pending and unfinished confirmed bookings, each for its length or the
     * calendar's default. `$exceptBookingId` leaves one out (re-timing it).
     */
    public function busy(int $guideId, int $from, int $to, float $defaultHours, ?int $exceptBookingId = null): array
    {
        $rows = (array) $this->db->db_GetArray(
            'SELECT UNIX_TIMESTAMP(`meeting_at`) AS `start`, COALESCE(`duration_hours`, ' . sprintf('%.2F', $defaultHours) . ') AS `hours`
             FROM `bookings`
             WHERE `guide_id` = ' . $guideId . ' AND `meeting_at` IS NOT NULL
               AND (`status` = "pending" OR (`status` = "confirmed" AND `trip_status` <> "finished"))
               AND `meeting_at` < FROM_UNIXTIME(' . ($to + 86400) . ')
               AND `meeting_at` > FROM_UNIXTIME(' . ($from - 86400) . ')'
            . ($exceptBookingId ? ' AND `id` <> ' . $exceptBookingId : '')
        );

        return array_map(static fn(array $r): array => [
            (int) $r['start'],
            (int) $r['start'] + (int) round((float) $r['hours'] * 3600),
        ], $rows);
    }

    /** Free start times on local date `$day` for a trip of `$hours`: `[ts, …]`. */
    public function startTimes(int $guideId, string $day, float $hours, ?int $now = null): array
    {
        $calendar = $this->calendarFor($guideId);
        if (!$calendar) {
            return [];
        }
        $windows = self::windowsOn($calendar, self::date($day));
        if (!$windows) {
            return [];
        }
        $now = $now ?? time();
        [$earliest, $latest] = self::bounds($calendar, $now);
        $busy = $this->busy($guideId, $windows[0][0], end($windows)[1], $calendar['defaultTripHours']);

        return self::startsIn($windows, $busy, self::seconds($hours), $calendar['slotMinutes'] * 60,
            $calendar['bufferMinutes'] * 60, $earliest, $latest);
    }

    /** The local dates in month `$month` (`YYYY-MM`) with at least one free start for `$hours`. */
    public function daysWithTime(int $guideId, string $month, float $hours, ?int $now = null): array
    {
        $calendar = $this->calendarFor($guideId);
        if (!$calendar) {
            return [];
        }
        if (!preg_match('/^\d{4}-(0[1-9]|1[0-2])$/', $month)) {
            throw new RuntimeException('A month is YYYY-MM.');
        }
        $zone = new DateTimeZone($calendar['timeZone']);
        $first = new DateTimeImmutable($month . '-01 00:00:00', $zone);
        $next = $first->modify('first day of next month');
        $now = $now ?? time();
        [$earliest, $latest] = self::bounds($calendar, $now);
        $busy = $this->busy($guideId, $first->getTimestamp(), $next->getTimestamp(), $calendar['defaultTripHours']);
        $duration = self::seconds($hours);

        $days = [];
        for ($d = $first; $d < $next; $d = $d->modify('+1 day')) {
            $windows = self::windowsOn($calendar, $d->format('Y-m-d'));
            if ($windows && end($windows)[1] > $earliest && $windows[0][0] <= $latest
                && self::startsIn($windows, $busy, $duration, $calendar['slotMinutes'] * 60, $calendar['bufferMinutes'] * 60, $earliest, $latest)) {
                $days[] = $d->format('Y-m-d');
            }
        }

        return $days;
    }

    /** Whether `$guideId` is free at `$start` for `$hours` — what `Bookings::pay()` checks, under the guide's lock. */
    public function isFree(int $guideId, int $start, float $hours, ?int $exceptBookingId = null, ?int $now = null): bool
    {
        $calendar = $this->calendarFor($guideId);
        if (!$calendar) {
            return true;
        }
        $day = (new DateTimeImmutable('@' . $start))->setTimezone(new DateTimeZone($calendar['timeZone']))->format('Y-m-d');
        $windows = self::windowsOn($calendar, $day);
        [$earliest, $latest] = self::bounds($calendar, $now ?? time());
        $duration = self::seconds($hours);
        $busy = $this->busy($guideId, $start, $start + $duration, $calendar['defaultTripHours'], $exceptBookingId);

        return self::fits($windows, $busy, $start, $duration, $calendar['bufferMinutes'] * 60, $earliest, $latest);
    }

    /**
     * The guide's own view (`myCalendar`): their calendar (null if none yet),
     * the hours they set on their profile as a starting point, and their
     * bookings from `$from` to `$to` (local dates) for the month view.
     */
    public function viewFor(int $guideId, string $from, string $to): array
    {
        $calendar = $this->calendarFor($guideId);
        $zone = new DateTimeZone($calendar['timeZone'] ?? 'UTC');
        $start = (new DateTimeImmutable(self::date($from) . ' 00:00:00', $zone))->getTimestamp();
        $end = (new DateTimeImmutable(self::date($to) . ' 00:00:00', $zone))->modify('+1 day')->getTimestamp();
        if ($end <= $start || $end - $start > 62 * 86400) {
            throw new RuntimeException('Ask for up to two months at a time.');
        }

        $bookings = (array) $this->db->db_GetArray(
            'SELECT b.`id`, b.`status`, b.`trip_status`, UNIX_TIMESTAMP(b.`meeting_at`) AS `start`, b.`duration_hours`,
                    u.`id` AS `guest_id`, u.`name` AS `guest_name`
             FROM `bookings` b JOIN `users` u ON u.`id` = b.`guest_id`
             WHERE b.`guide_id` = ' . $guideId . ' AND b.`meeting_at` IS NOT NULL
               AND b.`status` IN ("pending", "confirmed")
               AND b.`meeting_at` >= FROM_UNIXTIME(' . $start . ') AND b.`meeting_at` < FROM_UNIXTIME(' . $end . ')
             ORDER BY b.`meeting_at`'
        );
        // Their profile hours (`hour_of_day`), offered as weekly hours to start from.
        $suggested = array_map(static fn(array $r): array => [(int) $r['range_start'] * 60, min(1440, (int) $r['range_end'] * 60)], (array) $this->db->db_GetArray(
            'SELECT r.`range_start`, r.`range_end` FROM `user_attribute_ranges` r
             JOIN `attribute_categories` c ON c.`id` = r.`category_id` AND c.`key` = "hour_of_day"
             WHERE r.`user_id` = ' . $guideId . ' AND r.`role` IN ("any", "guide") ORDER BY r.`range_start`'
        ));

        if ($calendar) {
            unset($calendar['exceptions']);
            $calendar['weekly'] = (object) $calendar['weekly'];
        }

        return [
            'calendar' => $calendar,
            'suggestedHours' => $suggested,
            'bookings' => array_map(static fn(array $b): array => [
                'id' => (int) $b['id'],
                'status' => (string) $b['status'],
                'tripStatus' => (string) $b['trip_status'],
                'start' => gmdate('Y-m-d\TH:i:s\Z', (int) $b['start']),
                'hours' => $b['duration_hours'] !== null ? (float) $b['duration_hours'] : null,
                'guest' => ['id' => (int) $b['guest_id'], 'name' => (string) ($b['guest_name'] ?? '')],
            ], $bookings),
        ];
    }

    // ------------------------------------------------------------- writing

    /**
     * Creates or replaces the guide's calendar: settings, and the whole
     * weekly hours (`weekly: [{ weekday, start, end }]`, minutes). Field
     * errors are `{ field, error }` JSON.
     */
    public function save(int $guideId, array $input): void
    {
        $zone = trim((string) ($input['timeZone'] ?? ''));
        if (!in_array($zone, DateTimeZone::listIdentifiers(), true)) {
            throw self::fieldError('timeZone', 'Pick your time zone from the list.');
        }
        $slot = (int) ($input['slotMinutes'] ?? 30);
        if (!in_array($slot, self::SLOT_CHOICES, true)) {
            throw self::fieldError('slotMinutes', 'Every 15, 30 or 60 minutes.');
        }
        $buffer = self::intIn($input['bufferMinutes'] ?? 30, 0, 240, 'bufferMinutes', 'From 0 to 240 minutes.');
        $default = self::hours($input['defaultTripHours'] ?? 3, 'defaultTripHours');
        $notice = self::intIn($input['minNoticeHours'] ?? 12, 0, 336, 'minNoticeHours', 'From 0 to 336 hours (two weeks).');
        $ahead = self::intIn($input['maxDaysAhead'] ?? 180, 1, 365, 'maxDaysAhead', 'From 1 to 365 days.');

        $byDay = [];
        foreach ((array) ($input['weekly'] ?? []) as $window) {
            $day = (int) ($window['weekday'] ?? 0);
            $start = (int) ($window['start'] ?? -1);
            $end = (int) ($window['end'] ?? -1);
            if ($day < 1 || $day > 7 || $start < 0 || $end > 1440 || $start >= $end || $start % 5 || $end % 5) {
                throw self::fieldError('weekly', 'Each window needs a start before its end, within the day.');
            }
            $byDay[$day][] = [$start, $end];
        }
        foreach ($byDay as $day => $windows) {
            if (count($windows) > self::MAX_WINDOWS_PER_DAY) {
                throw self::fieldError('weekly', 'At most ' . self::MAX_WINDOWS_PER_DAY . ' windows a day.');
            }
            if (count(self::merge($windows)) !== count($windows)) {
                throw self::fieldError('weekly', 'Two windows on the same day overlap — merge them.');
            }
        }

        $this->db->db_Execute('START TRANSACTION');
        try {
            $this->exec(
                'INSERT INTO `guide_calendars` (`user_id`, `time_zone`, `slot_minutes`, `buffer_minutes`, `default_trip_hours`, `min_notice_hours`, `max_days_ahead`)
                 VALUES (' . $guideId . ', "' . $this->db->db_Escape($zone) . '", ' . $slot . ', ' . $buffer . ', ' . sprintf('%.1F', $default) . ', ' . $notice . ', ' . $ahead . ')
                 ON DUPLICATE KEY UPDATE `time_zone` = VALUES(`time_zone`), `slot_minutes` = VALUES(`slot_minutes`),
                   `buffer_minutes` = VALUES(`buffer_minutes`), `default_trip_hours` = VALUES(`default_trip_hours`),
                   `min_notice_hours` = VALUES(`min_notice_hours`), `max_days_ahead` = VALUES(`max_days_ahead`)'
            );
            $this->exec('DELETE FROM `guide_weekly_hours` WHERE `user_id` = ' . $guideId);
            $values = [];
            foreach ($byDay as $day => $windows) {
                foreach ($windows as [$start, $end]) {
                    $values[] = '(' . $guideId . ', ' . $day . ', ' . $start . ', ' . $end . ')';
                }
            }
            if ($values) {
                $this->exec('INSERT INTO `guide_weekly_hours` (`user_id`, `weekday`, `start_minute`, `end_minute`) VALUES ' . implode(', ', $values));
            }
            $this->db->db_Execute('COMMIT');
        } catch (Throwable $e) {
            $this->db->db_Execute('ROLLBACK');
            throw $e;
        }
    }

    /** A day off (all day, or `start`–`end`), or an `extra` window, on a local date from today on. */
    public function addException(int $guideId, array $input): void
    {
        if (!$this->db->db_GetRow('SELECT `user_id` FROM `guide_calendars` WHERE `user_id` = ' . $guideId)) {
            throw new RuntimeException('Save your weekly hours first.');
        }
        $day = self::date((string) ($input['day'] ?? ''));
        if ($day < gmdate('Y-m-d', time() - 86400)) {
            throw self::fieldError('day', 'A day from today on.');
        }
        $kind = (string) ($input['kind'] ?? 'off');
        if (!in_array($kind, ['off', 'extra'], true)) {
            throw self::fieldError('kind', 'Off, or extra hours.');
        }
        $start = $input['start'] ?? null;
        $end = $input['end'] ?? null;
        $whole = ($start === null || $start === '') && ($end === null || $end === '');
        if ($kind === 'extra' && $whole) {
            throw self::fieldError('start', 'Extra hours need a start and an end.');
        }
        if (!$whole && (!is_numeric($start) || !is_numeric($end) || (int) $start < 0 || (int) $end > 1440 || (int) $start >= (int) $end)) {
            throw self::fieldError('start', 'A start before the end, within the day.');
        }
        $note = trim((string) ($input['note'] ?? ''));
        if (mb_strlen($note) > 120) {
            throw self::fieldError('note', 'At most 120 characters.');
        }
        $this->exec(
            'INSERT INTO `guide_days_off` (`user_id`, `day`, `kind`, `start_minute`, `end_minute`, `note`) VALUES ('
            . $guideId . ', "' . $day . '", "' . $kind . '", ' . ($whole ? 'NULL, NULL' : (int) $start . ', ' . (int) $end) . ', '
            . ($note === '' ? 'NULL' : '"' . $this->db->db_Escape($note) . '"') . ')'
        );
    }

    public function removeException(int $guideId, int $id): void
    {
        $this->exec('DELETE FROM `guide_days_off` WHERE `id` = ' . $id . ' AND `user_id` = ' . $guideId);
    }

    // ------------------------------------------------------------- helpers

    /** `[earliest, latest]` start times: now + the minimum notice, now + the days ahead. */
    private static function bounds(array $calendar, int $now): array
    {
        return [$now + $calendar['minNoticeHours'] * 3600, $now + $calendar['maxDaysAhead'] * 86400];
    }

    public static function seconds(float $hours): int
    {
        return (int) round($hours * 3600);
    }

    /** A trip length in hours: 0.5–24 in half hours. */
    public static function hours($value, string $field = 'durationHours'): float
    {
        if (!is_numeric($value) || (float) $value < self::MIN_TRIP_HOURS || (float) $value > self::MAX_TRIP_HOURS
            || fmod((float) $value * 2, 1.0) !== 0.0) {
            throw self::fieldError($field, 'From half an hour to 24 hours, in half hours.');
        }

        return (float) $value;
    }

    private static function date(string $value): string
    {
        if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $value) || !strtotime($value)) {
            throw self::fieldError('day', 'A date, YYYY-MM-DD.');
        }

        return $value;
    }

    private static function intIn($value, int $min, int $max, string $field, string $message): int
    {
        if (!is_numeric($value) || (int) $value < $min || (int) $value > $max) {
            throw self::fieldError($field, $message);
        }

        return (int) $value;
    }

    private function exec(string $sql): void
    {
        if (!$this->db->db_Execute($sql)) {
            throw new RuntimeException('Could not save your calendar.');
        }
    }

    private static function fieldError(string $field, string $error): RuntimeException
    {
        return new RuntimeException((string) json_encode(['field' => $field, 'error' => $error], JSON_UNESCAPED_UNICODE));
    }
}
