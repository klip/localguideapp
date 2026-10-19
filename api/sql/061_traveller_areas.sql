-- 061: location, phase A3 (docs/ai-and-location.md).
--
-- `traveller_areas`: where a traveller is currently looking for guides — the
-- deck's search point, recorded when they set it (`setSearchArea`), so the
-- guide's deck can show travellers looking near them. A town label and a
-- point rounded to ~1 km; read only while fresh (30 days, see
-- `Users::searchByRole()`), deleted when they clear it.
--
-- Re-runnable.

SET NAMES utf8mb4;

CREATE TABLE IF NOT EXISTS `traveller_areas` (
  `user_id` INT UNSIGNED NOT NULL,
  `label` VARCHAR(160) NOT NULL,
  `country_code` CHAR(2) NULL,
  `point` POINT NOT NULL SRID 4326,
  `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`user_id`),
  SPATIAL INDEX `traveller_areas_point` (`point`),
  CONSTRAINT `traveller_areas_user_fk` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;
