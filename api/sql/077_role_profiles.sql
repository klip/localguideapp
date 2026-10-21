-- `user_role` back to what it is — which roles a user holds — and what a
-- user shows *in* each role into its own table.
--
-- role_profiles (user_id, role) — one row per user and role, created when
--   first written (`Profiles::setRoleText()`, `Levels::sync()`):
--     headline, bio  what they show as a traveller / as a guide (052 put them
--                    on `user_role`; one account can have both, so they
--                    can't be one `user_profiles` row)
--     level          the level last announced in that role (054)
--
-- Copies every existing value, then drops the columns from `user_role`.
-- Not re-runnable (DROP COLUMN).

SET NAMES utf8mb4;

CREATE TABLE IF NOT EXISTS `role_profiles` (
  `user_id` INT UNSIGNED NOT NULL,
  `role` ENUM('guest', 'guide') NOT NULL,
  `headline` VARCHAR(160) NULL,
  `bio` TEXT NULL,
  `level` TINYINT UNSIGNED NOT NULL DEFAULT 0,
  `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`user_id`, `role`),
  CONSTRAINT `fk_role_profiles_user` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT INTO `role_profiles` (`user_id`, `role`, `headline`, `bio`, `level`)
SELECT ur.`user_id`, r.`name`, ur.`headline`, ur.`bio`, ur.`level`
FROM `user_role` ur
JOIN `roles` r ON r.`id` = ur.`role_id`
WHERE r.`name` IN ('guest', 'guide')
  AND (ur.`headline` IS NOT NULL OR ur.`bio` IS NOT NULL OR ur.`level` > 0)
ON DUPLICATE KEY UPDATE `headline` = VALUES(`headline`), `bio` = VALUES(`bio`), `level` = VALUES(`level`);

ALTER TABLE `user_role`
  DROP COLUMN `headline`,
  DROP COLUMN `bio`,
  DROP COLUMN `level`;
