-- 056: dual roles, phase 5 — which role a notification is about
-- (`guest`/`guide`, the recipient's side), so opening it switches the app into
-- that mode (`?as=` on its link). NULL for older rows. Not re-runnable.

SET NAMES utf8mb4;

ALTER TABLE `notifications`
  ADD COLUMN `role` ENUM('guest', 'guide') NULL DEFAULT NULL AFTER `about_user_id`;
