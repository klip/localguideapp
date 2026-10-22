-- Guides' availability calendars (docs/availability-plan.md, C1/C2): when a
-- guide works, so visitors can only book free times.
--
-- guide_calendars     one row per guide who set one up — until then their
--                     bookings stay free-form, as before. All hours are local
--                     to `time_zone` (IANA). `slot_minutes`: the start-time
--                     step; `buffer_minutes`: kept free between trips;
--                     `default_trip_hours`: a booking's length when the guest
--                     gives none; `min_notice_hours` / `max_days_ahead`: how
--                     soon / how far ahead a trip may start.
-- guide_weekly_hours  working windows per ISO weekday (1 = Monday), in
--                     minutes from local midnight; several per day allowed.
-- guide_days_off      a dated exception: `off` (all day when start/end are
--                     NULL, else that window) or `extra` (an extra window).
--
-- Busy time isn't stored: pending and unfinished confirmed bookings hold
-- their slot (`Availability`). Re-runnable.

SET NAMES utf8mb4;

CREATE TABLE IF NOT EXISTS `guide_calendars` (
  `user_id` INT UNSIGNED NOT NULL,
  `time_zone` VARCHAR(64) NOT NULL,
  `slot_minutes` SMALLINT UNSIGNED NOT NULL DEFAULT 30,
  `buffer_minutes` SMALLINT UNSIGNED NOT NULL DEFAULT 30,
  `default_trip_hours` DECIMAL(4,1) NOT NULL DEFAULT 3.0,
  `min_notice_hours` SMALLINT UNSIGNED NOT NULL DEFAULT 12,
  `max_days_ahead` SMALLINT UNSIGNED NOT NULL DEFAULT 180,
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`user_id`),
  CONSTRAINT `fk_guide_calendars_user` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `guide_weekly_hours` (
  `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `user_id` INT UNSIGNED NOT NULL,
  `weekday` TINYINT UNSIGNED NOT NULL,
  `start_minute` SMALLINT UNSIGNED NOT NULL,
  `end_minute` SMALLINT UNSIGNED NOT NULL,
  PRIMARY KEY (`id`),
  KEY `guide_weekly_hours_user` (`user_id`, `weekday`),
  CONSTRAINT `fk_guide_weekly_hours_user` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE,
  CONSTRAINT `guide_weekly_hours_span` CHECK (`weekday` BETWEEN 1 AND 7 AND `start_minute` < `end_minute` AND `end_minute` <= 1440)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `guide_days_off` (
  `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `user_id` INT UNSIGNED NOT NULL,
  `day` DATE NOT NULL,
  `kind` ENUM('off', 'extra') NOT NULL DEFAULT 'off',
  `start_minute` SMALLINT UNSIGNED NULL,
  `end_minute` SMALLINT UNSIGNED NULL,
  `note` VARCHAR(120) NULL,
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `guide_days_off_user` (`user_id`, `day`),
  CONSTRAINT `fk_guide_days_off_user` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
