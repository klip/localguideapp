<?php

/**
 * The scheduler (container `lemp-rg-scheduler`): runs `Bookings::sweep()`
 * every `SWEEP_EVERY_SECONDS` — expiring unanswered booking requests,
 * starting trips and finishing them on time, with their notifications and
 * emails — and pushes the resulting counter changes. Hourly it also trims
 * the sign-in history (`login_events`, 072): rows after 12 months, their IP
 * addresses after 90 days.
 *
 *   php /var/www/localguideapp/api/bin/scheduler.php
 *
 * Any number may run (two for availability, say): a Redis lock lets only one
 * sweep at a time. Long-running — restart it after changing the API's PHP.
 */

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

require_once __DIR__.'/../bootstrap.php';

Log::install('scheduler');
set_time_limit(0);

const SWEEP_EVERY_SECONDS = 30;
const LOCK_KEY = 'lock:bookings-sweep';
const PRUNE_EVERY_SECONDS = 3600;
$lastPrune = 0;

$db = new db();
$redis = new RedisClient();
$badges = new Badges($db);
$push = new Push($redis, $badges);
$notifications = new Notifications($db, new MailQueue($db, $redis), $push);
$bookings = new Bookings($db, $notifications, new Levels($db, $notifications));

Log::info('Scheduler started', ['every_seconds' => SWEEP_EVERY_SECONDS]);

while (true) {
    $started = microtime(true);

    try {
        // Held for most of an interval, so a second scheduler skips this
        // round rather than sweeping the same rows at the same moment.
        if ($redis->command('SET', LOCK_KEY, gethostname() ?: 'scheduler', 'NX', 'EX', (string) (SWEEP_EVERY_SECONDS - 5)) === 'OK') {
            $db->ensureConnected();
            $bookings->sweep();
            $push->flush();
            if (time() - $lastPrune >= PRUNE_EVERY_SECONDS) {
                $lastPrune = time();
                $db->db_Execute('DELETE FROM `login_events` WHERE `created_at` < UTC_TIMESTAMP() - INTERVAL 365 DAY LIMIT 5000');
                $db->db_Execute('UPDATE `login_events` SET `ip` = NULL WHERE `ip` IS NOT NULL AND `created_at` < UTC_TIMESTAMP() - INTERVAL 90 DAY LIMIT 5000');
            }
            Log::debug('Sweep done', ['ms' => (int) ((microtime(true) - $started) * 1000)]);
        }
    } catch (Throwable $e) {
        Log::exception('Sweep failed', $e);
    }

    sleep(max(1, SWEEP_EVERY_SECONDS - (int) (microtime(true) - $started)));
}
