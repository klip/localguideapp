-- 028: contact filter hits (see lib/ContactFilter.php).
--
-- One row each time someone tried to put contact details (a phone number,
-- email, link or social handle) where others would read them: `blocked`
-- when the save was refused (profile text, name, a new interest), `masked`
-- when it went through with the details replaced by [•••] (a review, a
-- meeting place, a decline/cancellation reason). The text itself is not
-- kept — only which field and what kind — so this is for spotting repeat
-- offenders, not for reading what they wrote.
--
-- Re-runnable.

SET NAMES utf8mb4;

CREATE TABLE IF NOT EXISTS `contact_filter_hits` (
  `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `user_id` INT UNSIGNED NOT NULL,
  `field` VARCHAR(40) NOT NULL,
  `kinds` VARCHAR(80) NOT NULL COMMENT 'comma-separated: phone,email,link,handle,invitation',
  `outcome` ENUM('blocked', 'masked') NOT NULL,
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_contact_filter_hits_user` (`user_id`, `created_at`),
  CONSTRAINT `fk_contact_filter_hits_user` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
