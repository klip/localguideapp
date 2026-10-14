<?php

/**
 * Drains the email outbox (`email_outbox`, see `MailQueue`).
 *
 * A message gets one send plus up to three retries — `MAX_ATTEMPTS` — spaced
 * by `RETRY_DELAYS`. After the last failure it's marked `failed` and logged
 * as an error; a permanent failure (bad address, a 5xx reply, no SMTP config)
 * gives up straight away. Either way its subject and body are wiped, since
 * they can hold a login code or a reset link.
 *
 * Any number of workers can run: each claims a batch with
 * `SELECT … FOR UPDATE SKIP LOCKED`, so two never take the same row. A worker
 * that dies mid-send leaves its rows `sending`; once their lock lapses
 * another worker picks them up (that attempt counts), so delivery is
 * at-least-once.
 */
class MailWorker
{
    /** The first send plus three retries. */
    public const MAX_ATTEMPTS = 4;
    /** Seconds to wait before retry 1, 2 and 3. */
    private const RETRY_DELAYS = [1 => 20, 2 => 60, 3 => 300];
    private const BATCH_SIZE = 10;
    /** How long a claimed row is ours before another worker may retake it. */
    private const LOCK_SECONDS = 120;
    /** Longest wait for a wake-up before polling the table anyway. */
    private const POLL_SECONDS = 5;
    /** Sent and failed rows (already wiped) are deleted after this many days. */
    private const KEEP_DAYS = 30;

    /** @var db */
    private $db;
    /** @var RedisClient */
    private $redis;
    /** @var Mailer */
    private $mailer;
    private $lastCleanup = 0;
    private $redisDownSince = 0;

    public function __construct(db $db, RedisClient $redis, Mailer $mailer)
    {
        $this->db = $db;
        $this->redis = $redis;
        $this->mailer = $mailer;
    }

    public function run(): void
    {
        Log::info('Mail worker started', ['smtp_configured' => Mailer::isConfigured()]);

        while (true) {
            try {
                $this->db->ensureConnected();
                while ($this->processBatch() > 0) {
                    // Keep going while there's a backlog.
                }
                $this->cleanup();
            } catch (Throwable $e) {
                Log::exception('Mail worker loop failed', $e);
                sleep(self::POLL_SECONDS);
            }

            $this->waitForWork();
        }
    }

    /** Claims and sends up to one batch of due messages; returns how many it claimed. */
    public function processBatch(): int
    {
        $rows = $this->claim();
        foreach ($rows as $row) {
            $this->deliver($row);
        }

        return count($rows);
    }

    /** @return array<int, array> the claimed rows, now `sending` with `attempts` already counted */
    private function claim(): array
    {
        $this->db->db_Execute('START TRANSACTION');
        $ids = $this->db->db_GetArray(
            'SELECT `id` FROM `email_outbox`
             WHERE (`status` = "queued" AND `next_attempt_at` <= UTC_TIMESTAMP())
                OR (`status` = "sending" AND `locked_until` < UTC_TIMESTAMP())
             ORDER BY `priority` DESC, `id` ASC
             LIMIT ' . self::BATCH_SIZE . '
             FOR UPDATE SKIP LOCKED',
            0,
            'id'
        );
        if (!$ids) {
            $this->db->db_Execute('COMMIT');
            return [];
        }

        $in = implode(',', array_map('intval', $ids));
        $this->db->db_Execute(
            'UPDATE `email_outbox`
             SET `status` = "sending", `attempts` = `attempts` + 1,
                 `locked_until` = UTC_TIMESTAMP() + INTERVAL ' . self::LOCK_SECONDS . ' SECOND
             WHERE `id` IN (' . $in . ')'
        );
        $this->db->db_Execute('COMMIT');

        return $this->db->db_GetArray('SELECT * FROM `email_outbox` WHERE `id` IN (' . $in . ') ORDER BY `priority` DESC, `id` ASC');
    }

    private function deliver(array $row): void
    {
        $id = (int) $row['id'];
        $attempt = (int) $row['attempts'];
        $context = [
            'email_id' => $id,
            'tag' => $row['tag'],
            'to' => Log::maskEmail((string) $row['recipient']),
            'attempt' => $attempt,
        ];

        try {
            $this->mailer->send((string) $row['recipient'], (string) $row['subject'], (string) $row['body']);
        } catch (MailerException $e) {
            $error = mb_substr($e->getMessage(), 0, 500);

            if ($e->permanent || $attempt >= self::MAX_ATTEMPTS) {
                $this->finish($id, 'failed', $error);
                Log::error('Email could not be delivered; giving up', $context + [
                    'error' => $error,
                    'permanent' => $e->permanent,
                ]);
                return;
            }

            $delay = self::RETRY_DELAYS[$attempt] ?? self::RETRY_DELAYS[3];
            $this->db->db_Execute(
                'UPDATE `email_outbox`
                 SET `status` = "queued", `locked_until` = NULL, `last_error` = "' . $this->db->db_Escape($error) . '",
                     `next_attempt_at` = UTC_TIMESTAMP() + INTERVAL ' . (int) $delay . ' SECOND
                 WHERE `id` = ' . $id
            );
            Log::warning('Email send failed; will retry', $context + ['error' => $error, 'retry_in_seconds' => $delay]);
            return;
        }

        $this->finish($id, 'sent', null);
        Log::info('Email sent', $context);
    }

    /** Final state: the subject and body are wiped — they may hold a login code or a reset link. */
    private function finish(int $id, string $status, ?string $error): void
    {
        $this->db->db_Execute(
            'UPDATE `email_outbox`
             SET `status` = "' . $status . '", `subject` = "", `body` = NULL, `locked_until` = NULL,
                 `last_error` = ' . ($error === null ? 'NULL' : '"' . $this->db->db_Escape($error) . '"') . ',
                 `sent_at` = ' . ($status === 'sent' ? 'UTC_TIMESTAMP()' : 'NULL') . '
             WHERE `id` = ' . $id
        );
    }

    private function cleanup(): void
    {
        if (time() - $this->lastCleanup < 3600) {
            return;
        }
        $this->lastCleanup = time();
        $this->db->db_Execute(
            'DELETE FROM `email_outbox`
             WHERE `status` IN ("sent", "failed") AND `created_at` < UTC_TIMESTAMP() - INTERVAL ' . self::KEEP_DAYS . ' DAY
             LIMIT 1000'
        );
    }

    /**
     * Sleeps until `MailQueue` pushes a wake-up or `POLL_SECONDS` pass, so
     * a retry that comes due is still picked up without a wake-up.
     */
    private function waitForWork(): void
    {
        try {
            if ($this->redis->blockingPop(MailQueue::WAKE_LIST, self::POLL_SECONDS) !== null) {
                // Several messages may have been queued at once — one batch
                // takes them all, so the extra wake-ups are noise.
                $this->redis->command('DEL', MailQueue::WAKE_LIST);
            }
            if ($this->redisDownSince) {
                Log::info('Redis is back; wake-ups resumed');
                $this->redisDownSince = 0;
            }
        } catch (Throwable $e) {
            if (!$this->redisDownSince) {
                $this->redisDownSince = time();
                Log::warning('Redis unavailable; polling the outbox instead', ['error' => $e->getMessage()]);
            }
            sleep(self::POLL_SECONDS);
        }
    }
}
