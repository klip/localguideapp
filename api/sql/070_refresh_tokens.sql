-- Refresh tokens (docs/auth-cms-plan.md, phase 1): the long-lived half of a
-- sign-in. The browser holds the token in an HttpOnly cookie; this table holds
-- only its SHA-256. Each one is single-use — `refresh` marks it used and
-- issues a child in the same family (`sid`, one per sign-in on a device), so
-- a token used twice (copied) can be told apart from the real one and the
-- whole family revoked (`Tokens::rotate()`).
--
--   expires_at           30 days after this token was issued (sliding: each
--                        refresh issues a token with a fresh 30 days)
--   absolute_expires_at  90 days after the sign-in, carried down the family
--   device_id            SHA-256 of the browser's device cookie
--
-- Re-runnable.

SET NAMES utf8mb4;

CREATE TABLE IF NOT EXISTS `refresh_tokens` (
  `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `user_id` INT UNSIGNED NOT NULL,
  `sid` CHAR(32) NOT NULL,
  `device_id` CHAR(64) NULL,
  `token_hash` CHAR(64) NOT NULL,
  `parent_id` INT UNSIGNED NULL,
  `issued_at` DATETIME NOT NULL,
  `expires_at` DATETIME NOT NULL,
  `absolute_expires_at` DATETIME NOT NULL,
  `used_at` DATETIME NULL,
  `revoked_at` DATETIME NULL,
  `revoke_reason` VARCHAR(40) NULL,
  `user_agent` VARCHAR(255) NOT NULL DEFAULT '',
  PRIMARY KEY (`id`),
  UNIQUE KEY `refresh_tokens_hash` (`token_hash`),
  KEY `refresh_tokens_sid` (`sid`),
  KEY `refresh_tokens_user` (`user_id`, `revoked_at`),
  KEY `refresh_tokens_expiry` (`absolute_expires_at`),
  CONSTRAINT `fk_refresh_tokens_user` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
