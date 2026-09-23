-- Finishes the job 007 started: nothing about a profile is predefined in code
-- any more, apart from the human constants (name, contact details, date of
-- birth, gender). Everything else is a category in the taxonomy.
--
-- Four changes (2026-09-15):
--
--   1. Categories gain a `kind`. `options` is what 007 built (pick values from
--      a list); `range` is new — a numeric span the user adds one or more of
--      (Hour of the day today, 00:00–23:00). `range_min`/`range_max` carry the
--      bounds so the slider is data-driven rather than hardcoded client-side.
--
--   2. Categories gain a `provider_label`: the same category reads differently
--      depending on which side of the match you're on. "Interests" is what a
--      guest picks as a consumer; the identical option set is a guide's
--      "Specialities" as a provider. One category, two labels — not two
--      concepts.
--
--   3. `user_specialties` is folded into `interests` accordingly. Its 9 live
--      rows are real user data, so they're migrated rather than dropped:
--      each distinct label becomes an `interests` option (matched
--      case-insensitively against the existing slugs, so "Photography" lands
--      on the existing `photography` rather than creating a duplicate), and
--      each row becomes a `user_attribute_values` entry. The single typo
--      "hicking" is normalised to "hiking" first, since it's plainly the same
--      tag and folding them keeps one option instead of two.
--      The table itself is left in place, no longer read or written by
--      `Profiles.php` — drop it in a later migration once this is confirmed
--      good in production.
--
--   4. `user_profiles.gender` moves to male/female. Confirmed NULL for every
--      row on the live DB before running, so no value needs translating; the
--      column stays nullable, and NULL is "no preference".
--
-- Additive apart from the two ALTERs. MySQL 8.4 has no `ADD COLUMN IF NOT
-- EXISTS` (see 004_users_image.sql), so re-running this errors on step 1
-- rather than doing anything silent.
--
--   docker exec -i lemp-mysql mysql -u localguideapp -p'…' guideapp < api/sql/010_profile_attributes.sql

-- 1. ---------------------------------------------------------------------
ALTER TABLE `attribute_categories`
  ADD COLUMN `kind`           ENUM('options','range') NOT NULL DEFAULT 'options' AFTER `label`,
  ADD COLUMN `provider_label` VARCHAR(80) NULL AFTER `kind`,
  ADD COLUMN `range_min`      INT NULL AFTER `multi`,
  ADD COLUMN `range_max`      INT NULL AFTER `range_min`;

-- One row per range a user has added within a range-kind category. Multiple
-- rows per (user, category) are the point: "09:00–13:00 and 18:00–21:00".
-- A user with no rows at all in a category is unconstrained — for
-- `hour_of_day` that reads as "always available", which is why the filter
-- treats a candidate with no ranges as matching every requested hour.
CREATE TABLE IF NOT EXISTS `user_attribute_ranges` (
  `id`          INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `user_id`     INT UNSIGNED NOT NULL,
  `category_id` INT UNSIGNED NOT NULL,
  `range_start` INT NOT NULL,
  `range_end`   INT NOT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `user_attribute_ranges_unique` (`user_id`, `category_id`, `range_start`, `range_end`),
  KEY `user_attribute_ranges_category` (`category_id`),
  CONSTRAINT `user_attribute_ranges_user_fk` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE,
  CONSTRAINT `user_attribute_ranges_category_fk` FOREIGN KEY (`category_id`) REFERENCES `attribute_categories` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- 2. ---------------------------------------------------------------------
UPDATE `attribute_categories` SET `provider_label` = 'Specialities' WHERE `key` = 'interests';

-- Hours a guide works. Guests don't store these — they pick the hours they
-- want in the discovery filter, which is matched against the guide's rows.
INSERT IGNORE INTO `attribute_categories`
  (`key`, `label`, `kind`, `provider_label`, `multi`, `range_min`, `range_max`)
VALUES
  ('hour_of_day', 'Hour of the day', 'range', 'Available hours', 1, 0, 23);

-- 3. ---------------------------------------------------------------------
UPDATE `user_specialties` SET `label` = 'hiking' WHERE LOWER(TRIM(`label`)) = 'hicking';

-- Create an interests option for every specialty label that isn't already one
-- (case-insensitively — the existing options are lowercase slugs).
INSERT IGNORE INTO `attribute_options` (`category_id`, `value`, `label`)
SELECT c.`id`, LOWER(TRIM(s.`label`)), TRIM(s.`label`)
FROM `user_specialties` s
JOIN `attribute_categories` c ON c.`key` = 'interests'
GROUP BY c.`id`, LOWER(TRIM(s.`label`)), TRIM(s.`label`);

-- Then point each user at the matching option.
INSERT IGNORE INTO `user_attribute_values` (`user_id`, `option_id`)
SELECT s.`user_id`, o.`id`
FROM `user_specialties` s
JOIN `attribute_categories` c ON c.`key` = 'interests'
JOIN `attribute_options` o ON o.`category_id` = c.`id` AND o.`value` = LOWER(TRIM(s.`label`));

-- 4. ---------------------------------------------------------------------
ALTER TABLE `user_profiles` MODIFY COLUMN `gender` ENUM('male','female') DEFAULT NULL;
