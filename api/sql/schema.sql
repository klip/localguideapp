-- Schema for the `guideapp` database (see api/classes/db.php for connection
-- config). Run against the database:
--
--   docker exec -i lemp-mysql mysql -u localguideapp -p'…' guideapp < api/sql/schema.sql
--
-- The DROPs below rewrite `roles`/`users` from an older, incompatible shape
-- (id/role/user_id and id/username/email/phone respectively) left over from
-- before this schema existed. That rewrite only happened to be safe because
-- both tables were still empty (checked 2026-09-02) — do not rerun this
-- file once there's real data without turning it into a proper migration.

DROP TABLE IF EXISTS `creds`;
DROP TABLE IF EXISTS `cms_users`;
DROP TABLE IF EXISTS `users`;
DROP TABLE IF EXISTS `roles`;

CREATE TABLE `roles` (
  `id`   INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `name` VARCHAR(32)  NOT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `roles_name` (`name`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- guest/guide: app-side accounts (`users`, visitor/local guide).
-- admin/manager/viewer: CMS operator accounts (`cms_users`).
INSERT INTO `roles` (`name`) VALUES ('guest'), ('guide'), ('admin'), ('manager'), ('viewer');

CREATE TABLE `users` (
  `id`         INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `role_id`    INT UNSIGNED NOT NULL,
  `email`      VARCHAR(190) NOT NULL,
  `name`       VARCHAR(120) DEFAULT NULL,
  `created_at` DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `users_email` (`email`),
  KEY `users_role_id` (`role_id`),
  CONSTRAINT `users_role_fk` FOREIGN KEY (`role_id`) REFERENCES `roles` (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- One row per user. `user_id` isn't in the columns the original spec called
-- out (id/password/salt) but is required to link a credential row back to
-- its account; added here as the obvious missing piece.
CREATE TABLE `creds` (
  `id`       INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `user_id`  INT UNSIGNED NOT NULL,
  `password` VARCHAR(255) NOT NULL,
  `salt`     VARCHAR(64)  NOT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `creds_user_id` (`user_id`),
  CONSTRAINT `creds_user_fk` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Mirrors `users`, for the future CMS/admin layer (`admin/`). Kept as a
-- separate table — rather than reusing `users` with a CMS role — so app
-- accounts (visitors/guides) and CMS operator accounts stay in distinct
-- spaces, even though both reference the same `roles` lookup.
--
-- No `cms_creds` counterpart yet: only the table shape was asked for so
-- far. Add one mirroring `creds` (id/cms_user_id/password/salt) once the
-- CMS actually needs to authenticate.
CREATE TABLE `cms_users` (
  `id`         INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `role_id`    INT UNSIGNED NOT NULL,
  `email`      VARCHAR(190) NOT NULL,
  `name`       VARCHAR(120) DEFAULT NULL,
  `created_at` DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `cms_users_email` (`email`),
  KEY `cms_users_role_id` (`role_id`),
  CONSTRAINT `cms_users_role_fk` FOREIGN KEY (`role_id`) REFERENCES `roles` (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
