-- 046: roles live only in the `user_role` mapping table.
--
-- `users.role_id` (the account's role, read everywhere) and `user_role` (a
-- write-only log of the same grant, 003) held the same fact twice. Every
-- read now goes through `user_role` (`lib/Roles.php`), so the column goes.
--
-- Backfills any account missing its `user_role` row first (none were, as of
-- 2026-10-18: 11 accounts, each with exactly one matching row), then drops
-- the column with its index and foreign key. Not re-runnable (the column is
-- gone afterwards). `cms_users.role_id` is a different table and stays.

SET NAMES utf8mb4;

INSERT IGNORE INTO `user_role` (`user_id`, `role_id`)
SELECT `id`, `role_id` FROM `users`;

ALTER TABLE `users`
  DROP FOREIGN KEY `users_role_fk`,
  DROP INDEX `users_role_id`,
  DROP COLUMN `role_id`;
