-- 037: complaints — one user reporting another (see api/classes/Complaints.php,
-- the "Report" button on both public profile pages).
--
-- `reporter_id` filed it about `subject_id`, with a short `title` and the
-- `description` (the complaint text). Moderators work it through `status`
-- (open → reviewing → resolved | dismissed) and write the outcome in
-- `resolution` — there's no admin screen yet (admin/ is a placeholder), so
-- that's done in SQL for now:
--
--   UPDATE complaints SET status = 'resolved', resolution = '…',
--          resolved_at = UTC_TIMESTAMP() WHERE id = …;
--
-- A complaint outlives the accounts it's about: deleting either user sets
-- their id to NULL rather than deleting the record, so someone who is
-- reported can't erase it by closing their account.
--
-- Re-runnable.

SET NAMES utf8mb4;

CREATE TABLE IF NOT EXISTS `complaints` (
  `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `reporter_id` INT UNSIGNED NULL,
  `subject_id` INT UNSIGNED NULL,
  `title` VARCHAR(120) NOT NULL,
  `description` TEXT NOT NULL,
  `status` ENUM('open', 'reviewing', 'resolved', 'dismissed') NOT NULL DEFAULT 'open',
  `resolution` TEXT NULL DEFAULT NULL,
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `resolved_at` DATETIME NULL DEFAULT NULL,
  `notified_at` DATETIME NULL DEFAULT NULL COMMENT 'when the moderators were emailed about it (COMPLAINTS_EMAIL)',
  PRIMARY KEY (`id`),
  KEY `idx_complaints_subject` (`subject_id`, `status`),
  KEY `idx_complaints_reporter` (`reporter_id`, `created_at`),
  KEY `idx_complaints_status` (`status`, `created_at`),
  CONSTRAINT `fk_complaints_reporter` FOREIGN KEY (`reporter_id`) REFERENCES `users` (`id`) ON DELETE SET NULL,
  CONSTRAINT `fk_complaints_subject` FOREIGN KEY (`subject_id`) REFERENCES `users` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
