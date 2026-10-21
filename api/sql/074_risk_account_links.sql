-- Suspicious sign-ins and linked accounts (docs/auth-cms-plan.md, phase 4).
--
-- login_events.risk_score / risk_reasons — what `Risk::assess()` made of a
--   sign-in attempt (unfamiliar browser, failed passwords, …).
-- auth_challenges.purpose 'secure' — the "Not you? Secure your account" link
--   in a new-sign-in email: 7 days, once; it signs the account out
--   everywhere and leads to a new password.
-- account_links — accounts that look like the same person: the same browser
--   (device cookie), the same fingerprint, or the same address and
--   fingerprint within 30 days. Flagged for staff, never blocked on its own
--   (families share laptops). `strength`: 3 device, 2 address+fingerprint,
--   1 fingerprint. `verdict` is for the CMS (phase 9).
--
-- Not re-runnable (ADD COLUMN).

SET NAMES utf8mb4;

ALTER TABLE `login_events`
  ADD COLUMN `risk_score` TINYINT UNSIGNED NULL AFTER `user_agent`,
  ADD COLUMN `risk_reasons` JSON NULL AFTER `risk_score`;

ALTER TABLE `auth_challenges`
  MODIFY `purpose` ENUM('login', 'reset', 'secure') NOT NULL;

CREATE TABLE IF NOT EXISTS `account_links` (
  `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `user_a` INT UNSIGNED NOT NULL,
  `user_b` INT UNSIGNED NOT NULL,
  `signal` ENUM('device', 'ip_fingerprint', 'fingerprint') NOT NULL,
  `strength` TINYINT UNSIGNED NOT NULL,
  `hits` INT UNSIGNED NOT NULL DEFAULT 1,
  `first_seen_at` DATETIME NOT NULL,
  `last_seen_at` DATETIME NOT NULL,
  `reviewed_at` DATETIME NULL,
  `verdict` ENUM('same_person', 'different_people', 'unsure') NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `account_links_pair` (`user_a`, `user_b`, `signal`),
  KEY `account_links_b` (`user_b`),
  CONSTRAINT `fk_account_links_a` FOREIGN KEY (`user_a`) REFERENCES `users` (`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_account_links_b` FOREIGN KEY (`user_b`) REFERENCES `users` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
