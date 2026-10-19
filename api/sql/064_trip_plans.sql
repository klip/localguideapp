-- 064: travel planner, phase B1 (docs/ai-and-location.md).
--
-- `trip_plans`: a traveller's saved itinerary. Finding guides for it runs as
-- a job (`api/bin/planner.php`): saving sets `status = 'pending'`, the worker
-- claims it (`processing`, `locked_until`), fills each stop's suggestions and
-- marks it `ready` (or `failed` after `TripPlanner::MAX_ATTEMPTS`).
-- `language` is the one the explanations are written in; `ai_ranked` says
-- whether Claude ordered and explained them (0 = the plain SQL order).
--
-- `trip_plan_stops`: one place + dates per stop, the point rounded to ~1 km
-- like every other stored place. `suggestions` is JSON written by the worker:
-- `[{ guideId, reason, distanceKm }]`, at most 4, best first.
--
-- Re-runnable.

SET NAMES utf8mb4;

CREATE TABLE IF NOT EXISTS `trip_plans` (
  `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `user_id` INT UNSIGNED NOT NULL,
  `title` VARCHAR(80) NULL,
  `language` VARCHAR(5) NOT NULL DEFAULT 'en',
  `status` ENUM('pending', 'processing', 'ready', 'failed') NOT NULL DEFAULT 'pending',
  `attempts` TINYINT UNSIGNED NOT NULL DEFAULT 0,
  `locked_until` DATETIME NULL,
  `error` VARCHAR(255) NULL,
  `ai_ranked` TINYINT(1) NOT NULL DEFAULT 0,
  `generated_at` DATETIME NULL,
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `trip_plans_user` (`user_id`, `created_at`),
  KEY `trip_plans_queue` (`status`, `locked_until`),
  CONSTRAINT `trip_plans_user_fk` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

CREATE TABLE IF NOT EXISTS `trip_plan_stops` (
  `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `plan_id` INT UNSIGNED NOT NULL,
  `position` TINYINT UNSIGNED NOT NULL,
  `label` VARCHAR(160) NOT NULL,
  `country_code` CHAR(2) NULL,
  `point` POINT NOT NULL SRID 4326,
  `arrive_on` DATE NOT NULL,
  `depart_on` DATE NOT NULL,
  `suggestions` JSON NULL,
  PRIMARY KEY (`id`),
  KEY `trip_plan_stops_plan` (`plan_id`, `position`),
  KEY `trip_plan_stops_depart` (`depart_on`),
  SPATIAL INDEX `trip_plan_stops_point` (`point`),
  CONSTRAINT `trip_plan_stops_plan_fk` FOREIGN KEY (`plan_id`) REFERENCES `trip_plans` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;
