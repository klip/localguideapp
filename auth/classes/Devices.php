<?php

/**
 * The browsers an account signs in from, and the sign-in history
 * (docs/auth-cms-plan.md, phase 3; tables `devices`, `login_events`, 072).
 *
 * A browser is its `__Host-rg_device` cookie (`AuthHttp`); what it says about
 * itself (`device` in the sign-in and refresh requests — browser, system,
 * screen, time zone, languages, CPU and memory class; never canvas/WebGL) is
 * stored **one keyed hash per component** (HMAC-SHA256 with the server's
 * `FINGERPRINT_KEY`): one-way, and matchable only with the key — enough to
 * tell "same browser, updated" from "a different one", which a single hash of
 * everything couldn't. Plain text is kept only for what the user is shown on
 * their own list: a label ("Chrome on macOS") and the place.
 */
class Devices
{
    /** The components the fingerprint combines — not versions, which change monthly. */
    private const STABLE = ['browser', 'os', 'model', 'mobile', 'screen', 'dpr', 'colorDepth', 'touchPoints', 'timeZone', 'languages', 'cores', 'memory'];

    /** @var db */
    private $db;
    /** @var GeoIp */
    private $geo;

    public function __construct(db $db, GeoIp $geo)
    {
        $this->db = $db;
        $this->geo = $geo;
    }

    /**
     * What the browser reported, cleaned up: known fields only, short strings,
     * numbers in range. Missing fields fall back to the User-Agent where it can.
     *
     * @return array<string, string|int|float|bool>
     */
    public static function normalize($device, string $userAgent): array
    {
        $device = is_array($device) ? $device : [];
        $text = static function ($value, int $max): ?string {
            if (!is_string($value)) {
                return null;
            }
            $value = trim((string) preg_replace('/[\x00-\x1F\x7F]+/u', ' ', $value));

            return $value === '' ? null : mb_substr($value, 0, $max);
        };
        $int = static function ($value, int $min, int $max): ?int {
            return is_int($value) || (is_float($value) && floor($value) === $value)
                ? max($min, min($max, (int) $value))
                : null;
        };
        $parsed = self::parseUserAgent($userAgent);

        $screen = is_array($device['screen'] ?? null) ? $device['screen'] : [];
        $width = $int($screen['width'] ?? null, 1, 20000);
        $height = $int($screen['height'] ?? null, 1, 20000);
        $languages = array_slice(array_values(array_filter(array_map(
            static fn($l) => is_string($l) && preg_match('/^[A-Za-z]{2,3}(-[A-Za-z0-9]{1,8})*$/', $l) ? $l : null,
            is_array($device['languages'] ?? null) ? $device['languages'] : []
        ))), 0, 5);
        $dpr = is_numeric($device['dpr'] ?? null) ? round(max(0.5, min(8, (float) $device['dpr'])), 2) : null;
        $memory = is_numeric($device['memory'] ?? null) ? max(0.25, min(64, (float) $device['memory'])) : null;

        return array_filter([
            'browser' => $text($device['browser'] ?? null, 40) ?? $parsed['browser'],
            'browserVersion' => $int($device['browserVersion'] ?? null, 0, 999) ?? $parsed['browserVersion'],
            'os' => $text($device['os'] ?? null, 40) ?? $parsed['os'],
            'osVersion' => $text($device['osVersion'] ?? null, 20),
            'model' => $text($device['model'] ?? null, 60),
            'mobile' => is_bool($device['mobile'] ?? null) ? $device['mobile'] : null,
            'screen' => $width && $height ? $width . 'x' . $height : null,
            'dpr' => $dpr,
            'colorDepth' => $int($device['colorDepth'] ?? null, 1, 64),
            'touchPoints' => $int($device['touchPoints'] ?? null, 0, 64),
            'timeZone' => is_string($device['timeZone'] ?? null) && preg_match('#^[A-Za-z_]+(/[A-Za-z0-9_+-]+){0,2}$#', $device['timeZone']) ? $device['timeZone'] : null,
            'languages' => $languages ? implode(',', $languages) : null,
            'cores' => $int($device['cores'] ?? null, 1, 256),
            'memory' => $memory,
        ], static fn($value): bool => $value !== null);
    }

    /** "Chrome on macOS", "Safari on iPhone", "Firefox" — what the user sees. */
    public static function label(array $info): string
    {
        $browser = (string) ($info['browser'] ?? '');
        $system = (string) ($info['model'] ?? '') ?: (string) ($info['os'] ?? '');

        return mb_substr(trim($browser !== '' && $system !== '' ? "{$browser} on {$system}" : $browser . $system), 0, 80);
    }

