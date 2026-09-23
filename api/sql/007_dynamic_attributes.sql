-- Generalizes the fixed `languages`/`activities` lookup+junction tables from
-- 005_profiles.sql into a single "list of lists" taxonomy — categories
-- (Languages, Interests, Food allergies, ...) each holding their own set of
-- options, cross-referenced by user id — so a new category (or option) can
-- be added with an INSERT, not a schema change or a new pair of tables.
--
-- Safe to replace `languages`/`user_languages`/`activities`/`user_activities`
-- outright rather than migrate them: confirmed 0 rows in all three junction/
-- lookup tables on the live DB (checked 2026-09-05) — no editing UI ever
-- existed to populate them (see 005_profiles.sql's own note). `Users::
-- searchByRole()`'s `language`/`activities` filters and `Profiles::
-- getAttributesFor()` are updated in the same change to read the new
-- tables, keeping the exact same filter semantics and the same
-- `ProfileAccount.languages`/`.activities` JSON field names — nothing in
-- the discovery deck/filter UI needs to change.
--
-- `user_specialties` (freeform guide tags, no fixed option list) is
-- deliberately NOT folded into this — it stays its own table; see
-- Profiles.php.

DROP TABLE IF EXISTS `user_languages`;
DROP TABLE IF EXISTS `user_activities`;
DROP TABLE IF EXISTS `languages`;
DROP TABLE IF EXISTS `activities`;

CREATE TABLE IF NOT EXISTS `attribute_categories` (
  `id`    INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `key`   VARCHAR(40)  NOT NULL,
  `label` VARCHAR(80)  NOT NULL,
  -- Whether a user can pick more than one option in this category. Every
  -- category seeded here is multi-select; the column exists so a future
  -- single-select category (e.g. a "preferred pace" pick-one) doesn't need
  -- a schema change, just `multi = 0` and client-side radio-vs-checkbox
  -- rendering off of it.
  `multi` TINYINT(1)   NOT NULL DEFAULT 1,
  PRIMARY KEY (`id`),
  UNIQUE KEY `attribute_categories_key` (`key`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS `attribute_options` (
  `id`          INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `category_id` INT UNSIGNED NOT NULL,
  -- `value` is what's stored/filtered on (stable even if `label` is reworded
  -- later); `label` is what's shown. Mirrors the old `activities.slug`/
  -- display-label split from stores/filters.ts's ACTIVITY_OPTIONS.
  `value`       VARCHAR(80)  NOT NULL,
  `label`       VARCHAR(80)  NOT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `attribute_options_category_value` (`category_id`, `value`),
  CONSTRAINT `attribute_options_category_fk` FOREIGN KEY (`category_id`) REFERENCES `attribute_categories` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS `user_attribute_values` (
  `user_id`   INT UNSIGNED NOT NULL,
  `option_id` INT UNSIGNED NOT NULL,
  PRIMARY KEY (`user_id`, `option_id`),
  KEY `user_attribute_values_option_id` (`option_id`),
  CONSTRAINT `user_attribute_values_user_fk` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE,
  CONSTRAINT `user_attribute_values_option_fk` FOREIGN KEY (`option_id`) REFERENCES `attribute_options` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

INSERT IGNORE INTO `attribute_categories` (`key`, `label`, `multi`) VALUES
  ('languages', 'Languages', 1),
  ('interests', 'Interests', 1),
  ('food_allergies', 'Food allergies', 1);

-- `languages` values match stores/filters.ts's LANGUAGES (minus its 'Any'
-- placeholder, which is a filter-UI sentinel, not a real option).
INSERT IGNORE INTO `attribute_options` (`category_id`, `value`, `label`)
SELECT `id`, v.value, v.label FROM `attribute_categories`
CROSS JOIN (
  SELECT 'English' AS value, 'English' AS label
  UNION ALL SELECT 'Spanish', 'Spanish'
  UNION ALL SELECT 'Arabic', 'Arabic'
  UNION ALL SELECT 'German', 'German'
) v
WHERE `attribute_categories`.`key` = 'languages';

-- `interests` values/labels match stores/filters.ts's ACTIVITY_OPTIONS
-- (same slugs the old `activities.slug` column held).
INSERT IGNORE INTO `attribute_options` (`category_id`, `value`, `label`)
SELECT `id`, v.value, v.label FROM `attribute_categories`
CROSS JOIN (
  SELECT 'history' AS value, 'History' AS label
  UNION ALL SELECT 'food', 'Food & drink'
  UNION ALL SELECT 'nature', 'Nature'
  UNION ALL SELECT 'family', 'Family-friendly'
  UNION ALL SELECT 'photography', 'Photography'
  UNION ALL SELECT 'bars', 'Bars walk'
  UNION ALL SELECT 'bro', 'Bro'
) v
WHERE `attribute_categories`.`key` = 'interests';

-- New category, not filtered on anywhere (like `user_specialties`) — just
-- self-reported on the profile page. Seed values per the initial ask.
INSERT IGNORE INTO `attribute_options` (`category_id`, `value`, `label`)
SELECT `id`, v.value, v.label FROM `attribute_categories`
CROSS JOIN (
  SELECT 'gluten' AS value, 'Gluten' AS label
  UNION ALL SELECT 'peanuts', 'Peanuts'
) v
WHERE `attribute_categories`.`key` = 'food_allergies';
