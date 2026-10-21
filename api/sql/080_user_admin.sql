-- What staff do to app users from the CMS's Users section
-- (docs/auth-cms-plan.md, phase 8).
--
-- user_freezes       an account suspended: from `starts_at` until `ends_at`
--                    (NULL = permanently), with a required reason; lifting
--                    one early sets `lifted_at`. While one is active, auth
--                    refuses sign-in and refresh, the user disappears from
--                    decks, the planner and public profiles, and chats with
--                    them are read-only.
-- level_adjustments  a signed change to a user's level in one role (NULL =
--                    both), e.g. +1 for a founding guide or −2 after a
--                    complaint; optional expiry; lifting sets `lifted_at`.
--                    Sanctions (`user_sanctions`, 041) stay as they are —
--                    person-wide, subtracting, and suspending bonuses.
-- user_badges        badges staff award by hand ("Founding guide"), shown on
--                    the user's cards and profile beside the derived ones;
--                    optional expiry; revoking sets `revoked_at`.
-- user_sanctions     + who imposed it, now that staff do it from the CMS.
--
-- Not re-runnable (ALTER TABLE).

SET NAMES utf8mb4;

CREATE TABLE IF NOT EXISTS `user_freezes` (
  `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `user_id` INT UNSIGNED NOT NULL,
  `starts_at` DATETIME NOT NULL,
  `ends_at` DATETIME NULL,
  `reason` VARCHAR(500) NOT NULL,
  `staff_id` INT UNSIGNED NULL,
  `staff_email` VARCHAR(190) NOT NULL DEFAULT '',
  `lifted_at` DATETIME NULL,
  `lifted_by` VARCHAR(190) NULL,
  `lift_reason` VARCHAR(500) NULL,
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `user_freezes_active` (`user_id`, `lifted_at`, `ends_at`),
  CONSTRAINT `fk_user_freezes_user` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `level_adjustments` (
  `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `user_id` INT UNSIGNED NOT NULL,
  `role` ENUM('guest', 'guide') NULL,
  `delta` TINYINT NOT NULL,
  `reason` VARCHAR(255) NOT NULL,
  `expires_at` DATETIME NULL,
  `staff_id` INT UNSIGNED NULL,
  `staff_email` VARCHAR(190) NOT NULL DEFAULT '',
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `lifted_at` DATETIME NULL,
  `lifted_by` VARCHAR(190) NULL,
  PRIMARY KEY (`id`),
  KEY `level_adjustments_active` (`user_id`, `lifted_at`, `expires_at`),
  CONSTRAINT `fk_level_adjustments_user` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE,
  CONSTRAINT `level_adjustments_delta` CHECK (`delta` <> 0 AND `delta` BETWEEN -7 AND 7)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `user_badges` (
  `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `user_id` INT UNSIGNED NOT NULL,
  `label` VARCHAR(40) NOT NULL,
  `icon` VARCHAR(8) NOT NULL DEFAULT '★',
  `expires_at` DATETIME NULL,
  `staff_id` INT UNSIGNED NULL,
  `staff_email` VARCHAR(190) NOT NULL DEFAULT '',
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `revoked_at` DATETIME NULL,
  `revoked_by` VARCHAR(190) NULL,
  PRIMARY KEY (`id`),
  KEY `user_badges_active` (`user_id`, `revoked_at`, `expires_at`),
  CONSTRAINT `fk_user_badges_user` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

ALTER TABLE `user_sanctions`
  ADD COLUMN `staff_id` INT UNSIGNED NULL AFTER `complaint_id`,
  ADD COLUMN `staff_email` VARCHAR(190) NOT NULL DEFAULT '' AFTER `staff_id`,
  ADD COLUMN `lifted_by` VARCHAR(190) NULL AFTER `lifted_at`;

-- Bonuses staff grant: `reason` is `staff_<id>` (unique per grant).
ALTER TABLE `bonus_grants`
  ADD COLUMN `staff_email` VARCHAR(190) NULL AFTER `ends_at`,
  ADD COLUMN `note` VARCHAR(255) NULL AFTER `staff_email`,
  ADD COLUMN `revoked_at` DATETIME NULL AFTER `note`;
