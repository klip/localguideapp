-- 052: dual roles, phase 3 — what differs per role (docs/dual-roles.md).
--
--   * Headline and description move to `user_role` (one per role held),
--     copied from `user_profiles` onto the user's one existing role.
--   * `attribute_categories.scope`: `shared` (one set for the person —
--     languages), `per_role` (a set per role — interests/specialities, and
--     any category users create), `guest` / `guide` (that role only).
--     Picks and spans get a `role` column: `any` for a shared category, else
--     the role they belong to — backfilled with the user's first role.
--   * `reviews.subject_role`: which side the person reviewed was on, from
--     the review's booking — ratings are kept per role.
--
-- Not re-runnable (columns added/dropped).

SET NAMES utf8mb4;

-- Headline and description per role.
ALTER TABLE `user_role`
  ADD COLUMN `headline` VARCHAR(160) NULL DEFAULT NULL,
  ADD COLUMN `bio` TEXT NULL DEFAULT NULL;

UPDATE `user_role` ur
JOIN `user_profiles` p ON p.`user_id` = ur.`user_id`
SET ur.`headline` = p.`headline`, ur.`bio` = p.`bio`;

ALTER TABLE `user_profiles`
  DROP COLUMN `headline`,
  DROP COLUMN `bio`;

-- Category scopes.
ALTER TABLE `attribute_categories`
  ADD COLUMN `scope` ENUM('shared', 'per_role', 'guest', 'guide') NOT NULL DEFAULT 'per_role' AFTER `kind`;

UPDATE `attribute_categories` SET `scope` = 'shared' WHERE `key` = 'languages';
UPDATE `attribute_categories` SET `scope` = 'guest' WHERE `key` = 'food_allergies';
UPDATE `attribute_categories` SET `scope` = 'guide' WHERE `key` = 'hour_of_day';

-- Picks per role.
ALTER TABLE `user_attribute_values`
  ADD COLUMN `role` ENUM('any', 'guest', 'guide') NOT NULL DEFAULT 'any',
  DROP PRIMARY KEY,
  ADD PRIMARY KEY (`user_id`, `option_id`, `role`);

UPDATE `user_attribute_values` v
JOIN `attribute_options` o ON o.`id` = v.`option_id`
JOIN `attribute_categories` c ON c.`id` = o.`category_id`
SET v.`role` = CASE c.`scope`
    WHEN 'shared' THEN 'any'
    WHEN 'guest' THEN 'guest'
    WHEN 'guide' THEN 'guide'
    ELSE COALESCE((
      SELECT r.`name` FROM `user_role` ur JOIN `roles` r ON r.`id` = ur.`role_id`
      WHERE ur.`user_id` = v.`user_id` AND r.`name` IN ('guest', 'guide') ORDER BY ur.`id` LIMIT 1
    ), 'any')
  END;

-- Spans per role.
ALTER TABLE `user_attribute_ranges`
  ADD COLUMN `role` ENUM('any', 'guest', 'guide') NOT NULL DEFAULT 'any' AFTER `category_id`,
  DROP INDEX `user_attribute_ranges_unique`,
  ADD UNIQUE KEY `user_attribute_ranges_unique` (`user_id`, `category_id`, `role`, `range_start`, `range_end`);

UPDATE `user_attribute_ranges` x
JOIN `attribute_categories` c ON c.`id` = x.`category_id`
SET x.`role` = CASE c.`scope`
    WHEN 'shared' THEN 'any'
    WHEN 'guest' THEN 'guest'
    WHEN 'guide' THEN 'guide'
    ELSE COALESCE((
      SELECT r.`name` FROM `user_role` ur JOIN `roles` r ON r.`id` = ur.`role_id`
      WHERE ur.`user_id` = x.`user_id` AND r.`name` IN ('guest', 'guide') ORDER BY ur.`id` LIMIT 1
    ), 'any')
  END;

-- Reviews per role.
ALTER TABLE `reviews`
  ADD COLUMN `subject_role` ENUM('guest', 'guide') NOT NULL DEFAULT 'guide' AFTER `subject_id`,
  ADD KEY `reviews_subject_role_created` (`subject_id`, `subject_role`, `created_at`);

UPDATE `reviews` r
JOIN `bookings` b ON b.`id` = r.`booking_id`
SET r.`subject_role` = IF(r.`subject_id` = b.`guide_id`, 'guide', 'guest');
