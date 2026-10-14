<?php

/**
 * The mail worker (container `lemp-rg-mailer`): sends what the other services
 * queue in `email_outbox` — see `MailWorker` for retries and `MailQueue` for
 * the producer side. Safe to run several at once.
 *
 *   php /var/www/localguideapp/mailer/bin/mail-worker.php
 *
 * Needs DB_*, REDIS_* and the SMTP_* and MAIL_* settings in its environment. Long-running:
 * restart it after changing its PHP.
 */

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

require_once __DIR__.'/../../lib/bootstrap.php';
require_once __DIR__.'/../classes/Mailer.php';
require_once __DIR__.'/../classes/MailWorker.php';

Log::install('mailer');
set_time_limit(0);

(new MailWorker(new db(), new RedisClient(), new Mailer()))->run();
