<?php

/**
 * The email outbox (`email_outbox`, 022). Services never talk to SMTP: they
 * queue a message here, inside the request, and the mail worker
 * (`mailer/bin/mail-worker.php`) sends it — retrying a failed send up to
 * three times before giving up and logging an error. A slow or broken mail
 * server therefore never holds up a request.
 *
 * The row lives in MySQL, not Redis, so a queued email survives a restart
 * and is written alongside the booking change it reports. Redis only wakes
 * the worker (`mail:wake`) so it needn't wait for its next poll; if Redis is
 * down the worker still finds the row within a few seconds.
 *
 * The subject and body can carry a login code or a reset link, so the worker
 * wipes both once a message is sent or abandoned; `tag` says what it was.
 */
class MailQueue
{
    public const WAKE_LIST = 'mail:wake';

    /** Login codes and reset links jump the queue — someone is waiting at a form. */
    public const PRIORITY_HIGH = 10;
    public const PRIORITY_NORMAL = 0;

    /** @var db */
    private $db;
    /** @var RedisClient|null */
    private $redis;

    public function __construct(db $db, ?RedisClient $redis = null)
    {
        $this->db = $db;
        $this->redis = $redis;
    }

    /**
     * Queues one plain-text email. `$tag` names the kind of message for the
     * logs (`login_code`, `booking_accepted`…). Never throws for a Redis
     * problem — the worker polls anyway.
     */
    public function enqueue(string $to, string $subject, string $body, string $tag, int $priority = self::PRIORITY_NORMAL): void
    {
        $id = $this->db->db_Insert('email_outbox', [
            'recipient' => mb_substr($to, 0, 255),
            'subject' => mb_substr($subject, 0, 255),
            'body' => $body,
            'tag' => mb_substr($tag, 0, 64),
            'priority' => $priority,
            'next_attempt_at' => gmdate('Y-m-d H:i:s'),
            'created_at' => gmdate('Y-m-d H:i:s'),
        ]);
        if (!$id) {
            Log::error('Could not queue an email', ['tag' => $tag, 'to' => Log::maskEmail($to)]);
            return;
        }

        if ($this->redis) {
            try {
                $this->redis->pipeline([['LPUSH', self::WAKE_LIST, (string) $id], ['LTRIM', self::WAKE_LIST, '0', '99']]);
            } catch (Throwable $e) {
                Log::warning('Could not wake the mail worker', ['error' => $e->getMessage()]);
            }
        }
    }
}
