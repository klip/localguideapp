-- Additive migration (see 002's header for the pattern).
--
--   docker exec -i lemp-mysql mysql -u localguideapp -p'…' guideapp < api/sql/005_profiles.sql
--
-- Moves the discovery-deck filterable attributes out of the client (where
-- they were placeholder-only — see stores/guides.ts/visitors.ts before this
-- migration) and into normalized tables, so filtering can happen in SQL
-- (`Users::searchByRole()`) instead of a JS array filter over an
-- already-fetched list.
--
--   user_profiles   — one row per user, the scalar fields (age, gender,
--                      price, bio, …). A visitor and a guide share this
--                      table (like `users` itself already does); only the
--                      columns relevant to that account's role get filled.
--   languages / user_languages       — many-to-many: a user can speak
--                      several languages, seeded from the client's existing
--                      `LANGUAGES` list (stores/filters.ts).
--   activities / user_activities     — many-to-many, mirrors the `Activity`
--                      TS union (types/index.ts) — keep both in sync if
--                      that union ever changes.
--   user_specialties  — freeform guide tags (e.g. "Upper Rock", "Old
--                      Town") shown on a guide's card/profile. Not a
--                      lookup table: these are guide-authored text, not a
--                      fixed vocabulary, and not filtered on today (no
--                      specialty filter exists in the UI).

CREATE TABLE IF NOT EXISTS `user_profiles` (
  `user_id`        INT UNSIGNED NOT NULL,
  `age`            INT UNSIGNED NULL,
  `gender`         ENUM('woman','man','other') NULL,
  `headline`       VARCHAR(160) NULL,
  `bio`            TEXT NULL,
  `price_amount`   DECIMAL(8,2) NULL,
  `price_label`    VARCHAR(80) NULL,
  `price_note`     VARCHAR(120) NULL,
  `includes`       TEXT NULL,
  `rating`         DECIMAL(2,1) NULL,
  `tours`          INT UNSIGNED NULL,
  `party`          VARCHAR(160) NULL,
  `duration_hours` INT UNSIGNED NULL,
  PRIMARY KEY (`user_id`),
  CONSTRAINT `user_profiles_user_fk` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS `languages` (
  `id`   INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `name` VARCHAR(40) NOT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `languages_name` (`name`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

INSERT IGNORE INTO `languages` (`name`) VALUES ('English'), ('Spanish'), ('Arabic'), ('German');

CREATE TABLE IF NOT EXISTS `user_languages` (
  `user_id`     INT UNSIGNED NOT NULL,
  `language_id` INT UNSIGNED NOT NULL,
  PRIMARY KEY (`user_id`, `language_id`),
  KEY `user_languages_language_id` (`language_id`),
  CONSTRAINT `user_languages_user_fk` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE,
  CONSTRAINT `user_languages_language_fk` FOREIGN KEY (`language_id`) REFERENCES `languages` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS `activities` (
  `id`   INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `slug` VARCHAR(40) NOT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `activities_slug` (`slug`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

INSERT IGNORE INTO `activities` (`slug`) VALUES
  ('history'), ('food'), ('nature'), ('family'), ('photography'), ('bars'), ('bro');

CREATE TABLE IF NOT EXISTS `user_activities` (
  `user_id`     INT UNSIGNED NOT NULL,
  `activity_id` INT UNSIGNED NOT NULL,
  PRIMARY KEY (`user_id`, `activity_id`),
  KEY `user_activities_activity_id` (`activity_id`),
  CONSTRAINT `user_activities_user_fk` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE,
  CONSTRAINT `user_activities_activity_fk` FOREIGN KEY (`activity_id`) REFERENCES `activities` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS `user_specialties` (
  `id`      INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `user_id` INT UNSIGNED NOT NULL,
  `label`   VARCHAR(80) NOT NULL,
  PRIMARY KEY (`id`),
  KEY `user_specialties_user_id` (`user_id`),
  CONSTRAINT `user_specialties_user_fk` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
