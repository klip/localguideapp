-- 032: shared locations in chat (see api/classes/Messages.php,
-- MessagesPage.vue's "Share my location").
--
-- A message is now either `text` (the body) or `location` (a point the
-- sender's device reported, with its accuracy in metres; the body is empty).
-- Coordinates are stored rather than a maps link so each viewer gets a link
-- to the maps app native to their own device (client/src/utils/mapsLink.ts).
--
-- Not re-runnable (ADD COLUMN; MySQL 8.4 has no IF NOT EXISTS for columns).

SET NAMES utf8mb4;

ALTER TABLE `messages`
  ADD COLUMN `kind` ENUM('text', 'location') NOT NULL DEFAULT 'text' AFTER `recipient_id`,
  ADD COLUMN `latitude` DECIMAL(9, 6) NULL DEFAULT NULL AFTER `body`,
  ADD COLUMN `longitude` DECIMAL(9, 6) NULL DEFAULT NULL AFTER `latitude`,
  ADD COLUMN `accuracy_m` INT UNSIGNED NULL DEFAULT NULL AFTER `longitude`;
