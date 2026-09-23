-- A server-side session: `session_hash` is a random 32-byte (64 hex char)
-- token minted by `Session::create()` on register/login, stored client-side
-- in localStorage (see stores/auth.ts) and sent back with every
-- authenticated request. `Session::resolve()` is the actual gate: it looks
-- up the hash, checks `last_active_at` against a 5-minute inactivity
-- window, and — if still valid — bumps `last_active_at` to now (a sliding
-- expiry measured from the last request, not a fixed TTL from creation)
-- before returning the user id. This is what closes the "any
-- client-supplied userId is trusted outright" gap flagged throughout this
-- codebase's history — see `RequestProcessor::requireUserId()`.

CREATE TABLE IF NOT EXISTS `sessions` (
  `id`             INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `session_hash`   CHAR(64)     NOT NULL,
  `user_id`        INT UNSIGNED NOT NULL,
  `created_at`     DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `last_active_at` DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `sessions_hash` (`session_hash`),
  KEY `sessions_user_id` (`user_id`),
  CONSTRAINT `sessions_user_fk` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
