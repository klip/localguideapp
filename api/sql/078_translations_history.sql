-- Every change to a UI text made in the CMS's content editor
-- (docs/auth-cms-plan.md, phase 7): the value before and after, by whom —
-- so any edit can be seen and reverted (`ContentEditor`). A NULL old value is
-- a text that didn't exist (a new key or a first translation); a NULL new
-- value is one removed (the language falls back to English).
--
-- Re-runnable.

SET NAMES utf8mb4;

CREATE TABLE IF NOT EXISTS `translations_history` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `language` VARCHAR(10) NOT NULL,
  `key` VARCHAR(191) NOT NULL,
  `old_value` TEXT NULL,
  `new_value` TEXT NULL,
  -- No foreign key: the record outlives a removed staff member.
  `staff_id` INT UNSIGNED NULL,
  `staff_email` VARCHAR(190) NOT NULL DEFAULT '',
  `created_at` DATETIME NOT NULL,
  PRIMARY KEY (`id`),
  KEY `translations_history_key` (`key`, `language`, `id`),
  KEY `translations_history_created` (`created_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
