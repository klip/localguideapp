-- 041: levels, rank-based fees and bonus packages (see api/classes/Levels.php).
--
--   user_sanctions — levels taken away by a moderator, e.g. after a complaint:
--     INSERT INTO user_sanctions (user_id, levels, reason, complaint_id, expires_at)
--     VALUES (…, 1, 'Asked a guest to pay outside the app', …, UTC_TIMESTAMP() + INTERVAL 30 DAY);
--     (expires_at NULL = until lifted; lift with UPDATE … SET lifted_at = UTC_TIMESTAMP()).
--   bonus_grants — bonus packages earned (free picks for guests, fee-free
--     days for guides), claimed by the user: starts_at/ends_at set on claim.
--   users.level — the level last announced, so each level-up is told once
--     (an account with past trips hears its current level on its next sync).
--   selections.free_pick — an 'interested' made during free picks, which
--     doesn't use the visitor's paid pack.
--   bookings.commission_rate / commission_amount / guide_payout — the
--     platform fee taken from a paid booking, fixed when the guest pays.
--
-- Not re-runnable (ADD COLUMN).

SET NAMES utf8mb4;

CREATE TABLE IF NOT EXISTS `user_sanctions` (
  `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `user_id` INT UNSIGNED NOT NULL,
  `levels` TINYINT UNSIGNED NOT NULL DEFAULT 1,
  `reason` VARCHAR(255) NOT NULL,
  `complaint_id` INT UNSIGNED NULL DEFAULT NULL,
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `expires_at` DATETIME NULL DEFAULT NULL,
  `lifted_at` DATETIME NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `idx_user_sanctions_active` (`user_id`, `lifted_at`, `expires_at`),
  CONSTRAINT `fk_user_sanctions_user` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_user_sanctions_complaint` FOREIGN KEY (`complaint_id`) REFERENCES `complaints` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `bonus_grants` (
  `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `user_id` INT UNSIGNED NOT NULL,
  `kind` ENUM('free_picks', 'no_fee') NOT NULL,
  `days` SMALLINT UNSIGNED NOT NULL,
  `reason` VARCHAR(40) NOT NULL COMMENT 'level_<n> or reviews_<n>: granted once each',
  `granted_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `starts_at` DATETIME NULL DEFAULT NULL,
  `ends_at` DATETIME NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `bonus_grants_once` (`user_id`, `reason`),
  KEY `idx_bonus_grants_active` (`user_id`, `kind`, `ends_at`),
  CONSTRAINT `fk_bonus_grants_user` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

ALTER TABLE `users` ADD COLUMN `level` TINYINT UNSIGNED NOT NULL DEFAULT 0;
ALTER TABLE `selections` ADD COLUMN `free_pick` TINYINT(1) NOT NULL DEFAULT 0;
ALTER TABLE `bookings`
  ADD COLUMN `commission_rate` DECIMAL(5, 4) NULL DEFAULT NULL AFTER `amount`,
  ADD COLUMN `commission_amount` DECIMAL(10, 2) NULL DEFAULT NULL AFTER `commission_rate`,
  ADD COLUMN `guide_payout` DECIMAL(10, 2) NULL DEFAULT NULL AFTER `commission_amount`;
