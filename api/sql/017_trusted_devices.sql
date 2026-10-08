-- "Remember this device" for two-factor login (2026-10-08).
--
--   docker exec -i lemp-mysql mysql -u localguideapp -p'…' guideapp < api/sql/017_trusted_devices.sql
--
-- IF NOT EXISTS-guarded, safe to re-run.
--
-- After a correct login code the user can tick "Don't ask on this device for
-- 30 days". The browser keeps a random token (only its SHA-256 is stored
-- here) and sends it with later logins; a valid, unexpired token for the
-- account whose password just matched skips the emailed code. The password
-- is always required. `expires_at` is fixed when the device is trusted — use
-- doesn't extend it. A password reset deletes all of an account's rows; the
-- profile page can too ("Forget all devices"). See `AccountSecurity.php`.
CREATE TABLE IF NOT EXISTS `trusted_devices` (
  `id`           INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `user_id`      INT UNSIGNED NOT NULL,
  `token_hash`   CHAR(64)     NOT NULL,
  `user_agent`   VARCHAR(255) NULL,
  `created_at`   DATETIME     NOT NULL,
  `expires_at`   DATETIME     NOT NULL,
  `last_used_at` DATETIME     NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `trusted_devices_token` (`token_hash`),
  KEY `trusted_devices_user` (`user_id`, `expires_at`),
  CONSTRAINT `trusted_devices_user_fk` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