    /**
     * Records that this account used this browser now — creating or updating
     * its `devices` row — and returns the row id, the keyed hashes, and
     * whether the address or the fingerprint differs from last time.
     *
     * @return array{id: int, fingerprint: string, components: array<string, string>, ip: string, country: ?string, place: ?string, changed: bool}
     */
    public function touch(int $userId, string $deviceHash, array $info, string $ip): array
    {
        $described = $this->describe($info, $ip);
        [$components, $fingerprint] = [$described['components'], $described['fingerprint']];
        $geo = ['country' => $described['country'], 'place' => $described['place']];
        $label = self::label($info);

        $previous = $this->db->db_GetRow(
            'SELECT `id`, `fingerprint_hash`, `last_ip` FROM `devices`
             WHERE `user_id` = ' . $userId . ' AND `device_id` = "' . $this->db->db_Escape($deviceHash) . '"'
        );
        $now = '"' . gmdate('Y-m-d H:i:s') . '"';
        $set = [
            '`fingerprint_hash` = ' . $this->quote($fingerprint),
            '`components` = ' . $this->quote((string) json_encode($components)),
            '`label` = ' . $this->quote($label),
            '`last_seen_at` = ' . $now,
            '`last_ip` = ' . $this->quote($ip ?: null),
            '`last_country` = ' . $this->quote($geo['country']),
            '`last_place` = ' . $this->quote($geo['place']),
        ];

        if ($previous) {
            $id = (int) $previous['id'];
            $this->db->db_Execute('UPDATE `devices` SET ' . implode(', ', $set) . ' WHERE `id` = ' . $id);
        } else {
            $this->db->db_Execute(
                'INSERT INTO `devices` SET `user_id` = ' . $userId . ', `device_id` = ' . $this->quote($deviceHash)
                . ', `first_seen_at` = ' . $now . ', ' . implode(', ', $set)
                . ' ON DUPLICATE KEY UPDATE `id` = LAST_INSERT_ID(`id`), ' . implode(', ', $set)
            );
            $id = (int) $this->db->db_GetLastId();
        }

        return ['id' => $id] + $described + [
            'changed' => !$previous || $previous['fingerprint_hash'] !== $fingerprint || (string) $previous['last_ip'] !== $ip,
        ];
    }

    /**
     * The keyed hashes and the place for what a browser reported, without
     * storing anything — for an attempt that isn't a sign-in (a wrong
     * password), which mustn't add a device to anyone's account.
     *
     * @return array{fingerprint: string, components: array<string, string>, ip: string, country: ?string, place: ?string}
     */
    public function describe(array $info, string $ip): array
    {
        $geo = $this->geo->lookup($ip) ?? ['country' => null, 'place' => null];

        return [
            'fingerprint' => self::keyedFingerprint($info),
            'components' => self::keyedComponents($info),
            'ip' => $ip,
            'country' => $geo['country'],
            'place' => $geo['place'],
        ];
    }

    /**
     * One `login_events` row. `$device` is `touch()`'s result, or
     * `describe()`'s for an attempt that isn't a sign-in.
     */
    public function record(string $event, ?int $userId, ?array $device, string $deviceHash, string $ip, string $userAgent, ?string $email = null): void
    {
        $this->db->db_Execute(
            'INSERT INTO `login_events` SET `event` = ' . $this->quote($event)
            . ', `user_id` = ' . ($userId ? (int) $userId : 'NULL')
            . ', `email_hash` = ' . $this->quote($email !== null ? self::keyed('email', mb_strtolower(trim($email))) : null)
            . ', `device_row_id` = ' . (isset($device['id']) ? (int) $device['id'] : 'NULL')
            . ', `device_id` = ' . $this->quote($deviceHash ?: null)
            . ', `fingerprint_hash` = ' . $this->quote($device['fingerprint'] ?? null)
            . ', `components` = ' . $this->quote($device ? (string) json_encode($device['components']) : null)
            . ', `ip` = ' . $this->quote($ip ?: null)
            . ', `country` = ' . $this->quote($device['country'] ?? null)
            . ', `place` = ' . $this->quote($device['place'] ?? null)
            . ', `user_agent` = ' . $this->quote(mb_substr($userAgent, 0, 255))
            . ', `created_at` = "' . gmdate('Y-m-d H:i:s') . '"'
        );
    }

