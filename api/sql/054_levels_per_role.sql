-- 054: dual roles, phase 4 — levels per role, from paid trips only
-- (docs/dual-roles.md, "Levels from real money").
--
--   * `user_role.level`: the level last announced, per role (replaces
--     `users.level`, dropped). Starts at 0 — levels are recomputed from the
--     new rule, and nobody's announcement is owed (development data).
--   * Level bonus reasons carry the role (`guest_level_4`, `guide_level_4`):
--     `bonus_grants` is unique on (user, reason), and someone who is both can
--     reach level 4 in each role.
--
-- Not re-runnable (columns added/dropped).

SET NAMES utf8mb4;

ALTER TABLE `user_role`
  ADD COLUMN `level` TINYINT UNSIGNED NOT NULL DEFAULT 0;

ALTER TABLE `users`
  DROP COLUMN `level`;

UPDATE `bonus_grants`
SET `reason` = CONCAT(IF(`kind` = 'free_picks', 'guest_', 'guide_'), `reason`)
WHERE `reason` LIKE 'level\_%';
