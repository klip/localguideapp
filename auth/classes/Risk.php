<?php

/**
 * How unusual a sign-in looks, and accounts that look like the same person
 * (docs/auth-cms-plan.md, phase 4).
 *
 * `assess()` scores an attempt from what's known about the account:
 *
 *   +30  a browser this account has never used (its device cookie)
 *   +15  a fingerprint it has never used (`Devices`' keyed hashes)
 *   +30  a country it has never signed in from (only with IP → place on)
 *   +20  3 or more wrong passwords in the last hour
 *
 * Browser and fingerprint only count once the account has some history — a
 * first sign-in after this feature shipped mustn't look like an intruder.
 * At `STEP_UP` the sign-in needs an emailed code, even with two-factor off or
 * a remembered browser; at `NOTIFY` the owner gets a "new sign-in — not you?"
 * email afterwards (`AccountSecurity::notifyNewSignIn()`).
 *
 * Not yet (they need real geolocation — GeoLite2): impossible travel, a
 * hosting/VPN network, a time zone that doesn't match the IP's country.
 */
class Risk
{
    public const NOTIFY = 30;
    public const STEP_UP = 40;
    private const FAILED_PASSWORDS = 3;
    /** Fingerprints from fewer details than this (a script, a bare request) prove nothing. */
    private const MIN_COMPONENTS = 5;
    private const LINK_WINDOW_DAYS = 30;

    /** @var db */
    private $db;

    public function __construct(db $db)
    {
        $this->db = $db;
    }

    /**
     * `$device` is `Devices::describe()`'s result for the browser asking.
     *
     * @return array{score: int, reasons: string[]}
     */
    public function assess(int $userId, string $deviceHash, array $device): array
    {
        $reasons = [];
        $hasHistory = (int) $this->db->db_GetFieldFromQuery(
            'SELECT COUNT(*) AS n FROM `devices` WHERE `user_id` = ' . $userId, 'n'
        ) > 0;

        if ($hasHistory) {
            $knownDevice = (int) $this->db->db_GetFieldFromQuery(
                'SELECT COUNT(*) AS n FROM `devices` WHERE `user_id` = ' . $userId
                . ' AND `device_id` = "' . $this->db->db_Escape($deviceHash) . '"', 'n'
            ) > 0;
            if (!$knownDevice) {
                $reasons['new_device'] = 30;
            }
            if (count($device['components'] ?? []) >= self::MIN_COMPONENTS) {
                $knownFingerprint = (int) $this->db->db_GetFieldFromQuery(
                    'SELECT COUNT(*) AS n FROM `devices` WHERE `user_id` = ' . $userId
                    . ' AND `fingerprint_hash` = "' . $this->db->db_Escape((string) $device['fingerprint']) . '"', 'n'
                ) > 0;
                if (!$knownFingerprint) {
                    $reasons['new_fingerprint'] = 15;
                }
            }
        }

        $country = $device['country'] ?? null;
        if ($country !== null) {
            $countries = array_column((array) $this->db->db_GetArray(
                'SELECT DISTINCT `country` FROM `login_events`
                 WHERE `user_id` = ' . $userId . ' AND `country` IS NOT NULL
                   AND `event` IN ("register", "login", "two_factor_ok", "refresh")'
            ), 'country');
            if ($countries && !in_array($country, $countries, true)) {
                $reasons['new_country'] = 30;
            }
        }

        $failed = (int) $this->db->db_GetFieldFromQuery(
            'SELECT COUNT(*) AS n FROM `login_events`
             WHERE `user_id` = ' . $userId . ' AND `event` = "login_failed"
               AND `created_at` > UTC_TIMESTAMP() - INTERVAL 1 HOUR', 'n'
        );
        if ($failed >= self::FAILED_PASSWORDS) {
            $reasons['failed_passwords'] = 20;
        }

        return ['score' => (int) array_sum($reasons), 'reasons' => array_keys($reasons)];
    }

    /**
     * Records which other accounts this browser connects `$userId` to, after a
     * sign-in or registration: the same device cookie (strong), the same
     * address and fingerprint within 30 days, or the same fingerprint. Only
     * flagged — staff decide (`account_links.verdict`).
     *
     * @return int how many links were new or seen again
     */
    public function linkAccounts(int $userId, string $deviceHash, array $device): int
    {
        $found = [];
        foreach ($this->column(
            'SELECT DISTINCT `user_id` FROM `devices` WHERE `device_id` = "' . $this->db->db_Escape($deviceHash) . '"'
            . ' AND `user_id` <> ' . $userId
        ) as $other) {
            $found[] = [$other, 'device', 3];
        }

        if (count($device['components'] ?? []) >= self::MIN_COMPONENTS) {
            $fingerprint = $this->db->db_Escape((string) $device['fingerprint']);
            $ip = (string) ($device['ip'] ?? '');
            if ($ip !== '') {
                foreach ($this->column(
                    'SELECT DISTINCT `user_id` FROM `login_events`
                     WHERE `fingerprint_hash` = "' . $fingerprint . '" AND `ip` = "' . $this->db->db_Escape($ip) . '"
                       AND `user_id` IS NOT NULL AND `user_id` <> ' . $userId . '
                       AND `event` IN ("register", "login", "two_factor_ok")
                       AND `created_at` > UTC_TIMESTAMP() - INTERVAL ' . self::LINK_WINDOW_DAYS . ' DAY'
                ) as $other) {
                    $found[] = [$other, 'ip_fingerprint', 2];
                }
            }
            foreach ($this->column(
                'SELECT DISTINCT `user_id` FROM `devices`
                 WHERE `fingerprint_hash` = "' . $fingerprint . '" AND `user_id` <> ' . $userId . '
                   AND `last_seen_at` > UTC_TIMESTAMP() - INTERVAL ' . self::LINK_WINDOW_DAYS . ' DAY'
            ) as $other) {
                $found[] = [$other, 'fingerprint', 1];
            }
        }

        foreach ($found as [$other, $signal, $strength]) {
            [$a, $b] = $userId < $other ? [$userId, $other] : [$other, $userId];
            $this->db->db_Execute(
                'INSERT INTO `account_links` (`user_a`, `user_b`, `signal`, `strength`, `first_seen_at`, `last_seen_at`)
                 VALUES (' . $a . ', ' . $b . ', "' . $signal . '", ' . $strength . ', UTC_TIMESTAMP(), UTC_TIMESTAMP())
                 ON DUPLICATE KEY UPDATE `hits` = `hits` + 1, `last_seen_at` = UTC_TIMESTAMP()'
            );
        }
        if ($found) {
            Log::info('Accounts linked', ['user_id' => $userId, 'links' => count($found)]);
        }

        return count($found);
    }

    /** @return int[] */
    private function column(string $sql): array
    {
        return array_map('intval', array_column((array) $this->db->db_GetArray($sql), 'user_id'));
    }
}
