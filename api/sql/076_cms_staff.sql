-- The CMS's staff accounts (docs/auth-cms-plan.md, phase 6). Separate from
-- app accounts: `cms_users` (from schema.sql) gets a status and sign-in
-- bookkeeping, and these tables are the staff's own versions of the app's
-- `creds`, `auth_challenges` and `refresh_tokens`, plus the audit log.
--
--   roles 'viewer' → 'editor'        the CMS roles are admin, manager, editor
--   cms_users.disabled_at            a disabled member can't sign in
--   cms_creds                        password (salt + pepper + bcrypt, as
--                                    `creds`), or a one-time set-password
--                                    link (invite / reset) when there's none
--   cms_challenges                   the emailed sign-in code — every staff
--                                    sign-in needs one (mandatory 2FA)
--   cms_refresh_tokens               staff sign-ins: 12 hours, no remember-me
--   staff_audit                      every change made in the CMS: who, what,
--                                    before and after
--
-- Not re-runnable (ADD COLUMN).

SET NAMES utf8mb4;

UPDATE `roles` SET `name` = 'editor' WHERE `name` = 'viewer';

ALTER TABLE `cms_users`
  ADD COLUMN `disabled_at` DATETIME NULL AFTER `name`,
  ADD COLUMN `last_sign_in_at` DATETIME NULL AFTER `disabled_at`,
  ADD COLUMN `invited_by` INT UNSIGNED NULL AFTER `last_sign_in_at`;

CREATE TABLE IF NOT EXISTS `cms_creds` (
  `staff_id` INT UNSIGNED NOT NULL,
  `password` VARCHAR(255) NULL,
  `salt` VARCHAR(64) NULL,
  `setup_token_hash` CHAR(64) NULL,
  `setup_expires_at` DATETIME NULL,
  PRIMARY KEY (`staff_id`),
  UNIQUE KEY `cms_creds_setup` (`setup_token_hash`),
  CONSTRAINT `fk_cms_creds_staff` FOREIGN KEY (`staff_id`) REFERENCES `cms_users` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `cms_challenges` (
  `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `staff_id` INT UNSIGNED NOT NULL,
  `token_hash` CHAR(64) NOT NULL,
  `code_hash` VARCHAR(255) NOT NULL,
  `attempts` TINYINT UNSIGNED NOT NULL DEFAULT 0,
  `expires_at` DATETIME NOT NULL,
  `used_at` DATETIME NULL,
  `created_at` DATETIME NOT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `cms_challenges_token` (`token_hash`),
  KEY `cms_challenges_staff` (`staff_id`, `created_at`),
  CONSTRAINT `fk_cms_challenges_staff` FOREIGN KEY (`staff_id`) REFERENCES `cms_users` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `cms_refresh_tokens` (
  `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `staff_id` INT UNSIGNED NOT NULL,
  `sid` CHAR(32) NOT NULL,
  `token_hash` CHAR(64) NOT NULL,
  `parent_id` INT UNSIGNED NULL,
  `issued_at` DATETIME NOT NULL,
  `expires_at` DATETIME NOT NULL,
  `used_at` DATETIME NULL,
  `revoked_at` DATETIME NULL,
  `revoke_reason` VARCHAR(40) NULL,
  `ip` VARCHAR(45) NULL,
  `user_agent` VARCHAR(255) NOT NULL DEFAULT '',
  PRIMARY KEY (`id`),
  UNIQUE KEY `cms_refresh_tokens_hash` (`token_hash`),
  KEY `cms_refresh_tokens_sid` (`sid`),
  KEY `cms_refresh_tokens_staff` (`staff_id`, `revoked_at`),
  CONSTRAINT `fk_cms_refresh_tokens_staff` FOREIGN KEY (`staff_id`) REFERENCES `cms_users` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `staff_audit` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  -- No foreign key: the record outlives a removed staff member.
  `staff_id` INT UNSIGNED NULL,
  `staff_email` VARCHAR(190) NOT NULL DEFAULT '',
  `action` VARCHAR(60) NOT NULL,
  `target_type` VARCHAR(40) NOT NULL DEFAULT '',
  `target_id` VARCHAR(190) NOT NULL DEFAULT '',
  `before` JSON NULL,
  `after` JSON NULL,
  `ip` VARCHAR(45) NULL,
  `created_at` DATETIME NOT NULL,
  PRIMARY KEY (`id`),
  KEY `staff_audit_staff` (`staff_id`, `created_at`),
  KEY `staff_audit_target` (`target_type`, `target_id`),
  KEY `staff_audit_created` (`created_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