    /**
     * The browsers this account is signed in on now (a live refresh token),
     * most recently used first — "Your devices" on the profile page.
     *
     * @return array<int, array{id: int, label: string, place: ?string, country: ?string, last_seen_at: string, first_seen_at: string}>
     */
    public function signedInFor(int $userId): array
    {
        $rows = (array) $this->db->db_GetArray(
            'SELECT d.`id`, d.`label`, d.`last_place`, d.`last_country`,
                    UNIX_TIMESTAMP(d.`last_seen_at`) AS `last_seen`, UNIX_TIMESTAMP(d.`first_seen_at`) AS `first_seen`
             FROM `devices` d
             WHERE d.`user_id` = ' . $userId . '
               AND EXISTS (SELECT 1 FROM `refresh_tokens` rt
                           WHERE rt.`user_id` = d.`user_id` AND rt.`device_id` = d.`device_id`
                             AND rt.`revoked_at` IS NULL AND rt.`used_at` IS NULL
                             AND rt.`expires_at` > UTC_TIMESTAMP() AND rt.`absolute_expires_at` > UTC_TIMESTAMP())
             ORDER BY d.`last_seen_at` DESC'
        );

        return array_map(static fn(array $row): array => [
            'id' => (int) $row['id'],
            'label' => (string) $row['label'],
            'place' => $row['last_place'] !== null ? (string) $row['last_place'] : null,
            'country' => $row['last_country'] !== null ? (string) $row['last_country'] : null,
            'last_seen_at' => gmdate('Y-m-d\TH:i:s\Z', (int) $row['last_seen']),
            'first_seen_at' => gmdate('Y-m-d\TH:i:s\Z', (int) $row['first_seen']),
        ], $rows);
    }

    /** The `devices` row of this account and browser, if it has one. */
    public function idFor(int $userId, string $deviceHash): ?int
    {
        $row = $this->db->db_GetRow(
            'SELECT `id` FROM `devices` WHERE `user_id` = ' . $userId . ' AND `device_id` = "' . $this->db->db_Escape($deviceHash) . '"'
        );

        return $row ? (int) $row['id'] : null;
    }

    /** The `device_id` (cookie hash) of one of this account's devices, or null. */
    public function deviceHashOf(int $userId, int $deviceRowId): ?string
    {
        $row = $this->db->db_GetRow(
            'SELECT `device_id` FROM `devices` WHERE `id` = ' . $deviceRowId . ' AND `user_id` = ' . $userId
        );

        return $row ? (string) $row['device_id'] : null;
    }

    /** @return array<string, string> component → keyed hash */
    private static function keyedComponents(array $info): array
    {
        $hashes = [];
        foreach ($info as $name => $value) {
            $hashes[$name] = substr(self::keyed($name, self::scalar($value)), 0, 32);
        }
        ksort($hashes);

        return $hashes;
    }

    private static function keyedFingerprint(array $info): string
    {
        $parts = [];
        foreach (self::STABLE as $name) {
            $parts[] = $name . '=' . (isset($info[$name]) ? self::scalar($info[$name]) : '');
        }

        return self::keyed('fingerprint', implode("\n", $parts));
    }

    private static function keyed(string $name, string $value): string
    {
        $key = (string) (getenv('FINGERPRINT_KEY') ?: '');
        if ($key === '') {
            throw new LogicException('FINGERPRINT_KEY is not configured.');
        }

        return hash_hmac('sha256', $name . "\0" . $value, $key);
    }

    private static function scalar($value): string
    {
        return is_bool($value) ? ($value ? '1' : '0') : (string) $value;
    }

    /** @return array{browser: ?string, browserVersion: ?int, os: ?string} */
    private static function parseUserAgent(string $ua): array
    {
        $browser = null;
        $version = null;
        foreach ([['Edge', '#Edg(?:e|A|iOS)?/(\d+)#'], ['Opera', '#OPR/(\d+)#'], ['Samsung Internet', '#SamsungBrowser/(\d+)#'],
                  ['Firefox', '#(?:Firefox|FxiOS)/(\d+)#'], ['Chrome', '#(?:Chrome|CriOS)/(\d+)#'], ['Safari', '#Version/(\d+).*Safari/#']] as [$name, $pattern]) {
            if (preg_match($pattern, $ua, $m)) {
                [$browser, $version] = [$name, (int) $m[1]];
                break;
            }
        }
        $os = null;
        foreach ([['iPhone', '#iPhone#'], ['iPad', '#iPad#'], ['Android', '#Android#'], ['ChromeOS', '#CrOS#'],
                  ['Windows', '#Windows#'], ['macOS', '#Mac OS X|Macintosh#'], ['Linux', '#Linux#']] as [$name, $pattern]) {
            if (preg_match($pattern, $ua)) {
                $os = $name;
                break;
            }
        }

        return ['browser' => $browser, 'browserVersion' => $version, 'os' => $os];
    }

    private function quote($value): string
    {
        return $value === null ? 'NULL' : '"' . $this->db->db_Escape((string) $value) . '"';
    }
}
