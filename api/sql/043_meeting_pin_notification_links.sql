-- 043: a booking's meeting place can carry a map pin (`location_lat`/
-- `location_lng`, shared from the device like a chat location), and a
-- notification can name the person it's about (`about_user_id`), so its toast
-- links to them. Not re-runnable (ADD COLUMN).

SET NAMES utf8mb4;

ALTER TABLE `bookings`
  ADD COLUMN `location_lat` DECIMAL(9,6) NULL AFTER `location`,
  ADD COLUMN `location_lng` DECIMAL(9,6) NULL AFTER `location_lat`;

ALTER TABLE `notifications`
  ADD COLUMN `about_user_id` INT UNSIGNED NULL AFTER `kind`,
  ADD CONSTRAINT `notifications_about_fk` FOREIGN KEY (`about_user_id`) REFERENCES `users` (`id`) ON DELETE SET NULL;
