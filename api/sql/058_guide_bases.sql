-- 058: location, phase A1 (docs/ai-and-location.md).
--
--   * `guide_bases`: where a guide works — a place label, its country, a
--     point (SRID 4326, rounded to ~1 km by the API so a guide's home isn't
--     exact) and how far they'll travel. `radius_km` NULL = flexible: they
--     travel any distance.
--   * The `distance` category goes: it was a span each user typed on their
--     own profile and the filter compared spans — nobody had coordinates, so
--     it measured nothing. Distance is now measured from a search point to a
--     guide's base (phase A2). Its stored spans go with it (FK cascade).
--   * `attribute_categories.allow_unlimited` (048) only ever served
--     `distance`; dropped.
--
-- Not re-runnable (table created, column dropped).

SET NAMES utf8mb4;

CREATE TABLE `guide_bases` (
  `user_id` INT UNSIGNED NOT NULL,
  `label` VARCHAR(160) NOT NULL,
  `country_code` CHAR(2) NULL,
  `point` POINT NOT NULL SRID 4326,
  `radius_km` SMALLINT UNSIGNED NULL,
  `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`user_id`),
  SPATIAL INDEX `guide_bases_point` (`point`),
  CONSTRAINT `guide_bases_user_fk` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

DELETE FROM `attribute_categories` WHERE `key` = 'distance';

ALTER TABLE `attribute_categories` DROP COLUMN `allow_unlimited`;
