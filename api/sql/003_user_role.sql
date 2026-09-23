-- Additive migration (see 002's header for why this style, not schema.sql's).
--
--   docker exec -i lemp-mysql mysql -u localguideapp -p'…' guideapp < api/sql/003_user_role.sql
--
-- A per-registration role-grant log, separate from `users.role_id` (the
-- account's current/primary role, unchanged and still what `getRoleName()`
-- reads). `Users::register()` writes one row here per registration in
-- addition to setting `users.role_id` — e.g. the "I'm a guide" flow. Unique
-- on (user_id, role_id): re-registering the same role for the same user is
-- a no-op, not a duplicate row.
CREATE TABLE IF NOT EXISTS `user_role` (
  `id`      INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `user_id` INT UNSIGNED NOT NULL,
  `role_id` INT UNSIGNED NOT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `user_role_pair` (`user_id`, `role_id`),
  KEY `user_role_role_id` (`role_id`),
  CONSTRAINT `user_role_user_fk` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE,
  CONSTRAINT `user_role_role_fk` FOREIGN KEY (`role_id`) REFERENCES `roles` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
