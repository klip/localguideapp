-- The CMS's Moderation section (docs/auth-cms-plan.md, phase 9).
--
-- complaints        + who took it (`assigned_*`, when it moved to
--                   "reviewing"), who closed it (`resolved_by`), and when the
--                   reporter was told it was reviewed (`reporter_told_at`).
-- complaint_notes   staff-only notes on a complaint — what was checked, who
--                   was contacted — never shown to either user.
-- account_links     + who gave the verdict and why (`reviewed_by`,
--                   `review_note`); `reviewed_at`/`verdict` exist since 074.
--
-- Not re-runnable (ALTER TABLE).

SET NAMES utf8mb4;

ALTER TABLE `complaints`
  ADD COLUMN `assigned_staff_id` INT UNSIGNED NULL AFTER `status`,
  ADD COLUMN `assigned_email` VARCHAR(190) NULL AFTER `assigned_staff_id`,
  ADD COLUMN `assigned_at` DATETIME NULL AFTER `assigned_email`,
  ADD COLUMN `resolved_by` VARCHAR(190) NULL AFTER `resolved_at`,
  ADD COLUMN `reporter_told_at` DATETIME NULL AFTER `notified_at`;

CREATE TABLE IF NOT EXISTS `complaint_notes` (
  `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `complaint_id` INT UNSIGNED NOT NULL,
  `staff_id` INT UNSIGNED NULL,
  `staff_email` VARCHAR(190) NOT NULL DEFAULT '',
  `body` TEXT NOT NULL,
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `complaint_notes_complaint` (`complaint_id`, `id`),
  CONSTRAINT `fk_complaint_notes_complaint` FOREIGN KEY (`complaint_id`) REFERENCES `complaints` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

ALTER TABLE `account_links`
  ADD COLUMN `reviewed_by` VARCHAR(190) NULL AFTER `reviewed_at`,
  ADD COLUMN `review_note` VARCHAR(500) NULL AFTER `verdict`;
