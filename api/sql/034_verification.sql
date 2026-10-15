-- 034: identity verification — the "Verified" badge and its weight in the
-- discovery ranking (see api/classes/Ranking.php).
--
-- `users.verified_at` is set when a person's identity document (ID card,
-- passport or driving licence) has been checked against a selfie; until the
-- automated check exists (the next big change), a moderator sets it by hand:
--
--   UPDATE users SET verified_at = UTC_TIMESTAMP(), verification_method = 'passport'
--   WHERE id = …;
--
-- Clearing `verified_at` (back to NULL) removes the badge. Nothing about the
-- document itself is stored here.
--
-- Not re-runnable (ADD COLUMN).

SET NAMES utf8mb4;

ALTER TABLE `users`
  ADD COLUMN `verified_at` DATETIME NULL DEFAULT NULL,
  ADD COLUMN `verification_method` ENUM('id_card', 'passport', 'driving_licence') NULL DEFAULT NULL;
