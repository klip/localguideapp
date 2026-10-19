<?php

/**
 * The planner worker (container `lemp-rg-planner`): finds guides for the
 * trips travellers save (`TripPlanner::processNext()` — SQL, then Claude) and
 * pushes the result. Woken through Redis (`planner:wake`) when a plan is
 * saved, and polls every few seconds for retries. Safe to run several at once
 * (`FOR UPDATE SKIP LOCKED`).
 *
 *   php /var/www/localguideapp/api/bin/planner.php
 *
 * Needs DB_*, REDIS_* and ANTHROPIC_API_KEY (without it plans get the plain
 * SQL order, no explanations). Long-running — restart it after changing the
 * API's PHP.
 */

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

require_once __DIR__.'/../bootstrap.php';

Log::install('planner');
set_time_limit(0);

const POLL_SECONDS = 5;

$db = new db();
$redis = new RedisClient();
$push = new Push($redis, new Badges($db));
$planner = new TripPlanner($db, $redis, new Users($db), new Profiles($db), new Geocoder($redis), $push, new Ai());
// Explanations are masked for contact details, but nobody wrote them, so hits aren't held against a user.
ContactFilter::configure($db, null);

Log::info('Planner started', ['ai_configured' => (new Ai())->isConfigured()]);

while (true) {
    try {
        $db->ensureConnected();
        while ($planner->processNext()) {
            // Keep going while there's a queue.
        }
    } catch (Throwable $e) {
        Log::exception('Planner loop failed', $e);
        sleep(POLL_SECONDS);
    }

    try {
        if ($redis->blockingPop(TripPlanner::WAKE_LIST, POLL_SECONDS) !== null) {
            $redis->command('DEL', TripPlanner::WAKE_LIST);
        }
    } catch (Throwable $e) {
        sleep(POLL_SECONDS);
    }
}
