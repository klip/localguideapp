-- Two-factor login codes and password resets, both by email (2026-10-05).
--
--   docker exec -i lemp-mysql mysql -u localguideapp -p'…' guideapp < api/sql/016_auth_challenges.sql
--
-- The CREATE TABLE is IF NOT EXISTS-guarded and the UPDATE is idempotent, so
-- this is safe to re-run.
--
-- One row per challenge (see `AccountSecurity.php`):
--   login — sent after a correct password when the account has two-factor on.
--           The client holds `token` (only its SHA-256 is stored here); the
--           6-digit `code` goes by email and is stored as a password_hash().
--           Wrong guesses count in `attempts`; resends in `sends`.
--   reset — a "forgot password" link. Only the token, no code.
-- A challenge is spent once `used_at` is set, and dead once `expires_at` has
-- passed. All date-times are UTC.
CREATE TABLE IF NOT EXISTS `auth_challenges` (
  `id`           INT UNSIGNED     NOT NULL AUTO_INCREMENT,
  `user_id`      INT UNSIGNED     NOT NULL,
  `purpose`      ENUM('login','reset') NOT NULL,
  `token_hash`   CHAR(64)         NOT NULL,
  `code_hash`    VARCHAR(255)     NULL,
  `attempts`     TINYINT UNSIGNED NOT NULL DEFAULT 0,
  `sends`        TINYINT UNSIGNED NOT NULL DEFAULT 1,
  `last_sent_at` DATETIME         NOT NULL,
  `expires_at`   DATETIME         NOT NULL,
  `used_at`      DATETIME         NULL,
  `created_at`   DATETIME         NOT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `auth_challenges_token` (`token_hash`),
  KEY `auth_challenges_user` (`user_id`, `purpose`, `created_at`),
  CONSTRAINT `auth_challenges_user_fk` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Codes can only go by email for now. Accounts that picked SMS or WhatsApp
-- (stored, but never delivered) get email, so their next login works.
UPDATE `users` SET `two_factor_method` = 'email' WHERE `two_factor_method` IN ('sms', 'whatsapp');
