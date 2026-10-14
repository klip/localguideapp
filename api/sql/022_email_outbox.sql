-- 022: the email outbox (applied 2026-10-14). Services queue mail here
-- (`lib/MailQueue.php`) instead of talking SMTP inside a request; the mail
-- worker (`mailer/`) sends it, retrying a failure up to three times before
-- marking the row `failed` and logging an error.
--
-- `subject`/`body` can hold a login code or a reset link: the worker blanks
-- both as soon as a message is sent or abandoned, and deletes finished rows
-- after 30 days. `tag` says what the message was, for the logs.
--
-- Re-runnable.

CREATE TABLE IF NOT EXISTS `email_outbox` (
  `id`              BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `recipient`       VARCHAR(255)    NOT NULL,
  `subject`         VARCHAR(255)    NOT NULL DEFAULT '',
  `body`            MEDIUMTEXT      NULL,
  `tag`             VARCHAR(64)     NOT NULL DEFAULT '',
  `priority`        TINYINT         NOT NULL DEFAULT 0,
  `status`          ENUM('queued', 'sending', 'sent', 'failed') NOT NULL DEFAULT 'queued',
  `attempts`        TINYINT UNSIGNED NOT NULL DEFAULT 0,
  `next_attempt_at` DATETIME        NOT NULL,
  `locked_until`    DATETIME        NULL,
  `last_error`      VARCHAR(500)    NULL,
  `created_at`      DATETIME        NOT NULL,
  `sent_at`         DATETIME        NULL,
  PRIMARY KEY (`id`),
  KEY `idx_email_outbox_due` (`status`, `next_attempt_at`),
  KEY `idx_email_outbox_created` (`created_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
