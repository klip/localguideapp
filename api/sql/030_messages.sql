-- 030: direct messages between a matched guest and guide (see
-- api/classes/Messages.php).
--
-- One row per message, tied to the pair's `selections` row. Writing needs an
-- active match; the history outlives it (deleting a user, or the pair's
-- selection, deletes it). `body` has contact details already masked by
-- lib/ContactFilter.php. `read_at` is set when the recipient opens the
-- conversation — the unread count is the "Messages" menu counter.
--
-- Re-runnable.

SET NAMES utf8mb4;

CREATE TABLE IF NOT EXISTS `messages` (
  `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `selection_id` INT UNSIGNED NOT NULL,
  `sender_id` INT UNSIGNED NOT NULL,
  `recipient_id` INT UNSIGNED NOT NULL,
  `body` TEXT NOT NULL,
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `read_at` DATETIME NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `idx_messages_selection` (`selection_id`, `id`),
  KEY `idx_messages_unread` (`recipient_id`, `read_at`),
  KEY `idx_messages_pair_unread` (`selection_id`, `recipient_id`, `read_at`),
  CONSTRAINT `fk_messages_selection` FOREIGN KEY (`selection_id`) REFERENCES `selections` (`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_messages_sender` FOREIGN KEY (`sender_id`) REFERENCES `users` (`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_messages_recipient` FOREIGN KEY (`recipient_id`) REFERENCES `users` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
