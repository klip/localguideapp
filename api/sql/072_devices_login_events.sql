-- Devices and the sign-in history (docs/auth-cms-plan.md, phase 3).
--
-- devices — one row per account per browser (the `__Host-rg_device` cookie;
--   `device_id` is its SHA-256). Device details are stored as keyed hashes,
--   one per component (`components`, HMAC with the server's FINGERPRINT_KEY —
--   one-way, and useless without the key), plus their combination
--   (`fingerprint_hash`); in plain text only what the user is shown on their
--   own list: the label ("Chrome on macOS") and the place.
-- login_events — every sign-in, failed password, two-factor step, refresh
--   from a new address, logout and revocation, for risk scoring (phase 4) and
--   the CMS. Kept 12 months; the IP is blanked after 90 days (the scheduler).
--
-- Re-runnable.

SET NAMES utf8mb4;

CREATE TABLE IF NOT EXISTS `devices` (
  `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `user_id` INT UNSIGNED NOT NULL,
  `device_id` CHAR(64) NOT NULL,
  `fingerprint_hash` CHAR(64) NULL,
  `components` JSON NULL,
  `label` VARCHAR(80) NOT NULL DEFAULT '',
  `first_seen_at` DATETIME NOT NULL,
  `last_seen_at` DATETIME NOT NULL,
  `last_ip` VARCHAR(45) NULL,
  `last_country` CHAR(2) NULL,
  `last_place` VARCHAR(120) NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `devices_user_device` (`user_id`, `device_id`),
  KEY `devices_device` (`device_id`),
  KEY `devices_fingerprint` (`fingerprint_hash`),
  CONSTRAINT `fk_devices_user` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `login_events` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `user_id` INT UNSIGNED NULL,
  -- Keyed hash of the address typed, for failed attempts on any account.
  `email_hash` CHAR(64) NULL,
  `event` ENUM('register', 'login', 'login_failed', 'two_factor_required', 'two_factor_ok',
               'refresh', 'logout', 'logout_everywhere', 'device_signed_out', 'reuse_detected') NOT NULL,
  `device_row_id` INT UNSIGNED NULL,
  `device_id` CHAR(64) NULL,
  `fingerprint_hash` CHAR(64) NULL,
  `components` JSON NULL,
  `ip` VARCHAR(45) NULL,
  `country` CHAR(2) NULL,
  `place` VARCHAR(120) NULL,
  `user_agent` VARCHAR(255) NOT NULL DEFAULT '',
  `created_at` DATETIME NOT NULL,
  PRIMARY KEY (`id`),
  KEY `login_events_user` (`user_id`, `created_at`),
  KEY `login_events_email` (`email_hash`, `created_at`),
  KEY `login_events_device` (`device_id`),
  KEY `login_events_fingerprint` (`fingerprint_hash`),
  KEY `login_events_created` (`created_at`),
  CONSTRAINT `fk_login_events_user` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
